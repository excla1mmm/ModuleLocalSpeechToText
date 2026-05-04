<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;

class LocalSpeechToTextConf extends ConfigClass
{
    public function getModuleWorkers(): array
    {
        return [
            [
                'type' => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => WorkerCreateTranscriptionJobs::class,
            ],
        ];
    }
}
