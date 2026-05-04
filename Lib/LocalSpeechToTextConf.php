<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use MikoPBX\Common\Providers\PBXConfModulesProvider;
use MikoPBX\Core\System\Processes;
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

    public function onAfterModuleEnable(): void
    {
        PBXConfModulesProvider::recreateModulesProvider();
        Processes::restartAllWorkers(true);
    }
}
