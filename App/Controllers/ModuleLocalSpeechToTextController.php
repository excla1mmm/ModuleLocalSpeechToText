<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\Common\Models\ApiKeys;
use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModelMarketplaceService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLogReader;
use Modules\ModuleLocalSpeechToText\Lib\Services\QueueViewService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptConversationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptListService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptUserJsonService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerViewService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Throwable;

class ModuleLocalSpeechToTextController extends LocalSpeechToTextBaseController
{
    private const string SESSION_GENERATED_API_KEY = 'ModuleLocalSpeechToTextGeneratedApiKey';
    public function indexAction(): void
    {
        $generatedApiKey = $this->generatedApiKeyFromSession();
        if ($generatedApiKey['apiKey'] !== '') {
            $this->session->remove(self::SESSION_GENERATED_API_KEY);
        }

        $this->prepareIndexView($generatedApiKey, $this->activeTabFromRequest($generatedApiKey['apiKey']));
    }

    public function saveAction(): void
    {
        try {
            $this->saveSettingsFromPost();
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
            $this->view->success = true;
            $this->view->reload = MenuProvider::DASHBOARD_PATH;
        } catch (Throwable $exception) {
            $this->flash->error($exception->getMessage());
            $this->view->success = false;
        }

        if ($this->request->isAjax() === false) {
            $this->response->redirect(MenuProvider::DASHBOARD_PATH);
        }
    }

    public function saveModelAction(): void
    {
        try {
            $modelId = trim((string)$this->request->getPost(
                STTSetting::SELECTED_MODEL,
                'string',
                STTSetting::selectedModelId()
            ));
            $normalizedModelId = STTSetting::normalizeModelId(substr($modelId, 0, 255));
            $this->assertModelLanguageCompatibility($normalizedModelId, STTSetting::selectedLanguageId());
            STTSetting::setValue(STTSetting::SELECTED_MODEL, $normalizedModelId);
            WorkerProcessingSettingsService::markAllWorkersChanged();
            LocalSpeechToTextLogger::event(
                'settings',
                'settings_changed',
                'STT module settings changed',
                LOG_NOTICE,
                ['changed_fields' => STTSetting::SELECTED_MODEL]
            );
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        } catch (Throwable $exception) {
            $this->logControllerFailure('save_model', $exception);
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=models');
    }

    public function retryErrorsAction(): void
    {
        try {
            $count = STTJob::requeueErrors();
            if ($count > 0) {
                LocalSpeechToTextLogger::event(
                    'queue',
                    'failed_jobs_requeued',
                    'Failed transcription jobs returned to the queue',
                    LOG_NOTICE,
                    ['requeued_count' => $count]
                );
            }
            $this->flash->{$count > 0 ? 'success' : 'warning'}(
                $count > 0
                    ? sprintf($this->translation->_('module_local_speech_to_text_RetryErrorsSuccess'), $count)
                    : $this->translation->_('module_local_speech_to_text_RetryErrorsEmpty')
            );
        } catch (Throwable $exception) {
            $this->logControllerFailure('retry_failed_jobs', $exception);
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=queue');
    }

    public function generateWorkerApiKeyAction(): void
    {
        try {
            $plainApiKey = ApiKeys::generateApiKey();
            $service = $this->workerApiKeyService();
            $service->beginCoreApiKeyTransaction();
            try {
                $apiKey = $service->createCoreWorkerApiKey($plainApiKey);
                try {
                    $service->bindWorkerApiKey((int)$apiKey->id);
                    $service->commitCoreApiKeyTransaction();
                } catch (Throwable $exception) {
                    // The Core transaction can still roll back. Remove any
                    // module binding before propagating the failure.
                    try {
                        $service->removeWorkerApiKeyBinding((int)$apiKey->id);
                    } catch (Throwable $cleanupException) {
                        $this->logControllerFailure('compensate_worker_api_key_binding', $cleanupException);
                    }
                    throw $exception;
                }
            } catch (Throwable $exception) {
                try {
                    $service->rollBackCoreApiKeyTransaction();
                } catch (Throwable $rollbackException) {
                    $this->logControllerFailure('rollback_worker_api_key_create', $rollbackException);
                }
                throw $exception;
            }

            // A newly-created token cannot have a stale validation-cache hit;
            // cache refresh is therefore best-effort after the atomic write.
            try {
                $service->clearCoreApiKeyCache();
            } catch (Throwable $cacheException) {
                $this->logControllerFailure('refresh_api_key_cache_after_create', $cacheException, LOG_WARNING);
            }
            $this->session->set(self::SESSION_GENERATED_API_KEY, [
                'apiKey' => $plainApiKey,
                'apiKeyId' => (int)$apiKey->id,
            ]);
            LocalSpeechToTextLogger::event(
                'settings',
                'worker_api_key_created',
                'Worker API key created',
                LOG_NOTICE,
                ['api_key_id' => (int)$apiKey->id]
            );
            $this->flash->success($this->translation->_('module_local_speech_to_text_WorkerApiKeyCreated'));
        } catch (Throwable $exception) {
            $this->logControllerFailure('create_worker_api_key', $exception);
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=workers');
    }

    public function deleteWorkerApiKeyAction(): void
    {
        try {
            $apiKeyId = (int)$this->request->getPost('api_key_id', 'int', 0);
            if (!STTSetting::isWorkerApiKeyIdAllowed($apiKeyId)) {
                $this->flash->warning($this->translation->_('module_local_speech_to_text_WorkerApiKeyNotFound'));
                $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=workers');
                return;
            }

            $service = $this->workerApiKeyService();
            $service->beginCoreApiKeyTransaction();
            try {
                $service->deleteCoreWorkerApiKey($apiKeyId);
                // Commit revocation first, then remove module authorization.
                // Cache invalidation is attempted even if module cleanup
                // fails, so a deleted token cannot retain both gates.
                $service->commitCoreApiKeyTransaction();
                try {
                    $service->unbindWorkersFromApiKey($apiKeyId);
                } finally {
                    try {
                        // Removing the allowed ID is the module-level
                        // authorization boundary and must be attempted even
                        // when a stale worker row cannot be updated.
                        $service->removeWorkerApiKeyBinding($apiKeyId);
                    } finally {
                        try {
                            $service->clearCoreApiKeyCache();
                        } catch (Throwable $cacheException) {
                            $this->logControllerFailure('refresh_api_key_cache_after_delete', $cacheException, LOG_WARNING);
                        }
                    }
                }
            } catch (Throwable $exception) {
                try {
                    $service->rollBackCoreApiKeyTransaction();
                } catch (Throwable $rollbackException) {
                    $this->logControllerFailure('rollback_worker_api_key_delete', $rollbackException);
                }
                throw $exception;
            }
            LocalSpeechToTextLogger::event(
                'settings',
                'worker_api_key_deleted',
                'Worker API key deleted',
                LOG_NOTICE,
                ['api_key_id' => $apiKeyId]
            );
            $this->flash->success($this->translation->_('module_local_speech_to_text_WorkerApiKeyDeleted'));
        } catch (Throwable $exception) {
            $this->logControllerFailure('delete_worker_api_key', $exception);
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=workers');
    }

    public function transcriptsAction(): void
    {
        $transcripts = $this->transcriptListService();
        $transcriptFilter = $transcripts->transcriptDateFilterFromRequest();

        $this->addModuleAssets();
        $this->view->dashboardPath = MenuProvider::DASHBOARD_PATH;
        $this->view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
        $this->view->transcriptRows = $transcripts->transcriptRows(100, $transcriptFilter);
        $this->view->transcriptFilter = $transcripts->transcriptFilterView($transcriptFilter);
        $this->view->transcriptFilterAction = MenuProvider::TRANSCRIPTS_PATH;
        $this->view->transcriptFilterKeepTab = false;
        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcripts');
    }

    public function transcriptAction(): void
    {
        $this->addModuleAssets();

        $resultId = (int)$this->request->getQuery('id', 'int', 0);
        $result = STTResult::findFirstById($resultId);
        if (!$result instanceof STTResult) {
            $this->flash->error($this->translation->_('module_local_speech_to_text_TranscriptNotFound'));
            $this->response->redirect(MenuProvider::TRANSCRIPTS_PATH);
            return;
        }

        $job = $result->STTJob;
        $job = $job instanceof STTJob ? $job : null;
        $recordings = $this->callRecordingService();
        $cdr = $job instanceof STTJob
            ? ($recordings->cdrRecordForJob($job) ?? $recordings->cdrRecordForResult($result))
            : $recordings->cdrRecordForResult($result);

        $this->view->transcriptsPath = MenuProvider::DASHBOARD_PATH . '?tab=transcripts';
        $this->view->result = $result;
        $this->view->conversation = $this->conversationService()->conversationFromTranscript((string)$result->transcript);
        $this->view->recording = $recordings->recordingCard($result, $job, $cdr);
        $this->view->transcriptJson = $this->transcriptUserJsonService()->transcriptJson([
            'id' => $job instanceof STTJob ? (string)$job->call_id : null,
            'started_at' => $cdr?->start,
            'ended_at' => $cdr?->endtime,
            'duration_seconds' => $result->duration === null ? null : (float)$result->duration,
            'source_number' => $cdr?->src_num,
            'destination_number' => $cdr?->dst_num,
            'language' => $result->language,
            'model' => $result->model_used,
        ], (string)$result->transcript);
        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcript');
    }

    public function recordingAction(): void
    {
        $this->view->disable();

        $recordings = $this->callRecordingService();
        $result = STTResult::findFirstById((int)$this->request->getQuery('id', 'int', 0));
        if (!$result instanceof STTResult) {
            $recordings->sendRecordingError(404, 'Recording not found');
            return;
        }

        $job = $result->STTJob;
        $job = $job instanceof STTJob ? $job : null;
        $cdr = $job instanceof STTJob
            ? ($recordings->cdrRecordForJob($job) ?? $recordings->cdrRecordForResult($result))
            : $recordings->cdrRecordForResult($result);
        $filePath = $recordings->recordingPathForPlayback($job, $cdr);
        if ($filePath === '') {
            $recordings->sendRecordingError(404, 'Recording not found');
            return;
        }

        $recordings->streamRecordingFile($filePath, (int)$this->request->getQuery('download', 'int', 0) === 1);
    }

    public function logsAction(): void
    {
        $period = (int)$this->request->getQuery('period', 'int', 3600);
        if (!in_array($period, [0, 3600, 10800, 43200, 86400], true)) {
            $period = 3600;
        }

        $payload = (new ModuleLogReader())->recentForPeriod($period);
        $this->view->success = true;
        $this->view->entries = $payload['entries'];
        $this->view->logText = $payload['logText'];
        $this->view->lineCount = $payload['lineCount'];
        $this->view->fileCount = $payload['fileCount'];
        $this->view->generatedAt = $payload['generatedAt'];
        $this->view->period = $payload['period'];
        $this->view->availableFrom = $payload['availableFrom'];
        $this->view->truncated = $payload['truncated'];
        $this->view->sourceStatus = $payload['sourceStatus'];
        $this->view->malformedCount = $payload['malformedCount'];
    }

    /**
     * @param array{apiKey: string, apiKeyId: int} $generatedApiKey
     */
    private function prepareIndexView(array $generatedApiKey, string $activeTab): void
    {
        $this->addModuleAssets();

        $queueView = $this->queueViewService();
        $transcripts = $this->transcriptListService();
        $transcriptFilter = $transcripts->transcriptDateFilterFromRequest();
        $delayedRecordings = $queueView->storedRecords(STTSetting::DELAYED_RECORDINGS);
        $skippedRecordings = $queueView->storedRecords(STTSetting::SKIPPED_RECORDINGS);
        $pendingCount = $this->countJobsByStatus(STTJob::STATUS_PENDING);
        $processingCount = $this->countJobsByStatus(STTJob::STATUS_PROCESSING);
        $doneTodayCount = (int)STTJob::count([
            'conditions' => 'status = :status: AND finished_at >= :since:',
            'bind' => ['status' => STTJob::STATUS_DONE, 'since' => strtotime('today')],
        ]);
        $errorCount = $this->countJobsByStatus(STTJob::STATUS_ERROR);

        $this->view->activeTab = $activeTab;
        $this->view->form = $this->buildSettingsForm();
        $this->view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
        $this->view->modelPresets = STTSetting::modelPresets();
        $this->view->selectedModel = STTSetting::selectedModelId();
        $this->view->selectedLanguage = STTSetting::selectedLanguageId();
        $this->view->modelMarketplaceRows = $this->modelMarketplaceService()->modelMarketplaceRows();
        $this->view->selectedVocabularyTermsCount = count(STTSetting::selectedVocabularyTerms());
        $this->view->pendingCount = $pendingCount;
        $this->view->processingCount = $processingCount;
        $this->view->doneTodayCount = $doneTodayCount;
        $this->view->errorCount = $errorCount;
        $this->view->delayedRecordingCount = count($delayedRecordings);
        $this->view->skippedRecordingCount = count($skippedRecordings);
        $this->view->queueSummaryRows = $queueView->queueSummaryRows(
            $pendingCount,
            $processingCount,
            $doneTodayCount,
            $errorCount,
            $delayedRecordings,
            $skippedRecordings
        );
        $this->view->queueFileRows = $queueView->queueFileRows($delayedRecordings, $skippedRecordings);
        (new WorkerViewService())->prepare($this->view);
        $this->view->workerApiKeyRows = $this->workerApiKeyService()->workerApiKeyRows();
        $this->view->generatedApiKey = $generatedApiKey['apiKey'];
        $this->view->transcriptRows = $transcripts->transcriptRows(100, $transcriptFilter);
        $this->view->transcriptFilter = $transcripts->transcriptFilterView($transcriptFilter);
        $this->view->transcriptFilterAction = MenuProvider::DASHBOARD_PATH;
        $this->view->transcriptFilterKeepTab = true;
        $this->view->logsDataPath = MenuProvider::LOGS_PATH;
        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/index');
    }

    /**
     * @return array{apiKey: string, apiKeyId: int}
     */
    private function generatedApiKeyFromSession(): array
    {
        $generatedApiKey = $this->session->get(self::SESSION_GENERATED_API_KEY, '');
        if (is_array($generatedApiKey)) {
            return [
                'apiKey' => (string)($generatedApiKey['apiKey'] ?? ''),
                'apiKeyId' => (int)($generatedApiKey['apiKeyId'] ?? 0),
            ];
        }

        return [
            'apiKey' => is_scalar($generatedApiKey) ? (string)$generatedApiKey : '',
            'apiKeyId' => 0,
        ];
    }

    private function activeTabFromRequest(string $generatedApiKey): string
    {
        if ($generatedApiKey !== '') {
            return 'workers';
        }

        $tab = (string)$this->request->getQuery('tab', 'string', 'settings');

        return in_array($tab, ['settings', 'models', 'queue', 'workers', 'transcripts', 'logs'], true) ? $tab : 'settings';
    }

    private function logControllerFailure(string $operation, Throwable $exception, int $level = LOG_ERR): void
    {
        LocalSpeechToTextLogger::event(
            'settings',
            'operation_failed',
            'STT administration operation failed',
            $level,
            [
                'operation' => $operation,
                'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
            ]
        );
    }

    private function conversationService(): TranscriptConversationService
    {
        return new TranscriptConversationService($this->translation);
    }

    private function transcriptUserJsonService(): TranscriptUserJsonService
    {
        return new TranscriptUserJsonService($this->translation);
    }

    private function callRecordingService(): CallRecordingService
    {
        return new CallRecordingService($this->translation, $this->url, $this->request, $this->response);
    }

    private function queueViewService(): QueueViewService
    {
        return new QueueViewService($this->translation, $this->callRecordingService());
    }

    private function transcriptListService(): TranscriptListService
    {
        return new TranscriptListService($this->translation, $this->request, $this->callRecordingService());
    }

    private function workerApiKeyService(): WorkerApiKeyService
    {
        return new WorkerApiKeyService();
    }

    private function modelMarketplaceService(): ModelMarketplaceService
    {
        return new ModelMarketplaceService($this->translation);
    }
}
