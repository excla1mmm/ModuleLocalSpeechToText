<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use MikoPBX\Common\Providers\PBXConfModulesProvider;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Throwable;

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
        try {
            $connection = (new STTJob())->getWriteConnection();
            $pragmas = new ModuleDatabasePragmas();
            $pragmas->enableWalAfterVerifiedSetup(
                $connection,
                dirname(__DIR__) . '/db/module.db'
            );
            $pragmas->checkpoint($connection);
        } catch (Throwable $exception) {
            self::logDatabaseFailure('enable_database_runtime', $exception, LOG_WARNING);
        }

        $this->gate(bootstrap: true)->completeEnable();
        PBXConfModulesProvider::recreateModulesProvider();
        Processes::restartAllWorkers(true);
    }

    public function onBeforeModuleDisable(): bool
    {
        if ($this->gate()->beginDrain(self::DRAIN_TIMEOUT_MILLISECONDS)) {
            try {
                (new ModuleDatabasePragmas())->checkpoint((new STTJob())->getWriteConnection());
            } catch (Throwable $exception) {
                self::logDatabaseFailure('disable_database_checkpoint', $exception, LOG_WARNING);
            }
            return true;
        }

        LocalSpeechToTextLogger::event(
            'system',
            'operation_failed',
            'Module disable aborted because database writers remained active',
            LOG_ERR,
            [
                'operation' => 'disable_writer_drain',
                'error_code' => 'writer_drain_timeout',
            ]
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

    private static function logDatabaseFailure(string $operation, Throwable $exception, int $level): void
    {
        LocalSpeechToTextLogger::event(
            'storage',
            'database_operation_failed',
            'STT database operation failed',
            $level,
            [
                'operation' => $operation,
                'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
            ]
        );
    }
}
