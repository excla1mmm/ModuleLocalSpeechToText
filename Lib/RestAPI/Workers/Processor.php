<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\Actions\RegisterAction;
use Phalcon\Di\Injectable;

enum WorkerAction: string
{
    case REGISTER = 'register';
}

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = __METHOD__;

        $actionString = (string)($request['action'] ?? '');
        $action = WorkerAction::tryFrom($actionString);
        if ($action === null) {
            $result->messages['error'][] = "Unknown action - $actionString in " . __CLASS__;
            $result->function = $actionString;
            return $result;
        }

        $data = self::requestData($request);
        $result = match ($action) {
            WorkerAction::REGISTER => RegisterAction::main($data),
        };

        $result->processor = __METHOD__;
        $result->function = $actionString;

        return $result;
    }

    private static function requestData(array $request): array
    {
        $data = $request['data'] ?? [];
        $data = is_array($data) ? $data : [];

        if (isset($request['sessionContext']['remote_addr']) && is_scalar($request['sessionContext']['remote_addr'])) {
            $data['_remote_addr'] = (string)$request['sessionContext']['remote_addr'];
        }

        return $data;
    }
}
