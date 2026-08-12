<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;

final class LegacyRouteRejector
{
    public static function worker(): PBXApiResult
    {
        return WorkerApiContract::upgradeRequired();
    }

    public static function removed(string $replacementPath): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = 410;
        $result->data = [
            'error_code' => 'api_route_removed',
            'replacement_path' => $replacementPath,
        ];
        $result->messages['error'][] = "This API route was removed; use {$replacementPath}";

        return $result;
    }
}
