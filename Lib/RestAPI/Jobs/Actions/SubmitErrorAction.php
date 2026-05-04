<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\Actions;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

class SubmitErrorAction
{
    public static function main(array $data): PBXApiResult
    {
        return LocalSpeechToTextMain::submitError($data);
    }
}
