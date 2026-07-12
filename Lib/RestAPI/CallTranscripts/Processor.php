<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;
use Phalcon\Di\Injectable;
use RuntimeException;
use Throwable;

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $result = new PBXApiResult();
        $action = (string)($request['action'] ?? '');
        $result->processor = __METHOD__;
        $result->function = $action;
        if ($action !== 'getRecord') {
            $result->messages['error'][] = "Unknown action - {$action} in " . __CLASS__;
            return $result;
        }

        try {
            $data = is_array($request['data'] ?? null) ? $request['data'] : [];
            $revision = isset($data['revision']) && (int)$data['revision'] > 0 ? (int)$data['revision'] : null;
            $result->success = true;
            $result->httpCode = 200;
            $result->data = (new CallTranscriptIntegrationService())->callTranscriptDetail((int)($data['id'] ?? 0), $revision);
        } catch (RuntimeException $exception) {
            $result->success = false;
            $result->httpCode = 404;
            $result->data = ['error' => 'call_transcript_not_found'];
            $result->messages['error'][] = $exception->getMessage();
        } catch (Throwable) {
            $result->success = false;
            $result->httpCode = 500;
            $result->data = ['error' => 'internal_error'];
            $result->messages['error'][] = 'Internal server error';
        }

        return $result;
    }
}
