<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/** Prepares only the STT dashboard sections authorized for the current user. */
final class IndexViewService
{
    public function __construct(
        private readonly mixed $translation,
        private readonly mixed $request,
        private readonly CallRecordingService $recordings,
    ) {
    }

    /**
     * @param Closure(): mixed $settingsForm
     * @param array{apiKey: string, apiKeyId: int} $generatedApiKey
     */
    public function prepare(
        mixed $view,
        Closure $settingsForm,
        array $generatedApiKey,
        string $activeTab,
        UsersUIViewCapabilities $capabilities,
        UsersUICallScope $callScope,
    ): void {
        $view->activeTab = $activeTab;
        $view->canManageSettings = $capabilities->canManageSettings();
        $view->canManageSystem = $capabilities->canManageSystem();
        $view->canViewTranscripts = $capabilities->canViewTranscripts();

        if ($capabilities->canManageSettings()) {
            $view->form = $settingsForm();
            $view->modelPresets = STTSetting::modelPresets();
            $view->selectedModel = STTSetting::selectedModelId();
            $view->selectedLanguage = STTSetting::selectedLanguageId();
            $marketplace = new ModelMarketplaceService($this->translation);
            $view->modelMarketplaceRows = $marketplace->modelMarketplaceRows();
            $view->modelPlatformRows = $marketplace->platformRows();
            $view->selectedPlatform = STTSetting::selectedPlatform();
        }

        if ($capabilities->canManageSystem()) {
            $this->prepareSystem($view, $generatedApiKey);
        }

        if ($capabilities->canViewTranscripts()) {
            $transcripts = new TranscriptListService(
                $this->translation,
                $this->request,
                $this->recordings,
                $callScope,
                new UsersUITranscriptAccessService(),
            );
            $filter = $transcripts->transcriptDateFilterFromRequest();
            $search = $transcripts->transcriptSearchFromRequest();
            $view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
            // The transcripts tab is a real navigation target (?tab=transcripts,
            // see the nav link in index.volt), not a client-side-only tab
            // switch, so this is the only case where activeTab === 'transcripts'
            // on the request that renders it. Building the row list (bounded
            // SQL queries, CDR lookups, employee lookups) is the expensive part
            // of this whole method; skip it on every other tab's page load.
            $view->transcriptRows = $activeTab === 'transcripts'
                ? $transcripts->transcriptRows(100, $filter, $search['query'])
                : [];
            $view->transcriptFilter = $transcripts->transcriptFilterView($filter);
            $view->transcriptSearch = $search;
            $view->transcriptFilterAction = MenuProvider::DASHBOARD_PATH;
            $view->transcriptFilterKeepTab = true;
            $view->transcriptsPath = MenuProvider::DASHBOARD_PATH . '?tab=transcripts';
        }
    }

    /** @param array{apiKey: string, apiKeyId: int} $generatedApiKey */
    private function prepareSystem(mixed $view, array $generatedApiKey): void
    {
        $queue = new QueueViewService($this->translation, $this->recordings);
        $delayed = $queue->storedRecords(STTSetting::DELAYED_RECORDINGS);
        $skipped = $queue->storedRecords(STTSetting::SKIPPED_RECORDINGS);
        $pending = $this->countJobs(STTJob::STATUS_PENDING);
        $processing = $this->countJobs(STTJob::STATUS_PROCESSING);
        $doneToday = (int)STTJob::count([
            'conditions' => 'status = :status: AND finished_at >= :since:',
            'bind' => ['status' => STTJob::STATUS_DONE, 'since' => strtotime('today')],
        ]);
        $errors = $this->countJobs(STTJob::STATUS_ERROR);

        $view->pendingCount = $pending;
        $view->processingCount = $processing;
        $view->doneTodayCount = $doneToday;
        $view->errorCount = $errors;
        $view->delayedRecordingCount = count($delayed);
        $view->skippedRecordingCount = count($skipped);
        $view->queueSummaryRows = $queue->queueSummaryRows(
            $pending,
            $processing,
            $doneToday,
            $errors,
            $delayed,
            $skipped,
        );
        $view->queueFileRows = $queue->queueFileRows($delayed, $skipped);
        (new WorkerViewService())->prepare($view);
        $view->workerApiKeyRows = (new WorkerApiKeyService())->workerApiKeyRows();
        $view->generatedApiKey = $generatedApiKey['apiKey'];
        $view->isSecureConnection = $this->request->isSecure();
        $view->logsDataPath = MenuProvider::LOGS_PATH;
    }

    private function countJobs(string $status): int
    {
        return (int)STTJob::count([
            'conditions' => 'status = :status:',
            'bind' => ['status' => $status],
        ]);
    }
}
