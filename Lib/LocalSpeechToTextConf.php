<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use MikoPBX\Common\Providers\PBXConfModulesProvider;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;

class LocalSpeechToTextConf extends ConfigClass
{
    private const int DRAIN_TIMEOUT_MILLISECONDS = 30000;

    private ?ModuleLifecycleGate $lifecycleGate = null;

    public function getModuleWorkers(): array
    {
        return [
            [
                'type' => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => WorkerLocalSpeechToTextJobs::class,
            ],
        ];
    }

    public function onAfterModuleEnable(): void
    {
        $this->gate(bootstrap: true)->completeEnable();
        PBXConfModulesProvider::recreateModulesProvider();
        Processes::restartAllWorkers(true);
    }

    public function onBeforeModuleDisable(): bool
    {
        if ($this->gate()->beginDrain(self::DRAIN_TIMEOUT_MILLISECONDS)) {
            return true;
        }

        LocalSpeechToTextLogger::write(
            'setup',
            'Module disable aborted because STT database writers did not drain before the deadline',
            LOG_ERR
        );
        return false;
    }

    public function onAfterModuleDisable(): void
    {
        $this->gate()->completeDisable();
    }

    private function gate(bool $bootstrap = false): ModuleLifecycleGate
    {
        if ($this->lifecycleGate === null) {
            $this->lifecycleGate = new ModuleLifecycleGate(bootstrap: $bootstrap);
        }

        return $this->lifecycleGate;
    }
}
