<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers\Actions;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

class RegisterAction
{
    public static function main(array $data): PBXApiResult
    {
        return LocalSpeechToTextMain::registerWorker($data);
    }
}
