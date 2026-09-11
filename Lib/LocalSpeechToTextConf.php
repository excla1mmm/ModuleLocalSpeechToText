<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use MikoPBX\Common\Providers\PBXConfModulesProvider;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadRestAPIWorkerAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleLocalSpeechToText\App\Providers\AssetProvider;
use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Phalcon\Assets\Manager;
use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\View;
use Throwable;

class LocalSpeechToTextConf extends ConfigClass
{
    private const int DRAIN_TIMEOUT_MILLISECONDS = 30000;

    private ?ModuleLifecycleGate $lifecycleGate = null;

    public function onAfterAssetsPrepared(Manager $assets, Dispatcher $dispatcher): void
    {
        if (
            $dispatcher->getControllerName() === 'CallDetailRecords'
            && $dispatcher->getActionName() === 'index'
        ) {
            AssetProvider::addCdrAssets($assets);
        }
    }

    public function onVoltBlockCompile(string $controller, string $blockName, View $view): string
    {
        if ($controller !== 'CallDetailRecords' || $blockName !== 'Footer') {
            return '';
        }

        return 'Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/cdrTranscriptModal';
    }

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
        $this->scheduleRestApiReload();
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
        $this->scheduleRestApiReload();
    }

    private function scheduleRestApiReload(): void
    {
        try {
            WorkerModelsEvents::invokeAction(ReloadRestAPIWorkerAction::class);
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::event(
                'system',
                'rest_api_reload_schedule_failed',
                'REST API worker reload could not be scheduled',
                LOG_ERR,
                [
                    'operation' => 'schedule_rest_api_reload',
                    'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                ]
            );
            throw $exception;
        }
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
