<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Phalcon\Di\Injectable;
use Throwable;

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $result = new PBXApiResult();
        $action = (string)($request['action'] ?? '');
        $result->processor = __METHOD__;
        $result->function = $action;
        if ($action !== 'getList') {
            $result->httpCode = 400;
            $result->messages['error'][] = "Unknown action - {$action} in " . __CLASS__;
            return $result;
        }

        try {
            $data = is_array($request['data'] ?? null) ? $request['data'] : [];
            $result->success = true;
            $result->httpCode = 200;
            $result->data = (new CallTranscriptIntegrationService())->callTranscriptEvents($data);
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::event(
                'api',
                'operation_failed',
                'STT call transcript event API operation failed',
                LOG_ERR,
                [
                    'operation' => $action,
                    'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                ]
            );
            $result->success = false;
            $result->httpCode = 500;
            $result->data = ['error' => 'internal_error'];
            $result->messages['error'][] = 'Internal server error';
        }

        return $result;
    }
}
