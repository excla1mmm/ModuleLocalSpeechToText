<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptIntegrationService;
use Phalcon\Di\Injectable;
use RuntimeException;
use Throwable;

enum TranscriptAction: string
{
    case GET_LIST = 'getList';
    case GET_RECORD = 'getRecord';
}

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        $actionString = (string)($request['action'] ?? '');
        $action = TranscriptAction::tryFrom($actionString);
        if ($action === null) {
            $result->messages['error'][] = "Unknown action - $actionString in " . __CLASS__;
            $result->function = $actionString;
            return $result;
        }

        $data = self::requestData($request);
        $service = new TranscriptIntegrationService();

        try {
            $result = match ($action) {
                TranscriptAction::GET_LIST => self::success($service->listCompletedTranscripts($data)),
                TranscriptAction::GET_RECORD => self::success($service->transcriptDetail((int)($data['id'] ?? 0))),
            };
        } catch (RuntimeException $exception) {
            $result = self::error('transcript_not_found', $exception->getMessage(), 404);
        } catch (Throwable) {
            $result = self::error('internal_error', 'Internal server error', 500);
        }

        $result->processor = __METHOD__;
        $result->function = $actionString;

        return $result;
    }

    private static function success(array $data): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = true;
        $result->data = $data;
        $result->httpCode = 200;

        return $result;
    }

    private static function error(string $code, string $message, int $httpCode): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->data = ['error' => $code];
        $result->messages['error'][] = $message;
        $result->httpCode = $httpCode;

        return $result;
    }

    private static function requestData(array $request): array
    {
        $data = $request['data'] ?? [];
        $data = is_array($data) ? $data : [];
        unset($data['_remote_addr'], $data['_auth_type'], $data['_api_key_id']);

        if (isset($request['sessionContext']['remote_addr']) && is_scalar($request['sessionContext']['remote_addr'])) {
            $data['_remote_addr'] = (string)$request['sessionContext']['remote_addr'];
        }
        if (isset($request['sessionContext']['auth_type']) && is_scalar($request['sessionContext']['auth_type'])) {
            $data['_auth_type'] = (string)$request['sessionContext']['auth_type'];
        }
        if (isset($request['sessionContext']['token_id']) && is_scalar($request['sessionContext']['token_id'])) {
            $data['_api_key_id'] = (int)$request['sessionContext']['token_id'];
        }

        return $data;
    }
}
