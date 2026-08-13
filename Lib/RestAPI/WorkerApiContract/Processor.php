<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerApiContract;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Phalcon\Di\Injectable;

final class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $action = (string)($request['action'] ?? '');
        if ($action === 'getList') {
            $result = WorkerApiContract::contractResult();
        } else {
            $result = new PBXApiResult();
            $result->success = false;
            $result->httpCode = 404;
            $result->messages['error'][] = "Unknown action - {$action} in " . __CLASS__;
        }

        $result->processor = self::class . '::callBack';
        $result->function = $action;

        return $result;
    }
}
