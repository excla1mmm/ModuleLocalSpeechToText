<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryLeases;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\SummaryRequestDispatcher;
use Phalcon\Di\Injectable;

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $action = (string)($request['action'] ?? '');
        $operation = match ($action) {
            'create' => SummaryRequestDispatcher::CLAIM,
            'patch' => SummaryRequestDispatcher::HEARTBEAT,
            'delete' => SummaryRequestDispatcher::RELEASE,
            default => null,
        };
        if ($operation === null) {
            $result = new PBXApiResult();
            $result->success = false;
            $result->httpCode = 403;
            $result->data = ['error_code' => 'worker_action_forbidden'];

            return $result;
        }

        return SummaryRequestDispatcher::dispatch($request, $operation, self::class . '::callBack', $action);
    }
}
