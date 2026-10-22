<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryResults;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\SummaryRequestDispatcher;
use Phalcon\Di\Injectable;

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        return SummaryRequestDispatcher::dispatch($request, SummaryRequestDispatcher::RESULT, self::class . '::callBack', 'update');
    }
}
