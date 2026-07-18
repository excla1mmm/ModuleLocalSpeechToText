<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\Actions\GetProcessingSettingsAction;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\Actions\RegisterAction;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Phalcon\Di\Injectable;

enum WorkerAction: string
{
    case REGISTER = 'register';
    case GET_PROCESSING_SETTINGS = 'getProcessingSettings';
}

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = self::class . '::callBack';

        $actionString = (string)($request['action'] ?? '');
        $action = WorkerAction::tryFrom($actionString);
        if ($action === null) {
            $result->messages['error'][] = "Unknown action - $actionString in " . __CLASS__;
            $result->function = $actionString;
            return $result;
        }

        $gate = new ModuleLifecycleGate();
        $writeLease = $gate->acquireWriteLease();
        if ($writeLease === null) {
            $result->success = false;
            $result->httpCode = 503;
            $result->function = $actionString;
            $result->messages['error'][] = 'ModuleLocalSpeechToText is temporarily unavailable during maintenance';
            $result->data['retry_after'] = 5;
            return $result;
        }

        try {
            $data = self::requestData($request);
            $result = match ($action) {
                WorkerAction::REGISTER => RegisterAction::main($data),
                WorkerAction::GET_PROCESSING_SETTINGS => GetProcessingSettingsAction::main($data),
            };
        } finally {
            $gate->releaseWriteLease($writeLease);
        }

        $result->processor = __METHOD__;
        $result->function = $actionString;

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
