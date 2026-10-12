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
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
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
        $controller = strtolower((string)preg_replace(
            '/[^A-Za-z0-9]+/',
            '',
            (string)$dispatcher->getControllerName()
        ));
        if (
            $controller !== 'calldetailrecords'
            || strtolower((string)$dispatcher->getActionName()) !== 'index'
        ) {
            return;
        }

        AssetProvider::addCdrAssets($assets);
    }

    public function onVoltBlockCompile(string $controller, string $blockName, View $view): string
    {
        $controller = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $controller));
        if ($controller !== 'calldetailrecords' || strtolower($blockName) !== 'footer') {
            return '';
        }

        return 'Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/cdrTranscriptModal';
    }

    /**
     * Worker submissions carry free text: call transcripts and error messages.
     * Real conversations sometimes contain word sequences that the MikoPBX WAF
     * body scan mistakes for SQL injection, and nginx then rejects the whole
     * result with 403 before it reaches this module; the call never gets a
     * transcript. These endpoints are authenticated with the restricted worker
     * key and validate their JSON strictly, so the body scan is switched off for
     * them only. Picked up by WafRegistry on MikoPBX versions that have a WAF.
     *
     * @return array<string, array{scopes: list<string>, prefix: bool}>
     */
    public function getWafExemptions(): array
    {
        return [
            '/pbxcore/api/v3/module-local-speech-to-text/job-results' => ['scopes' => ['body-scan'], 'prefix' => true],
            '/pbxcore/api/v3/module-local-speech-to-text/job-failures' => ['scopes' => ['body-scan'], 'prefix' => true],
        ];
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

        $diagnostics = $this->drainFailureDiagnostics();

        LocalSpeechToTextLogger::event(
            'system',
            'operation_failed',
            'Module disable aborted because database writers remained active',
            LOG_ERR,
            [
                'operation' => 'disable_writer_drain',
                'error_code' => 'writer_drain_timeout',
                'pending_jobs' => $diagnostics['pending'],
                'processing_jobs' => $diagnostics['processing'],
                'online_workers' => $diagnostics['onlineWorkers'],
            ]
        );

        $this->messages[] = $this->describeDrainFailure($diagnostics);
        return false;
    }

    /**
     * @return array{pending: ?int, processing: ?int, onlineWorkers: ?int}
     */
    private function drainFailureDiagnostics(): array
    {
        try {
            return [
                'pending' => (int)STTJob::count([
                    'conditions' => 'status = :status:',
                    'bind' => ['status' => STTJob::STATUS_PENDING],
                ]),
                'processing' => (int)STTJob::count([
                    'conditions' => 'status = :status:',
                    'bind' => ['status' => STTJob::STATUS_PROCESSING],
                ]),
                'onlineWorkers' => (int)STTWorker::count([
                    'conditions' => 'status = :status:',
                    'bind' => ['status' => STTWorker::STATUS_ONLINE],
                ]),
            ];
        } catch (Throwable $exception) {
            self::logDatabaseFailure('disable_drain_diagnostics', $exception, LOG_WARNING);
            return ['pending' => null, 'processing' => null, 'onlineWorkers' => null];
        }
    }

    /**
     * @param array{pending: ?int, processing: ?int, onlineWorkers: ?int} $diagnostics
     */
    private function describeDrainFailure(array $diagnostics): string
    {
        if ($diagnostics['pending'] === null) {
            return 'Module disable aborted: database writers remained active and the current '
                . 'queue state could not be read. Check the module logs and try again.';
        }

        return sprintf(
            'Module disable aborted: database writers remained active (%d job(s) pending, '
                . '%d job(s) processing, %d worker(s) online). Wait for the queue to clear '
                . 'and try again.',
            $diagnostics['pending'],
            $diagnostics['processing'],
            $diagnostics['onlineWorkers']
        );
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
