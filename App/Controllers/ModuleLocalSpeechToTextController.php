<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\Common\Models\ApiKeys;
use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\AdministrativeSettingsWriter;
use Modules\ModuleLocalSpeechToText\Lib\Services\IndexViewService;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\LogicalCallReviewService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLogReader;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerDownloadAvailability;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerDownloadService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use InvalidArgumentException;
use Throwable;

class ModuleLocalSpeechToTextController extends LocalSpeechToTextBaseController
{
    private const string SESSION_GENERATED_API_KEY = 'ModuleLocalSpeechToTextGeneratedApiKey';
    public function indexAction(): void
    {
        $capabilities = $this->usersUIViewCapabilities();
        if ($capabilities->visibleTabs() === []) {
            $this->denyUsersUIAccess();
            return;
        }

        $generatedApiKey = $capabilities->canManageSystem()
            ? $this->generatedApiKeyFromSession()
            : ['apiKey' => '', 'apiKeyId' => 0];
        if ($generatedApiKey['apiKey'] !== '') {
            $this->session->remove(self::SESSION_GENERATED_API_KEY);
            LocalSpeechToTextLogger::event(
                'settings',
                'worker_api_key_shown',
                'New worker API key shown once',
                LOG_NOTICE,
                ['api_key_id' => $generatedApiKey['apiKeyId']]
            );
        }

        $activeTab = $capabilities->normalizeTab($this->activeTabFromRequest($generatedApiKey['apiKey']));
        $this->addModuleAssets();
        (new IndexViewService($this->translation, $this->request, $this->callRecordingService()))->prepare(
            $this->view,
            $this->buildSettingsForm(...),
            $generatedApiKey,
            $activeTab,
            $capabilities,
            $this->usersUICallScope(),
        );
        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/index');
    }

    public function saveAction(): void
    {
        if (!$this->requireSameOriginPost() || !$this->usersUIViewCapabilities()->canManageSettings()) {
            $this->denyUsersUIAccess();
            return;
        }

        try {
            $this->saveSettingsFromPost();
            $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
            $this->view->success = true;
            $this->view->reload = MenuProvider::DASHBOARD_PATH;
        } catch (Throwable $exception) {
            $this->logControllerFailure('save_settings', $exception);
            $this->flash->error($this->settingsSaveErrorMessage($exception));
            $this->view->success = false;
        }

        if ($this->request->isAjax() === false) {
            $this->response->redirect(MenuProvider::DASHBOARD_PATH);
        }
    }

    public function saveModelAction(): void
    {
        if (!$this->requireSameOriginPost() || !$this->usersUIViewCapabilities()->canManageSettings()) {
            $this->denyUsersUIAccess();
            return;
        }

        try {
            $modelId = trim((string)$this->request->getPost(
                STTSetting::SELECTED_MODEL,
                'string',
                STTSetting::selectedModelId()
            ));
            $normalizedModelId = STTSetting::normalizeModelId(substr($modelId, 0, 255));
            $this->assertModelLanguageCompatibility($normalizedModelId, STTSetting::selectedLanguageId());
            (new AdministrativeSettingsWriter())->save(
                [STTSetting::SELECTED_MODEL => $normalizedModelId],
                null,
                true
            );
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
            $this->flash->error($this->settingsSaveErrorMessage($exception));
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=models');
    }

    public function retryErrorsAction(): void
    {
        if (!$this->requireSameOriginPost() || !$this->usersUIViewCapabilities()->canManageSystem()) {
            $this->denyUsersUIAccess();
            return;
        }

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
            $this->flash->error($this->translation->_('module_local_speech_to_text_OperationFailedError'));
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=queue');
    }

    public function downloadWorkerAction(): void
    {
        if (!$this->requireSameOriginPost() || !$this->usersUIViewCapabilities()->canManageSystem()) {
            $this->denyUsersUIAccess();
            return;
        }

        try {
            $service = new WorkerDownloadService();
            $resolution = $service->resolveDownload();
            LocalSpeechToTextLogger::event(
                'settings',
                'worker_download_requested',
                'Worker dmg download requested',
                $resolution->availability === WorkerDownloadAvailability::AVAILABLE ? LOG_NOTICE : LOG_WARNING,
                ['availability' => $resolution->availability->value]
            );
            if ($resolution->availability === WorkerDownloadAvailability::AVAILABLE) {
                // External redirect keeps the license key out of the page HTML.
                $this->response->redirect($resolution->url, true);
                return;
            }
            $this->flash->error($this->translation->_($service->failureTranslationKey($resolution->availability)));
        } catch (Throwable $exception) {
            $this->logControllerFailure('download_worker', $exception);
            $this->flash->error($this->settingsSaveErrorMessage($exception));
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=workers');
    }

    public function generateWorkerApiKeyAction(): void
    {
        if (!$this->requireSameOriginPost() || !$this->usersUIViewCapabilities()->canManageSystem()) {
            $this->denyUsersUIAccess();
            return;
        }

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
            $this->flash->error($this->translation->_('module_local_speech_to_text_OperationFailedError'));
        }

        // Core sends the redirect from afterExecuteRoute, but the Redis session (no locking) is saved only
        // at request shutdown: the redirected page could read the session first, miss the one-time key and
        // then overwrite it with its stale copy. Save the key before the browser can follow the redirect.
        session_write_close();
        $this->response->redirect($this->workersTabPath());
    }

    public function deleteWorkerApiKeyAction(): void
    {
        if (!$this->requireSameOriginPost() || !$this->usersUIViewCapabilities()->canManageSystem()) {
            $this->denyUsersUIAccess();
            return;
        }

        try {
            $apiKeyId = (int)$this->request->getPost('api_key_id', 'int', 0);
            if (!STTSetting::isWorkerApiKeyIdAllowed($apiKeyId)) {
                $this->flash->warning($this->translation->_('module_local_speech_to_text_WorkerApiKeyNotFound'));
                $this->response->redirect($this->workersTabPath());
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
            $this->flash->error($this->translation->_('module_local_speech_to_text_OperationFailedError'));
        }

        $this->response->redirect($this->workersTabPath());
    }

    public function transcriptsAction(): void
    {
        if (!$this->usersUIViewCapabilities()->canViewTranscripts()) {
            $this->denyUsersUIAccess();
            return;
        }

        $transcripts = $this->transcriptListService();
        $transcriptFilter = $transcripts->transcriptDateFilterFromRequest();
        $transcriptSearch = $transcripts->transcriptSearchFromRequest();

        $this->addModuleAssets();
        $this->view->dashboardPath = MenuProvider::DASHBOARD_PATH;
        $this->view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
        $this->view->transcriptRows = $transcripts->transcriptRows(100, $transcriptFilter, $transcriptSearch['query']);
        $this->view->transcriptFilter = $transcripts->transcriptFilterView($transcriptFilter);
        $this->view->transcriptSearch = $transcriptSearch;
        $this->view->transcriptFilterAction = MenuProvider::TRANSCRIPTS_PATH;
        $this->view->transcriptFilterKeepTab = false;
        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcripts');
    }

    public function transcriptAction(): void
    {
        if (!$this->usersUIViewCapabilities()->canViewTranscripts()) {
            $this->denyUsersUIAccess();
            return;
        }

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
        if (!$this->canAccessTranscript($job)) {
            $this->flash->error($this->translation->_('module_local_speech_to_text_TranscriptNotFound'));
            $this->response->redirect(MenuProvider::TRANSCRIPTS_PATH);
            return;
        }
        $recordings = $this->callRecordingService();
        $review = (new LogicalCallReviewService(
            $this->translation,
            $recordings,
            $this->conversationSpeakerLabelResolver(),
        ))->review($job instanceof STTJob ? (string)$job->call_id : '', $result);

        $this->view->transcriptsPath = MenuProvider::DASHBOARD_PATH . '?tab=transcripts';
        $this->view->transcriptExportPath = MenuProvider::TRANSCRIPT_EXPORT_PATH;
        $this->view->transcriptDeletePath = MenuProvider::TRANSCRIPT_DELETE_PATH;
        $this->view->canDeleteTranscript = $this->usersUIViewCapabilities()->canDeleteTranscripts();
        $this->view->result = $result;
        $this->view->transcriptStages = $review['stages'];
        $this->view->transcriptGroups = $review['groups'];
        $this->view->showCallLegTabs = $review['showTabs'];
        $this->view->showTranscriptStageTitles = count($review['stages']) > 1;
        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcript');
    }

    public function recordingAction(): void
    {
        $this->view->disable();

        $recordings = $this->callRecordingService();
        if (!$this->usersUIViewCapabilities()->canViewTranscripts()) {
            $recordings->sendRecordingError(403, 'Forbidden');
            return;
        }
        $result = STTResult::findFirstById((int)$this->request->getQuery('id', 'int', 0));
        if (!$result instanceof STTResult) {
            $recordings->sendRecordingError(404, 'Recording not found');
            return;
        }

        $job = $result->STTJob;
        $job = $job instanceof STTJob ? $job : null;
        if (!$this->canAccessTranscript($job)) {
            $recordings->sendRecordingError(404, 'Recording not found');
            return;
        }
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
        if (!$this->usersUIViewCapabilities()->canManageSystem()) {
            $this->denyUsersUIAccess();
            return;
        }

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

    /** The Workers tab, reopened on the worker platform the administrator was looking at. */
    private function workersTabPath(): string
    {
        $platform = (string)$this->request->getPost('worker_platform', 'string', '');

        return MenuProvider::DASHBOARD_PATH . '?tab=workers'
            . (in_array($platform, ['macos', 'linux'], true) ? '&platform=' . $platform : '');
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

    private function settingsSaveErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof InvalidArgumentException) {
            return $exception->getMessage();
        }

        return $this->translation->_('module_local_speech_to_text_SettingsSaveError');
    }

}
