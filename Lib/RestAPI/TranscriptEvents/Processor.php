<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptIntegrationService;
use Phalcon\Di\Injectable;
use Throwable;

enum TranscriptEventAction: string
{
    case GET_LIST = 'getList';
}

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        $actionString = (string)($request['action'] ?? '');
        $action = TranscriptEventAction::tryFrom($actionString);
        if ($action === null) {
            $result->messages['error'][] = "Unknown action - $actionString in " . __CLASS__;
            $result->function = $actionString;
            return $result;
        }

        try {
            $service = new TranscriptIntegrationService();
            $result = self::success($service->completedTranscriptEvents(self::requestData($request)));
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
