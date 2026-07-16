<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\Common\Models\ApiKeys;
use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModelMarketplaceService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLogReader;
use Modules\ModuleLocalSpeechToText\Lib\Services\QueueViewService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptConversationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptListService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptTechnicalInfoService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
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
            STTSetting::setValue(STTSetting::SELECTED_MODEL, STTSetting::normalizeModelId(substr($modelId, 0, 255)));
            LocalSpeechToTextLogger::write(
                'web-ui',
                sprintf('Recognition model selected: %s', STTSetting::selectedModelId()),
                LOG_NOTICE
            );
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write('web-ui', 'Failed to save recognition model: ' . $exception->getMessage(), LOG_ERR);
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=models');
    }

    public function addCustomModelAction(): void
    {
        try {
            STTSetting::addCustomModel([
                'repo' => (string)$this->request->getPost('model_repo', 'string', ''),
                'model' => (string)$this->request->getPost('runtime_model', 'string', ''),
                'label' => (string)$this->request->getPost('model_title', 'string', ''),
                'description' => (string)$this->request->getPost('model_description', 'string', ''),
                'language' => (string)$this->request->getPost('model_language', 'string', ''),
                'license' => (string)$this->request->getPost('model_license', 'string', ''),
                'source_url' => (string)$this->request->getPost('source_url', 'string', ''),
                'tags' => (string)$this->request->getPost('model_tags', 'string', ''),
            ]);
            LocalSpeechToTextLogger::write(
                'web-ui',
                sprintf('Custom recognition model added: %s', (string)$this->request->getPost('model_repo', 'string', '')),
                LOG_NOTICE
            );
            $this->flash->success($this->translation->_('module_local_speech_to_text_CustomModelAdded'));
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write('web-ui', 'Failed to add custom recognition model: ' . $exception->getMessage(), LOG_ERR);
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=models');
    }

    public function deleteCustomModelAction(): void
    {
        try {
            $modelId = (string)$this->request->getPost(STTSetting::SELECTED_MODEL, 'string', '');
            STTSetting::removeCustomModel($modelId);
            LocalSpeechToTextLogger::write('web-ui', sprintf('Custom recognition model deleted: %s', $modelId), LOG_NOTICE);
            $this->flash->success($this->translation->_('module_local_speech_to_text_CustomModelDeleted'));
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write('web-ui', 'Failed to delete custom recognition model: ' . $exception->getMessage(), LOG_ERR);
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=models');
    }

    public function retryErrorsAction(): void
    {
        try {
            $count = STTJob::requeueErrors();
            LocalSpeechToTextLogger::write(
                'web-ui',
                sprintf('Retry failed recordings requested: %d job(s) returned to queue', $count),
                $count > 0 ? LOG_NOTICE : LOG_INFO
            );
            $this->flash->{$count > 0 ? 'success' : 'warning'}(
                $count > 0
                    ? sprintf($this->translation->_('module_local_speech_to_text_RetryErrorsSuccess'), $count)
                    : $this->translation->_('module_local_speech_to_text_RetryErrorsEmpty')
            );
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write('web-ui', 'Failed to retry failed recordings: ' . $exception->getMessage(), LOG_ERR);
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
                        LocalSpeechToTextLogger::write(
                            'web-ui',
                            'Failed to compensate worker API key binding: ' . $cleanupException->getMessage(),
                            LOG_ERR
                        );
                    }
                    throw $exception;
                }
            } catch (Throwable $exception) {
                try {
                    $service->rollBackCoreApiKeyTransaction();
                } catch (Throwable $rollbackException) {
                    LocalSpeechToTextLogger::write(
                        'web-ui',
                        'Failed to roll back worker API key transaction: ' . $rollbackException->getMessage(),
                        LOG_ERR
                    );
                }
                throw $exception;
            }

            // A newly-created token cannot have a stale validation-cache hit;
            // cache refresh is therefore best-effort after the atomic write.
            try {
                $service->clearCoreApiKeyCache();
            } catch (Throwable $cacheException) {
                LocalSpeechToTextLogger::write(
                    'web-ui',
                    'Failed to refresh API key cache after create: ' . $cacheException->getMessage(),
                    LOG_WARNING
                );
            }
            $this->session->set(self::SESSION_GENERATED_API_KEY, [
                'apiKey' => $plainApiKey,
                'apiKeyId' => (int)$apiKey->id,
            ]);
            LocalSpeechToTextLogger::write(
                'web-ui',
                sprintf('Worker API key created: id=%d', (int)$apiKey->id),
                LOG_NOTICE
            );
            $this->flash->success($this->translation->_('module_local_speech_to_text_WorkerApiKeyCreated'));
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write('web-ui', 'Failed to create worker API key: ' . $exception->getMessage(), LOG_ERR);
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
                            LocalSpeechToTextLogger::write(
                                'web-ui',
                                'Failed to refresh API key cache after delete: ' . $cacheException->getMessage(),
                                LOG_WARNING
                            );
                        }
                    }
                }
            } catch (Throwable $exception) {
                try {
                    $service->rollBackCoreApiKeyTransaction();
                } catch (Throwable $rollbackException) {
                    LocalSpeechToTextLogger::write(
                        'web-ui',
                        'Failed to roll back worker API key deletion: ' . $rollbackException->getMessage(),
                        LOG_ERR
                    );
                }
                throw $exception;
            }
            LocalSpeechToTextLogger::write('web-ui', sprintf('Worker API key deleted: id=%d', $apiKeyId), LOG_NOTICE);
            $this->flash->success($this->translation->_('module_local_speech_to_text_WorkerApiKeyDeleted'));
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write('web-ui', 'Failed to delete worker API key: ' . $exception->getMessage(), LOG_ERR);
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
        $this->view->technicalInfo = $this->technicalInfoService()->technicalInfoCard((string)($result->technical_info ?? ''));
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
        if (!in_array($period, [3600, 10800, 43200, 86400, 604800, 1209600], true)) {
            $period = 3600;
        }

        LocalSpeechToTextLogger::write('web-ui', sprintf('Module logs requested: period=%d seconds', $period), LOG_INFO);
        $payload = (new ModuleLogReader())->moduleLogsPayload(time() - $period);
        $this->view->success = true;
        $this->view->logText = $payload['logText'];
        $this->view->lineCount = $payload['lineCount'];
        $this->view->fileCount = $payload['fileCount'];
        $this->view->generatedAt = date('Y-m-d H:i:s');
        $this->view->period = $period;
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
        $this->view->workers = STTWorker::find(['order' => 'last_seen DESC, id DESC']);
        $this->view->workerRuntimeModel = STTSetting::selectedModelDisplayName();
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

    private function conversationService(): TranscriptConversationService
    {
        return new TranscriptConversationService($this->translation);
    }

    private function technicalInfoService(): TranscriptTechnicalInfoService
    {
        return new TranscriptTechnicalInfoService($this->translation);
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
