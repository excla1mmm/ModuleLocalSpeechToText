<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\Common\Models\ApiKeys;
use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\LanInterfaces;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Common\Models\Users;
use MikoPBX\Core\System\Directories;
use MikoPBX\PBXCoreREST\Services\TokenValidationService;
use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use RuntimeException;
use Throwable;

class ModuleLocalSpeechToTextController extends LocalSpeechToTextBaseController
{
    private const string SESSION_GENERATED_API_KEY = 'ModuleLocalSpeechToTextGeneratedApiKey';
    private const int MODULE_LOG_MAX_RESPONSE_LINES = 2000;
    private const int MODULE_LOG_TAIL_LINES_PER_FILE = 4000;

    public function indexAction(): void
    {
        $generatedApiKey = $this->generatedApiKeyFromSession();
        if ($generatedApiKey['apiKey'] !== '') {
            $this->session->remove(self::SESSION_GENERATED_API_KEY);
        }

        $this->prepareIndexView($generatedApiKey, $this->activeTabFromRequest($generatedApiKey['apiKey']));
    }

    /**
     * @param array{apiKey: string, apiKeyId: int} $generatedApiKey
     */
    private function prepareIndexView(array $generatedApiKey = ['apiKey' => '', 'apiKeyId' => 0], string $activeTab = 'settings'): void
    {
        $this->addModuleAssets();

        $this->view->activeTab = $activeTab;
        $this->view->form = $this->buildSettingsForm();
        $this->view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
        $this->view->modelPresets = STTSetting::MODEL_PRESETS;
        $this->view->selectedModel = STTSetting::selectedModelId();
        $this->view->vocabularyDictionaries = STTSetting::VOCABULARY_DICTIONARIES;
        $this->view->selectedVocabularyDictionaries = STTSetting::selectedVocabularyDictionaryIds();
        $this->view->selectedVocabularyTermsCount = count(STTSetting::selectedVocabularyTerms());
        $pendingCount = $this->countJobsByStatus(STTJob::STATUS_PENDING);
        $processingCount = $this->countJobsByStatus(STTJob::STATUS_PROCESSING);
        $doneTodayCount = (int)STTJob::count([
            'conditions' => 'status = :status: AND finished_at >= :since:',
            'bind' => [
                'status' => STTJob::STATUS_DONE,
                'since' => strtotime('today'),
            ],
        ]);
        $errorCount = $this->countJobsByStatus(STTJob::STATUS_ERROR);
        $delayedRecordings = $this->storedRecords(STTSetting::DELAYED_RECORDINGS);
        $skippedRecordings = $this->storedRecords(STTSetting::SKIPPED_RECORDINGS);
        $this->view->pendingCount = $pendingCount;
        $this->view->processingCount = $processingCount;
        $this->view->doneTodayCount = $doneTodayCount;
        $this->view->errorCount = $errorCount;
        $this->view->delayedRecordingCount = count($delayedRecordings);
        $this->view->skippedRecordingCount = count($skippedRecordings);
        $this->view->queueSummaryRows = $this->queueSummaryRows(
            $pendingCount,
            $processingCount,
            $doneTodayCount,
            $errorCount,
            $delayedRecordings,
            $skippedRecordings
        );
        $this->view->queueFileRows = $this->queueFileRows($delayedRecordings, $skippedRecordings);
        STTWorker::markStaleOffline();
        $this->view->workers = STTWorker::find([
            'order' => 'last_seen DESC, id DESC',
        ]);
        $this->view->workerRuntimeModel = STTSetting::selectedModelId();
        $this->view->workerApiKeyRows = $this->workerApiKeyRows();
        $this->view->generatedApiKey = $generatedApiKey['apiKey'];
        $this->view->workerInstallCommand = $generatedApiKey['apiKey'] === ''
            ? ''
            : $this->workerInstallCommand($generatedApiKey['apiKey']);
        $transcriptFilter = $this->transcriptDateFilterFromRequest();
        $transcriptRows = $this->transcriptRows(100, $transcriptFilter);
        $this->view->transcriptRows = $transcriptRows;
        $this->view->transcriptFilter = $this->transcriptFilterView($transcriptFilter);
        $this->view->transcriptFilterAction = MenuProvider::DASHBOARD_PATH;
        $this->view->transcriptFilterKeepTab = true;
        $this->view->logsDataPath = MenuProvider::LOGS_PATH;

        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/index');
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

    public function retryErrorsAction(): void
    {
        try {
            $count = STTJob::requeueErrors();
            if ($count > 0) {
                $message = sprintf($this->translation->_('module_local_speech_to_text_RetryErrorsSuccess'), $count);
                $this->flash->success($message);
            } else {
                $this->flash->warning($this->translation->_('module_local_speech_to_text_RetryErrorsEmpty'));
            }
        } catch (Throwable $exception) {
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=queue');
    }

    public function generateWorkerApiKeyAction(): void
    {
        try {
            $plainApiKey = ApiKeys::generateApiKey();
            $apiKey = $this->createWorkerApiKey($plainApiKey);
            $this->session->set(self::SESSION_GENERATED_API_KEY, [
                'apiKey' => $plainApiKey,
                'apiKeyId' => (int)$apiKey->id,
            ]);
            $this->flash->success($this->translation->_('module_local_speech_to_text_WorkerApiKeyCreated'));
            $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=workers');
        } catch (Throwable $exception) {
            $this->flash->error($exception->getMessage());
            $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=workers');
        }
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

            $apiKey = ApiKeys::findFirstById($apiKeyId);
            if ($apiKey instanceof ApiKeys && !$apiKey->delete()) {
                throw new RuntimeException(implode(', ', $apiKey->getMessages()));
            }

            STTSetting::removeWorkerApiKeyId($apiKeyId);
            $this->unbindWorkersFromApiKey($apiKeyId);
            TokenValidationService::clearCache();
            $this->flash->success($this->translation->_('module_local_speech_to_text_WorkerApiKeyDeleted'));
        } catch (Throwable $exception) {
            $this->flash->error($exception->getMessage());
        }

        $this->response->redirect(MenuProvider::DASHBOARD_PATH . '?tab=workers');
    }

    public function transcriptsAction(): void
    {
        $this->addModuleAssets();
        $transcriptFilter = $this->transcriptDateFilterFromRequest();
        $transcriptRows = $this->transcriptRows(100, $transcriptFilter);

        $this->view->dashboardPath = MenuProvider::DASHBOARD_PATH;
        $this->view->transcriptPath = MenuProvider::TRANSCRIPT_PATH;
        $this->view->transcriptRows = $transcriptRows;
        $this->view->transcriptFilter = $this->transcriptFilterView($transcriptFilter);
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
        $cdr = $job instanceof STTJob ? $this->cdrRecordForJob($job) : null;
        $durationReference = $cdr instanceof CallDetailRecords ? (float)$cdr->duration : null;

        $this->view->transcriptsPath = MenuProvider::DASHBOARD_PATH . '?tab=transcripts';
        $this->view->result = $result;
        $this->view->conversation = $this->conversationFromTranscript(
            (string)$result->transcript,
            $this->normalizedSecondsValue($result->duration ?? null, $durationReference)
        );
        $this->view->recording = $this->recordingCard($result, $job, $cdr);
        $this->view->technicalInfo = $this->technicalInfoCard((string)($result->technical_info ?? ''));

        $this->view->pick('Modules/ModuleLocalSpeechToText/ModuleLocalSpeechToText/transcript');
    }

    public function recordingAction(): void
    {
        $this->view->disable();

        $resultId = (int)$this->request->getQuery('id', 'int', 0);
        $download = (int)$this->request->getQuery('download', 'int', 0) === 1;
        $result = STTResult::findFirstById($resultId);
        $job = $result instanceof STTResult ? $result->STTJob : null;
        if (!$job instanceof STTJob) {
            $this->sendRecordingError(404, 'Recording not found');
            return;
        }

        $filePath = $this->safeMonitorRecordingPath((string)$job->file_path);
        if ($filePath === '') {
            $this->sendRecordingError(404, 'Recording not found');
            return;
        }

        $this->streamRecordingFile($filePath, $download);
    }

    public function logsAction(): void
    {
        $period = (int)$this->request->getQuery('period', 'int', 3600);
        $allowedPeriods = [3600, 10800, 43200, 86400, 604800, 1209600];
        if (!in_array($period, $allowedPeriods, true)) {
            $period = 3600;
        }

        $payload = $this->moduleLogsPayload(time() - $period);
        $this->view->success = true;
        $this->view->logText = $payload['logText'];
        $this->view->lineCount = $payload['lineCount'];
        $this->view->fileCount = $payload['fileCount'];
        $this->view->generatedAt = date('Y-m-d H:i:s');
        $this->view->period = $period;
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationFromTranscript(string $transcript, ?float $recordingDuration = null): array
    {
        $segments = json_decode($transcript, true);
        if (!is_array($segments)) {
            return $this->emptyConversation();
        }

        $speakers = [
            'left' => $this->speakerColumn('left', $this->translation->_('module_local_speech_to_text_Speaker1')),
            'right' => $this->speakerColumn('right', $this->translation->_('module_local_speech_to_text_Speaker2')),
        ];

        $timeline = [];
        $timeScale = $this->transcriptTimeScale($segments, $recordingDuration);

        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $speakerKey = $this->speakerKey($segment);
            if (!isset($speakers[$speakerKey])) {
                $speakers[$speakerKey] = $this->speakerColumn($speakerKey, $this->speakerLabel($speakerKey, count($speakers) + 1));
            }

            [$start, $end] = $this->normalizeTranscriptRange(
                $segment['start'] ?? null,
                $segment['end'] ?? null,
                $timeScale,
                $recordingDuration
            );
            $orderTime = $start > 0 ? $start : $end;
            $utterance = [
                'text' => $text,
                'speakerKey' => $speakerKey,
                'speakerLabel' => $speakers[$speakerKey]['label'],
                'start' => $start,
                'end' => $end,
                'endLabel' => $this->timeLabel($orderTime),
                'orderTime' => $orderTime,
                'rangeLabel' => $this->rangeLabel($start, $end),
            ];
            $speakers[$speakerKey]['segments'][] = $utterance;
            $timeline[] = $utterance;
        }

        foreach ($speakers as &$speaker) {
            usort(
                $speaker['segments'],
                static fn (array $left, array $right): int => [$left['orderTime'], $left['start']]
                    <=> [$right['orderTime'], $right['start']]
            );
        }
        unset($speaker);

        usort(
            $timeline,
            static fn (array $left, array $right): int => [$left['orderTime'], $left['start'], $left['end']]
                <=> [$right['orderTime'], $right['start'], $right['end']]
        );

        return [
            'speakers' => array_values($speakers),
            'timeline' => $timeline,
            'hasSegments' => count($timeline) > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyConversation(): array
    {
        return [
            'speakers' => [
                $this->speakerColumn('left', $this->translation->_('module_local_speech_to_text_Speaker1')),
                $this->speakerColumn('right', $this->translation->_('module_local_speech_to_text_Speaker2')),
            ],
            'timeline' => [],
            'hasSegments' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function speakerColumn(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'segments' => [],
        ];
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function speakerKey(array $segment): string
    {
        $speaker = strtolower(trim((string)($segment['speaker'] ?? '')));
        $channel = strtolower(trim((string)($segment['channel'] ?? '')));
        $key = $speaker !== '' ? $speaker : $channel;

        if (in_array($key, ['speaker 1', 'speaker_1', 'speaker1', 'left'], true)) {
            return 'left';
        }
        if (in_array($key, ['speaker 2', 'speaker_2', 'speaker2', 'right'], true)) {
            return 'right';
        }

        return $key === '' ? 'left' : (string)preg_replace('/[^a-z0-9_-]+/', '-', $key);
    }

    private function speakerLabel(string $speakerKey, int $number): string
    {
        if ($speakerKey === 'left') {
            return $this->translation->_('module_local_speech_to_text_Speaker1');
        }
        if ($speakerKey === 'right') {
            return $this->translation->_('module_local_speech_to_text_Speaker2');
        }

        return sprintf('%s %d', $this->translation->_('module_local_speech_to_text_Speaker'), $number);
    }

    private function floatValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        return 0.0;
    }

    private function normalizedSecondsValue(mixed $value, mixed $maxReference = null): float
    {
        $normalized = $this->normalizeLargeDecimal($value, $maxReference);

        return is_numeric($normalized) ? max(0.0, (float)$normalized) : 0.0;
    }

    /**
     * @param array<int, mixed> $segments
     */
    private function transcriptTimeScale(array $segments, ?float $recordingDuration): float
    {
        $maxTime = 0.0;
        $hasFractionalTime = false;
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            foreach (['start', 'end'] as $field) {
                if (is_numeric($segment[$field] ?? null)) {
                    $time = (float)$segment[$field];
                    $maxTime = max($maxTime, abs($time));
                    $hasFractionalTime = $hasFractionalTime || abs($time - round($time)) > 0.000001;
                }
            }
        }

        if ($maxTime <= 0) {
            return 1.0;
        }
        if ($hasFractionalTime || $maxTime <= 10) {
            return 1.0;
        }
        if ($maxTime <= 360000) {
            return 100.0;
        }

        $scales = [1.0, 10.0, 100.0, 1000.0];
        if ($recordingDuration !== null && $recordingDuration > 0) {
            $bestScale = 1.0;
            $bestScore = PHP_FLOAT_MAX;
            foreach ($scales as $scale) {
                $candidate = $maxTime / $scale;
                $score = abs($candidate - $recordingDuration);
                if ($candidate > $recordingDuration * 1.2) {
                    $score += $recordingDuration;
                }
                if ($candidate < $recordingDuration * 0.01) {
                    $score += $recordingDuration;
                }
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestScale = $scale;
                }
            }

            return $bestScale;
        }

        if ($maxTime > 360000) {
            return 1000.0;
        }
        if ($maxTime > 1000) {
            return 100.0;
        }

        return 1.0;
    }

    private function normalizeTranscriptTime(
        mixed $value,
        float $scale,
        ?float $recordingDuration,
        ?float $minimum = null
    ): float {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $number = (float)$value;
        $base = max(0.0, $number / max(1.0, $scale));
        $durationLimit = $recordingDuration !== null && $recordingDuration > 0
            ? $recordingDuration + max(2.0, $recordingDuration * 0.05)
            : null;

        if (($minimum === null || $base + 0.05 >= $minimum) && ($durationLimit === null || $base <= $durationLimit)) {
            return $base;
        }

        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ([1.0, 10.0, 100.0, 1000.0] as $candidateScale) {
            $candidate = max(0.0, $number / $candidateScale);
            if ($minimum !== null && $candidate + 0.05 < $minimum) {
                continue;
            }
            if ($durationLimit !== null && $candidate > $durationLimit) {
                continue;
            }

            $score = abs($candidate - $base);
            if ($minimum !== null) {
                $score += abs($candidate - $minimum) * 0.05;
            }
            if ($recordingDuration !== null && $recordingDuration > 0) {
                $score += abs($candidate - min($candidate, $recordingDuration)) * 0.5;
            }

            if ($score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best ?? $base;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function normalizeTranscriptRange(mixed $startValue, mixed $endValue, float $scale, ?float $recordingDuration): array
    {
        $start = $this->normalizeTranscriptTime($startValue, $scale, $recordingDuration);
        $end = $this->normalizeTranscriptTime($endValue, $scale, $recordingDuration, $start);
        if (!is_numeric($startValue) || !is_numeric($endValue)) {
            return [$start, $end];
        }

        $duration = $end - $start;
        if ($duration >= 0 && $duration <= $this->maxTranscriptSegmentDuration($recordingDuration)) {
            return [$start, $end];
        }

        $durationLimit = $recordingDuration !== null && $recordingDuration > 0
            ? $recordingDuration + max(2.0, $recordingDuration * 0.05)
            : null;
        $bestStart = $start;
        $bestEnd = $end;
        $bestScore = PHP_FLOAT_MAX;
        $baseStart = max(0.0, (float)$startValue / max(1.0, $scale));
        $baseEnd = max(0.0, (float)$endValue / max(1.0, $scale));

        foreach ([0.01, 0.1, 1.0, 10.0, 100.0, 1000.0] as $startScale) {
            $candidateStart = max(0.0, (float)$startValue / $startScale);
            if ($durationLimit !== null && $candidateStart > $durationLimit) {
                continue;
            }

            foreach ([0.01, 0.1, 1.0, 10.0, 100.0, 1000.0] as $endScale) {
                $candidateEnd = max(0.0, (float)$endValue / $endScale);
                if ($durationLimit !== null && $candidateEnd > $durationLimit) {
                    continue;
                }
                if ($candidateEnd + 0.05 < $candidateStart) {
                    continue;
                }

                $candidateDuration = $candidateEnd - $candidateStart;
                $score = $this->scaleDistance($startScale, $scale) + $this->scaleDistance($endScale, $scale);
                $score += abs($candidateStart - $baseStart) * 0.01 + abs($candidateEnd - $baseEnd) * 0.01;
                if ($candidateDuration > $this->maxTranscriptSegmentDuration($recordingDuration)) {
                    $score += ($candidateDuration - $this->maxTranscriptSegmentDuration($recordingDuration)) * 10.0;
                }
                if ($recordingDuration !== null && $recordingDuration > 0) {
                    $score += abs($candidateEnd - min($candidateEnd, $recordingDuration)) * 0.5;
                }

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestStart = $candidateStart;
                    $bestEnd = $candidateEnd;
                }
            }
        }

        return [$bestStart, $bestEnd];
    }

    private function maxTranscriptSegmentDuration(?float $recordingDuration): float
    {
        if ($recordingDuration !== null && $recordingDuration > 0) {
            return min(35.0, max(12.0, $recordingDuration * 0.25));
        }

        return 35.0;
    }

    private function scaleDistance(float $candidateScale, float $baseScale): float
    {
        return abs(log($candidateScale / max(1.0, $baseScale), 10));
    }

    private function timeLabel(float $seconds): string
    {
        $totalSeconds = (int)floor(max(0.0, $seconds));
        $minutes = intdiv($totalSeconds, 60);
        $rest = $totalSeconds % 60;

        return sprintf('%d:%02d', $minutes, $rest);
    }

    private function rangeLabel(float $start, float $end): string
    {
        if ($end <= $start) {
            return $this->timeLabel($start);
        }

        return sprintf('%s - %s', $this->timeLabel($start), $this->timeLabel($end));
    }

    /**
     * @return array<string, mixed>
     */
    private function recordingCard(STTResult $result, ?STTJob $job, ?CallDetailRecords $cdr = null): array
    {
        $cdr = $cdr instanceof CallDetailRecords ? $cdr : ($job instanceof STTJob ? $this->cdrRecordForJob($job) : null);
        $filePath = $job instanceof STTJob ? (string)$job->file_path : '';
        $playbackUrl = '';
        $downloadUrl = '';
        $safeFilePath = $this->safeMonitorRecordingPath($filePath);
        if ($safeFilePath !== '') {
            $recordingUrl = MenuProvider::RECORDING_PATH . '?id=' . (int)$result->id;
            $playbackUrl = $this->url->get($recordingUrl);
            $downloadUrl = $this->url->get($recordingUrl . '&download=1');
        }
        $callTimestamp = $this->callTimestamp($result, $job);
        $cdrDuration = $cdr instanceof CallDetailRecords ? (float)$cdr->duration : null;
        $duration = $this->normalizedSecondsValue($result->duration ?? null, $cdrDuration);
        if ($duration <= 0 && $cdr instanceof CallDetailRecords) {
            $duration = (float)$cdr->duration;
        }
        $sourceNumber = $cdr instanceof CallDetailRecords ? $this->numberWithEmployeeName((string)$cdr->src_num) : '';
        $destinationNumber = $cdr instanceof CallDetailRecords ? $this->numberWithEmployeeName((string)$cdr->dst_num) : '';

        $rows = [
            [
                'label' => $this->translation->_('module_local_speech_to_text_CallDate'),
                'value' => $callTimestamp > 0 ? date('Y-m-d H:i:s', $callTimestamp) : '',
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_Duration'),
                'value' => $duration > 0 ? $this->durationLabel($duration) : '',
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_SourceNumber'),
                'value' => $sourceNumber,
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_DestinationNumber'),
                'value' => $destinationNumber,
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_TranscriptCreatedAt'),
                'value' => (int)$result->created_at > 0 ? date('Y-m-d H:i:s', (int)$result->created_at) : '',
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_Model'),
                'value' => (string)$result->model_used,
            ],
        ];

        return [
            'hasPlayback' => $playbackUrl !== '',
            'playbackUrl' => $playbackUrl,
            'downloadUrl' => $downloadUrl,
            'durationSeconds' => $duration > 0 ? (string)$duration : '',
            'durationLabel' => $duration > 0 ? $this->durationLabel($duration) : '',
            'rows' => array_values(array_filter(
                $rows,
                static fn (array $row): bool => trim((string)$row['value']) !== ''
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalInfoCard(string $technicalInfo): array
    {
        $data = json_decode($technicalInfo, true);
        if (!is_array($data)) {
            return [
                'hasRows' => false,
                'groups' => [],
                'rawJson' => '',
            ];
        }
        $data = $this->normalizeTechnicalInfo($data);

        $groups = [
            [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupPipeline'),
                'rows' => array_values(array_filter([
                    $this->technicalRow('module_local_speech_to_text_TechnicalModelResolved', $this->stringMetric($data, 'model.resolved')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalModelRequested', $this->stringMetric($data, 'model.requested')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalWhisperKitModel', $this->stringMetric($data, 'model.whisperkit_model')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalLanguageHint', $this->stringMetric($data, 'model.language_hint')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalLanguageDetected', $this->stringMetric($data, 'language.dominant')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalLanguageVotes', $this->languageVotesMetric($data)),
                    $this->technicalRow('module_local_speech_to_text_TechnicalStereoMode', $this->stringMetric($data, 'pipeline.stereo_mode')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSplitChannels', $this->boolMetric($data, 'pipeline.split_channels')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalAudioNormalize', $this->boolMetric($data, 'pipeline.audio_normalize')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalContext', $this->boolMetric($data, 'pipeline.context_enabled')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalVocabularyTerms', $this->numberMetric($data, 'pipeline.vocabulary_terms')),
                ])),
            ],
            [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupVad'),
                'rows' => array_values(array_filter([
                    $this->technicalRow('module_local_speech_to_text_TechnicalVadEnabled', $this->boolMetric($data, 'vad.enabled')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalInputDuration', $this->secondsMetric($data, 'input.duration_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalInputChannels', $this->numberMetric($data, 'input.channels')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalVadSegments', $this->numberMetric($data, 'vad.detected_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSpeechSeconds', $this->secondsMetric($data, 'vad.speech_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalTimelineSpeechSeconds', $this->secondsMetric($data, 'vad.timeline_speech_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSpeechRatio', $this->percentMetric($data, 'vad.speech_ratio')),
                ])),
            ],
            [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupPerformance'),
                'rows' => array_values(array_filter([
                    $this->technicalRow('module_local_speech_to_text_TechnicalSubmittedSegments', $this->numberMetric($data, 'transcription.submitted_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalRecognizedSegments', $this->numberMetric($data, 'transcription.recognized_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalAttemptedSegments', $this->numberMetric($data, 'transcription.attempted_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalEmptySegments', $this->numberMetric($data, 'transcription.empty_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalFallbackUsed', $this->boolMetric($data, 'transcription.fallback_used')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalFallbackSegments', $this->numberMetric($data, 'transcription.fallback_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalWarnings', $this->numberMetric($data, 'transcription.warnings')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSttCommands', $this->numberMetric($data, 'transcription.stt_commands')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalElapsed', $this->secondsMetric($data, 'timing.elapsed_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSttCommandTime', $this->secondsMetric($data, 'timing.stt_command_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalRealTimeFactor', $this->ratioMetric($data, 'timing.real_time_factor')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalStartedAt', $this->timestampMetric($data, 'timing.started_at')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalRejectedSegments', $this->numberMetric($data, 'quality.rejected_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSkippedShortSegments', $this->numberMetric($data, 'quality.skipped_short_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalQualityReasons', $this->qualityReasonsMetric($data)),
                ])),
            ],
        ];

        $channelRows = $this->technicalChannelRows($data);
        if ($channelRows !== []) {
            $groups[] = [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupChannels'),
                'rows' => $channelRows,
            ];
        }

        $groups = array_values(array_filter(
            $groups,
            static fn (array $group): bool => ($group['rows'] ?? []) !== []
        ));

        return [
            'hasRows' => $groups !== [],
            'groups' => $groups,
            'rawJson' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
        ];
    }

    private function normalizeTechnicalInfo(array $data): array
    {
        if ($this->metricValue($data, 'input.duration_seconds') === null) {
            $this->setMetricValue($data, 'input.duration_seconds', $this->metricValue($data, 'audio.duration_seconds'));
        }
        if ($this->metricValue($data, 'input.channels') === null) {
            $this->setMetricValue($data, 'input.channels', $this->metricValue($data, 'audio.channels'));
        }
        if ($this->metricValue($data, 'pipeline.stereo_mode') === null) {
            $this->setMetricValue($data, 'pipeline.stereo_mode', $this->metricValue($data, 'audio.stereo_mode'));
        }
        if ($this->metricValue($data, 'pipeline.split_channels') === null) {
            $this->setMetricValue($data, 'pipeline.split_channels', $this->metricValue($data, 'audio.split_channels'));
        }
        if ($this->metricValue($data, 'pipeline.audio_normalize') === null) {
            $this->setMetricValue($data, 'pipeline.audio_normalize', $this->metricValue($data, 'audio.normalization'));
        }
        if ($this->metricValue($data, 'vad.detected_segments') === null) {
            $this->setMetricValue($data, 'vad.detected_segments', $this->metricValue($data, 'vad.segments'));
        }
        if (is_array($data['warnings'] ?? null)) {
            $this->setMetricValue($data, 'transcription.warnings', count($data['warnings']));
        }

        $rawDuration = $this->metricValue($data, 'input.duration_seconds');
        $shortRecordingThreshold = $this->metricValue($data, 'short_recording_threshold_seconds');
        $duration = $this->metricValue($data, 'short_recording') === true && is_numeric($shortRecordingThreshold)
            ? $this->normalizeDecimalWithExpected($rawDuration, (float)$shortRecordingThreshold)
            : $this->normalizeLargeDecimal($rawDuration);
        $this->setMetricValue($data, 'input.duration_seconds', $duration);
        $channelsCount = max(1, (int)($this->metricValue($data, 'input.channels') ?: 1));
        $speechRatio = $this->normalizeRatio($this->metricValue($data, 'vad.speech_ratio'));

        $speechSeconds = $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'vad.speech_seconds'),
            is_numeric($duration) && is_numeric($speechRatio) ? (float)$duration * $channelsCount * (float)$speechRatio : null
        );
        $this->setMetricValue($data, 'vad.speech_seconds', $speechSeconds);
        $this->setMetricValue($data, 'vad.transcription_seconds', $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'vad.transcription_seconds'),
            $speechSeconds
        ));
        $this->setMetricValue($data, 'vad.speech_ratio', $speechRatio);
        $timelineRatio = $this->normalizeRatio($this->metricValue($data, 'vad.timeline_speech_ratio'));
        $timelineSpeechSeconds = $this->metricValue($data, 'vad.timeline_speech_seconds');
        if ($timelineSpeechSeconds === null) {
            $timelineSpeechSeconds = $this->timelineSpeechSecondsFromTechnicalInfo($data);
        }
        if ($timelineSpeechSeconds !== null) {
            $timelineSpeechSeconds = $this->normalizeDecimalWithExpected(
                $timelineSpeechSeconds,
                is_numeric($duration) && is_numeric($timelineRatio) ? (float)$duration * (float)$timelineRatio : null
            );
            $this->setMetricValue($data, 'vad.timeline_speech_seconds', $timelineSpeechSeconds);
        }
        if ($timelineRatio !== null) {
            $this->setMetricValue($data, 'vad.timeline_speech_ratio', $timelineRatio);
        }
        $realTimeFactor = $this->normalizeRatio(
            $this->metricValue($data, 'timing.real_time_factor'),
            true
        );
        $this->setMetricValue($data, 'timing.real_time_factor', $realTimeFactor);
        $elapsedExpected = is_numeric($duration) && is_numeric($realTimeFactor)
            ? (float)$duration * (float)$realTimeFactor
            : null;
        $elapsedSeconds = $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'timing.elapsed_seconds'),
            $elapsedExpected
        );
        $this->setMetricValue($data, 'timing.elapsed_seconds', $elapsedSeconds);
        $this->setMetricValue($data, 'timing.stt_command_seconds', $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'timing.stt_command_seconds'),
            $elapsedSeconds
        ));

        $votes = $this->metricValue($data, 'language.votes_seconds');
        if (is_array($votes)) {
            foreach ($votes as $language => $seconds) {
                $votes[$language] = $this->normalizeLargeDecimal($seconds, $duration);
            }
            $this->setMetricValue($data, 'language.votes_seconds', $votes);
        }

        $channels = $this->metricValue($data, 'vad.channels');
        if (is_array($channels)) {
            foreach ($channels as $channel => $stats) {
                if (!is_array($stats)) {
                    continue;
                }
                $stats['speech_ratio'] = $this->normalizeRatio($stats['speech_ratio'] ?? null);
                $stats['duration_seconds'] = $this->normalizeDecimalWithExpected($stats['duration_seconds'] ?? null, $duration);
                $stats['speech_seconds'] = $this->normalizeDecimalWithExpected(
                    $stats['speech_seconds'] ?? null,
                    is_numeric($stats['duration_seconds']) && is_numeric($stats['speech_ratio'])
                        ? (float)$stats['duration_seconds'] * (float)$stats['speech_ratio']
                        : null
                );
                $stats['transcription_seconds'] = $this->normalizeDecimalWithExpected(
                    $stats['transcription_seconds'] ?? null,
                    $stats['speech_seconds'] ?? null
                );
                $channels[$channel] = $stats;
            }
            $this->setMetricValue($data, 'vad.channels', $channels);
        }

        return $data;
    }

    private function timelineSpeechSecondsFromTechnicalInfo(array $data): ?float
    {
        $channels = $this->metricValue($data, 'vad.channels');
        if (!is_array($channels)) {
            return null;
        }

        $windows = [];
        foreach ($channels as $stats) {
            if (!is_array($stats) || !is_array($stats['speech_windows'] ?? null)) {
                continue;
            }

            foreach ($stats['speech_windows'] as $window) {
                if (!is_array($window) || count($window) < 2) {
                    continue;
                }

                $start = $window[0] ?? null;
                $end = $window[1] ?? null;
                if (!is_numeric($start) || !is_numeric($end) || (float)$end <= (float)$start) {
                    continue;
                }

                $windows[] = [(float)$start, (float)$end];
            }
        }

        if ($windows === []) {
            return null;
        }

        usort($windows, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        [$currentStart, $currentEnd] = $windows[0];
        $total = 0.0;
        foreach (array_slice($windows, 1) as [$start, $end]) {
            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);
                continue;
            }

            $total += $currentEnd - $currentStart;
            $currentStart = $start;
            $currentEnd = $end;
        }

        return round($total + $currentEnd - $currentStart, 3);
    }

    private function technicalRow(string $labelKey, string $value): ?array
    {
        if (trim($value) === '') {
            return null;
        }

        return [
            'label' => $this->translation->_($labelKey),
            'value' => $value,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function technicalChannelRows(array $data): array
    {
        $channels = $this->metricValue($data, 'vad.channels');
        if (!is_array($channels)) {
            return [];
        }

        $rows = [];
        foreach ($channels as $channel => $stats) {
            if (!is_array($stats)) {
                continue;
            }

            $parts = array_values(array_filter([
                $this->numberValue($stats['detected_segments'] ?? null) !== '' ? sprintf(
                    '%s: %s',
                    $this->translation->_('module_local_speech_to_text_TechnicalVadSegmentsShort'),
                    $this->numberValue($stats['detected_segments'] ?? null)
                ) : '',
                $this->secondsValue($stats['speech_seconds'] ?? null) !== '' ? sprintf(
                    '%s: %s',
                    $this->translation->_('module_local_speech_to_text_TechnicalSpeechShort'),
                    $this->secondsValue($stats['speech_seconds'] ?? null)
                ) : '',
                $this->percentValue($stats['speech_ratio'] ?? null) !== '' ? sprintf(
                    '%s: %s',
                    $this->translation->_('module_local_speech_to_text_TechnicalSpeechRatioShort'),
                    $this->percentValue($stats['speech_ratio'] ?? null)
                ) : '',
            ]));

            if ($parts === []) {
                continue;
            }

            $rows[] = [
                'label' => (string)$channel,
                'value' => implode(' / ', $parts),
            ];
        }

        return $rows;
    }

    private function stringMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function numberMetric(array $data, string $path): string
    {
        return $this->numberValue($this->metricValue($data, $path));
    }

    private function secondsMetric(array $data, string $path): string
    {
        return $this->secondsValue($this->metricValue($data, $path));
    }

    private function percentMetric(array $data, string $path): string
    {
        return $this->percentValue($this->metricValue($data, $path));
    }

    private function languageVotesMetric(array $data): string
    {
        $votes = $this->metricValue($data, 'language.votes_seconds');
        if (!is_array($votes) || $votes === []) {
            return '';
        }

        arsort($votes, SORT_NUMERIC);
        $parts = [];
        foreach ($votes as $language => $seconds) {
            if (!is_numeric($seconds)) {
                continue;
            }
            $parts[] = sprintf('%s: %s', (string)$language, $this->secondsValue($seconds));
        }

        return implode(' / ', $parts);
    }

    private function qualityReasonsMetric(array $data): string
    {
        $reasons = $this->metricValue($data, 'quality.reasons');
        if (!is_array($reasons) || $reasons === []) {
            return '';
        }

        arsort($reasons, SORT_NUMERIC);
        $parts = [];
        foreach ($reasons as $reason => $count) {
            if (!is_numeric($count)) {
                continue;
            }
            $parts[] = sprintf('%s: %s', (string)$reason, $this->numberValue($count));
        }

        return implode(' / ', $parts);
    }

    private function ratioMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);
        if (!is_numeric($value)) {
            return '';
        }

        return sprintf('%.3fx', (float)$value);
    }

    private function boolMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);
        if (!is_bool($value)) {
            return '';
        }

        return $value
            ? $this->translation->_('module_local_speech_to_text_Yes')
            : $this->translation->_('module_local_speech_to_text_No');
    }

    private function timestampMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);
        if (!is_numeric($value) || (int)$value <= 0) {
            return '';
        }

        return date('Y-m-d H:i:s', (int)$value);
    }

    private function numberValue(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $number = (float)$value;
        if (floor($number) === $number) {
            return (string)(int)$number;
        }

        return rtrim(rtrim(sprintf('%.3f', $number), '0'), '.');
    }

    private function secondsValue(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        return sprintf('%s %s', $this->numberValue($value), $this->translation->_('module_local_speech_to_text_SecondsShort'));
    }

    private function percentValue(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        return sprintf('%.1f%%', (float)$value * 100);
    }

    private function normalizeLargeDecimal(mixed $value, mixed $maxReference = null): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $number = (float)$value;
        $reference = is_numeric($maxReference) ? (float)$maxReference : 0.0;
        if ($reference > 0 && $number > $reference * 1.5) {
            return $this->normalizeDecimalWithExpected($number, $reference);
        }
        if ($number > 10000 || ($reference > 0 && $number > $reference * 1.5)) {
            return $number / 1000;
        }

        return $number;
    }

    private function normalizeDecimalWithExpected(mixed $value, mixed $expected): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $number = (float)$value;
        if (!is_numeric($expected) || (float)$expected <= 0) {
            return $this->normalizeLargeDecimal($number);
        }

        $expectedNumber = (float)$expected;
        $candidates = [$number, $number / 10, $number / 100, $number / 1000];
        $best = $candidates[0];
        $bestDistance = abs($best - $expectedNumber);
        foreach ($candidates as $candidate) {
            $distance = abs($candidate - $expectedNumber);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function normalizeRatio(mixed $value, bool $allowAboveOne = false): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $number = (float)$value;
        if ($allowAboveOne) {
            return $number > 100 ? $number / 1000 : $number;
        }

        return $number > 1 ? $number / 1000 : $number;
    }

    private function metricValue(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function setMetricValue(array &$data, string $path, mixed $newValue): void
    {
        $parts = explode('.', $path);
        $value = &$data;
        $lastIndex = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if (!is_array($value)) {
                return;
            }
            if (!array_key_exists($part, $value)) {
                $value[$part] = $index === $lastIndex ? null : [];
            }
            $value = &$value[$part];
        }

        $value = $newValue;
    }

    private function sendRecordingError(int $statusCode, string $message): void
    {
        $reason = match ($statusCode) {
            403 => 'Forbidden',
            404 => 'Not Found',
            default => 'Error',
        };

        $this->response->setStatusCode($statusCode, $reason);
        $this->response->setContentType('text/plain', 'UTF-8');
        $this->response->setContent($message);
    }

    private function streamRecordingFile(string $filePath, bool $download): void
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize <= 0) {
            $this->sendRecordingError(404, 'Recording not found');
            return;
        }

        $fileHandle = fopen($filePath, 'rb');
        if ($fileHandle === false) {
            $this->sendRecordingError(403, 'Recording not readable');
            return;
        }

        $start = 0;
        $end = $fileSize - 1;
        $statusCode = 200;
        $rangeHeader = (string)$this->request->getHeader('Range');
        if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches) === 1) {
            $rangeStart = $matches[1];
            $rangeEnd = $matches[2];

            if ($rangeStart === '' && $rangeEnd === '') {
                fclose($fileHandle);
                $this->sendInvalidRecordingRange($fileSize);
                return;
            }

            if ($rangeStart === '') {
                $suffixLength = (int)$rangeEnd;
                if ($suffixLength <= 0) {
                    fclose($fileHandle);
                    $this->sendInvalidRecordingRange($fileSize);
                    return;
                }
                $start = max(0, $fileSize - $suffixLength);
            } else {
                $start = (int)$rangeStart;
                $end = $rangeEnd === '' ? $end : (int)$rangeEnd;
            }

            if ($start > $end || $start >= $fileSize) {
                fclose($fileHandle);
                $this->sendInvalidRecordingRange($fileSize);
                return;
            }

            $end = min($end, $fileSize - 1);
            $statusCode = 206;
        }

        $length = $end - $start + 1;
        $fileName = basename($filePath);
        $safeFileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'recording';
        $disposition = $download ? 'attachment' : 'inline';

        $this->response->setStatusCode($statusCode, $statusCode === 206 ? 'Partial Content' : 'OK');
        $this->response->setHeader('Content-Type', $this->recordingMimeType($filePath));
        $this->response->setHeader('Accept-Ranges', 'bytes');
        $this->response->setHeader('Content-Length', (string)$length);
        $this->response->setHeader('Content-Disposition', $disposition . '; filename="' . $safeFileName . '"');
        if ($statusCode === 206) {
            $this->response->setHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $fileSize));
        }
        $this->response->sendHeaders();

        fseek($fileHandle, $start);
        $remainingBytes = $length;
        while ($remainingBytes > 0 && !feof($fileHandle)) {
            $chunk = fread($fileHandle, min(8192, $remainingBytes));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remainingBytes -= strlen($chunk);
            flush();
            if (connection_aborted()) {
                break;
            }
        }

        fclose($fileHandle);
        exit;
    }

    private function sendInvalidRecordingRange(int $fileSize): void
    {
        $this->response->setStatusCode(416, 'Range Not Satisfiable');
        $this->response->setHeader('Content-Range', 'bytes */' . $fileSize);
        $this->response->setContentType('text/plain', 'UTF-8');
        $this->response->setContent('Invalid range');
    }

    private function recordingMimeType(string $filePath): string
    {
        return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };
    }

    private function numberWithEmployeeName(string $number): string
    {
        $number = trim($number);
        if ($number === '') {
            return '';
        }

        $extension = $this->employeeExtensionByNumber($number);
        if (!$extension instanceof Extensions) {
            return $number;
        }

        $employeeName = '';
        $user = $extension->Users;
        if ($user instanceof Users) {
            $employeeName = trim((string)$user->username);
        }
        if ($employeeName === '') {
            $employeeName = trim((string)$extension->callerid);
        }

        return $employeeName === '' ? $number : sprintf('%s (%s)', $number, $employeeName);
    }

    private function employeeExtensionByNumber(string $number): ?Extensions
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }

        $extension = Extensions::findFirst([
            'conditions' => 'number = :number: AND userid IS NOT NULL AND userid > 0',
            'bind' => [
                'number' => $number,
            ],
            'order' => 'is_general_user_number DESC, id ASC',
        ]);

        return $extension instanceof Extensions ? $extension : null;
    }

    private function cdrRecordForJob(STTJob $job): ?CallDetailRecords
    {
        $callId = trim((string)$job->call_id);
        $jobFilePath = realpath((string)$job->file_path) ?: '';
        $preferredRecord = null;

        if ($callId !== '') {
            $records = CallDetailRecords::find([
                'conditions' => '(linkedid = :call_id: OR UNIQUEID = :call_id:) AND recordingfile <> :empty:',
                'bind' => [
                    'call_id' => $callId,
                    'empty' => '',
                ],
                'order' => 'start ASC, id ASC',
            ]);

            foreach ($records as $record) {
                if (!$record instanceof CallDetailRecords) {
                    continue;
                }
                if (!$preferredRecord instanceof CallDetailRecords) {
                    $preferredRecord = $record;
                }

                $recordingPath = $this->cdrRecordingPath((string)$record->recordingfile);
                if ($jobFilePath !== '' && $recordingPath === $jobFilePath) {
                    return $record;
                }
            }
        }

        if ($preferredRecord instanceof CallDetailRecords) {
            return $preferredRecord;
        }

        return $this->cdrRecordByRecordingPath($jobFilePath);
    }

    private function cdrRecordByRecordingPath(string $jobFilePath): ?CallDetailRecords
    {
        if ($jobFilePath === '') {
            return null;
        }

        $records = CallDetailRecords::find([
            'conditions' => 'recordingfile <> :empty:',
            'bind' => [
                'empty' => '',
            ],
            'order' => 'start DESC, id DESC',
            'limit' => 500,
        ]);

        foreach ($records as $record) {
            if (!$record instanceof CallDetailRecords) {
                continue;
            }

            if ($this->cdrRecordingPath((string)$record->recordingfile) === $jobFilePath) {
                return $record;
            }
        }

        return null;
    }

    private function cdrRecordingPath(string $recordingFile): string
    {
        $recordingFile = trim($recordingFile);
        if ($recordingFile === '' || $recordingFile === '-') {
            return '';
        }

        $monitorDir = Directories::getDir(Directories::AST_MONITOR_DIR);
        $candidate = str_starts_with($recordingFile, '/')
            ? $recordingFile
            : $monitorDir . '/' . ltrim($recordingFile, '/');
        $resolvedPath = realpath($candidate);

        return $resolvedPath === false ? '' : $resolvedPath;
    }

    private function safeMonitorRecordingPath(string $filePath): string
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return '';
        }

        $allowedBase = realpath(Directories::getDir(Directories::AST_MONITOR_DIR));
        $resolvedPath = realpath($filePath);
        if ($allowedBase === false || $resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            return '';
        }

        $allowedPrefix = rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolvedPath !== $allowedBase && strncmp($resolvedPath, $allowedPrefix, strlen($allowedPrefix)) !== 0) {
            return '';
        }

        return $resolvedPath;
    }

    private function durationLabel(float $seconds): string
    {
        $totalSeconds = (int)round(max(0.0, $seconds));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $rest = $totalSeconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workerApiKeyRows(): array
    {
        $rows = [];
        $existingIds = [];
        $storedIds = STTSetting::workerApiKeyIds();

        foreach ($storedIds as $apiKeyId) {
            $apiKey = ApiKeys::findFirstById($apiKeyId);
            if (!$apiKey instanceof ApiKeys) {
                continue;
            }

            $existingIds[] = (int)$apiKey->id;
            $rows[] = [
                'id' => (int)$apiKey->id,
                'keyDisplay' => (string)$apiKey->key_display,
                'createdAtLabel' => $this->apiKeyDateLabel((string)$apiKey->created_at),
                'lastUsedAtLabel' => $this->apiKeyDateLabel((string)$apiKey->last_used_at),
                'boundWorkers' => (int)STTWorker::count([
                    'conditions' => 'api_key_id = :api_key_id:',
                    'bind' => [
                        'api_key_id' => (int)$apiKey->id,
                    ],
                ]),
            ];
        }

        if ($existingIds !== $storedIds) {
            STTSetting::setWorkerApiKeyIds($existingIds);
        }

        return $rows;
    }

    private function createWorkerApiKey(string $plainApiKey): ApiKeys
    {
        $apiKey = new ApiKeys();
        $apiKey->description = 'Local Speech To Text Worker ' . date('Y-m-d H:i:s');
        $apiKey->key_hash = password_hash($plainApiKey, PASSWORD_BCRYPT);
        $apiKey->key_suffix = substr($plainApiKey, -4);
        $apiKey->key_display = substr($plainApiKey, 0, 5) . '...' . substr($plainApiKey, -5);
        $apiKey->networkfilterid = null;
        $apiKey->full_permissions = '0';
        $apiKey->allowed_paths = json_encode([
            '/api/v3/module-local-speech-to-text/workers' => 'write',
            '/api/v3/module-local-speech-to-text/jobs' => 'write',
        ]);
        $apiKey->created_at = date('Y-m-d H:i:s');

        if (!$apiKey->save()) {
            throw new RuntimeException(implode(', ', $apiKey->getMessages()));
        }

        STTSetting::addWorkerApiKeyId((int)$apiKey->id);
        TokenValidationService::clearCache();

        return $apiKey;
    }

    private function unbindWorkersFromApiKey(int $apiKeyId): void
    {
        $workers = STTWorker::find([
            'conditions' => 'api_key_id = :api_key_id:',
            'bind' => [
                'api_key_id' => $apiKeyId,
            ],
        ]);

        foreach ($workers as $worker) {
            if (!$worker instanceof STTWorker) {
                continue;
            }

            $worker->api_key_id = null;
            if (!$worker->save()) {
                throw new RuntimeException(implode(', ', $worker->getMessages()));
            }
        }
    }

    private function apiKeyDateLabel(string $dateTime): string
    {
        $dateTime = trim($dateTime);

        return $dateTime === '' ? '-' : $dateTime;
    }

    private function workerInstallCommand(string $apiKey): string
    {
        return sprintf(
            './install.sh --pbx-url %s --token %s',
            escapeshellarg($this->trustedPbxUrl()),
            escapeshellarg($apiKey)
        );
    }

    private function trustedPbxUrl(): string
    {
        $host = LanInterfaces::getExternalHostname();
        if ($host === '') {
            $host = LanInterfaces::getExternalAddress();
        }

        $httpsPort = trim(PbxSettings::getValueByKey(PbxSettings::WEB_HTTPS_PORT));
        if ($httpsPort !== '') {
            return 'https://' . $this->hostForUrl($host) . $this->portSuffix('https', $httpsPort);
        }

        $httpPort = trim(PbxSettings::getValueByKey(PbxSettings::WEB_PORT));

        return 'http://' . $this->hostForUrl($host) . $this->portSuffix('http', $httpPort);
    }

    private function hostForUrl(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return 'localhost';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '[' . $host . ']';
        }

        return $host;
    }

    private function portSuffix(string $scheme, string $port): string
    {
        $portNumber = (int)$port;
        if (
            $portNumber <= 0
            || ($scheme === 'https' && $portNumber === 443)
            || ($scheme === 'http' && $portNumber === 80)
        ) {
            return '';
        }

        return ':' . $portNumber;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queueSummaryRows(
        int $pendingCount,
        int $processingCount,
        int $doneTodayCount,
        int $errorCount,
        array $delayedRecordings,
        array $skippedRecordings
    ): array {
        return [
            [
                'icon' => 'clock outline',
                'label' => $this->translation->_('module_local_speech_to_text_Pending'),
                'labelClass' => 'blue',
                'count' => $pendingCount,
                'lastEventLabel' => $this->formatTimestamp($this->latestJobTimestamp(STTJob::STATUS_PENDING, 'created_at')),
            ],
            [
                'icon' => 'spinner',
                'label' => $this->translation->_('module_local_speech_to_text_Processing'),
                'labelClass' => 'orange',
                'count' => $processingCount,
                'lastEventLabel' => $this->formatTimestamp($this->latestJobTimestamp(STTJob::STATUS_PROCESSING, 'started_at')),
            ],
            [
                'icon' => 'check circle outline',
                'label' => $this->translation->_('module_local_speech_to_text_DoneToday'),
                'labelClass' => 'green',
                'count' => $doneTodayCount,
                'lastEventLabel' => $this->formatTimestamp(
                    $this->latestJobTimestamp(STTJob::STATUS_DONE, 'finished_at', strtotime('today'))
                ),
            ],
            [
                'icon' => 'exclamation triangle',
                'label' => $this->translation->_('module_local_speech_to_text_Errors'),
                'labelClass' => 'red',
                'count' => $errorCount,
                'lastEventLabel' => $this->formatTimestamp($this->latestJobTimestamp(STTJob::STATUS_ERROR, 'finished_at')),
            ],
            [
                'icon' => 'hourglass half',
                'label' => $this->translation->_('module_local_speech_to_text_DelayedRecordings'),
                'labelClass' => 'yellow',
                'count' => count($delayedRecordings),
                'lastEventLabel' => $this->formatTimestamp($this->latestStoredRecordTimestamp($delayedRecordings, 'last_seen')),
            ],
            [
                'icon' => 'ban',
                'label' => $this->translation->_('module_local_speech_to_text_SkippedRecordings'),
                'labelClass' => 'grey',
                'count' => count($skippedRecordings),
                'lastEventLabel' => $this->formatTimestamp($this->latestStoredRecordTimestamp($skippedRecordings, 'created_at')),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<int, array<string, mixed>> $skippedRecordings
     * @return array<int, array<string, mixed>>
     */
    private function queueFileRows(array $delayedRecordings, array $skippedRecordings): array
    {
        $rows = [];
        $jobs = STTJob::find([
            'conditions' => 'status = :pending: OR status = :processing: OR status = :error:',
            'bind' => [
                'pending' => STTJob::STATUS_PENDING,
                'processing' => STTJob::STATUS_PROCESSING,
                'error' => STTJob::STATUS_ERROR,
            ],
            'order' => 'id ASC',
            'limit' => 100,
        ]);

        foreach ($jobs as $job) {
            if (!$job instanceof STTJob) {
                continue;
            }

            $status = $this->queueJobStatusPresentation((string)$job->status);
            $filePath = (string)$job->file_path;
            $worker = $job->STTWorker;
            $updatedTimestamp = $this->queueJobUpdatedTimestamp($job);
            $recordingTimestamp = $this->queueRecordingTimestamp(
                (string)$job->call_id,
                $filePath,
                (int)$job->created_at
            );

            $rows[] = [
                'sourceRank' => 10,
                'sourceId' => (int)$job->id,
                'recordingDateLabel' => $this->queueDateLabel($recordingTimestamp),
                'recordingTimeLabel' => $this->queueTimeLabel($recordingTimestamp),
                'icon' => $status['icon'],
                'labelClass' => $status['labelClass'],
                'statusLabel' => $status['label'],
                'sourceLabel' => $this->translation->_('module_local_speech_to_text_Job') . ' #' . (int)$job->id,
                'callId' => (string)$job->call_id,
                'workerLabel' => $worker instanceof STTWorker && trim((string)$worker->name) !== ''
                    ? (string)$worker->name
                    : $this->translation->_('module_local_speech_to_text_WorkerUnassigned'),
                'attemptsLabel' => (string)(int)$job->attempts,
                'createdAtLabel' => $this->formatTimestamp((int)$job->created_at),
                'updatedAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedTimestamp' => $updatedTimestamp,
            ];
        }

        foreach ($delayedRecordings as $recordId => $record) {
            $recordingFile = (string)($record['recordingfile'] ?? '');
            $updatedTimestamp = (int)($record['last_seen'] ?? $record['first_seen'] ?? 0);
            $recordingTimestamp = $this->queueRecordingTimestamp(
                (string)($record['call_id'] ?? ''),
                $recordingFile,
                (int)($record['first_seen'] ?? 0)
            );
            $rows[] = [
                'sourceRank' => 20,
                'sourceId' => (int)$recordId,
                'recordingDateLabel' => $this->queueDateLabel($recordingTimestamp),
                'recordingTimeLabel' => $this->queueTimeLabel($recordingTimestamp),
                'icon' => 'hourglass half',
                'labelClass' => 'yellow',
                'statusLabel' => $this->translation->_('module_local_speech_to_text_DelayedRecordings'),
                'sourceLabel' => $this->translation->_('module_local_speech_to_text_CdrRecord') . ' #' . (int)$recordId,
                'callId' => (string)($record['call_id'] ?? ''),
                'workerLabel' => '',
                'attemptsLabel' => '',
                'createdAtLabel' => $this->formatTimestamp((int)($record['first_seen'] ?? 0)),
                'updatedAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedTimestamp' => $updatedTimestamp,
            ];
        }

        foreach ($skippedRecordings as $recordId => $record) {
            $recordingPath = (string)($record['recording_path'] ?? '');
            $recordingFile = $recordingPath !== '' ? $recordingPath : (string)($record['recordingfile'] ?? '');
            $updatedTimestamp = (int)($record['created_at'] ?? 0);
            $recordingTimestamp = $this->queueRecordingTimestamp(
                (string)($record['call_id'] ?? ''),
                $recordingFile,
                $updatedTimestamp
            );
            $rows[] = [
                'sourceRank' => 30,
                'sourceId' => (int)$recordId,
                'recordingDateLabel' => $this->queueDateLabel($recordingTimestamp),
                'recordingTimeLabel' => $this->queueTimeLabel($recordingTimestamp),
                'icon' => 'ban',
                'labelClass' => 'grey',
                'statusLabel' => $this->translation->_('module_local_speech_to_text_SkippedRecordings'),
                'sourceLabel' => $this->translation->_('module_local_speech_to_text_CdrRecord') . ' #' . (int)$recordId,
                'callId' => (string)($record['call_id'] ?? ''),
                'workerLabel' => '',
                'attemptsLabel' => '',
                'createdAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedTimestamp' => $updatedTimestamp,
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $sourceRank = ((int)$left['sourceRank']) <=> ((int)$right['sourceRank']);
                if ($sourceRank !== 0) {
                    return $sourceRank;
                }

                return ((int)$left['sourceId']) <=> ((int)$right['sourceId']);
            }
        );

        return $rows;
    }

    private function latestJobTimestamp(string $status, string $column, int $since = 0): int
    {
        $conditions = "status = :status: AND {$column} IS NOT NULL AND {$column} <> :empty:";
        $bind = [
            'status' => $status,
            'empty' => '',
        ];
        if ($since > 0) {
            $conditions .= " AND {$column} >= :since:";
            $bind['since'] = (string)$since;
        }

        $job = STTJob::findFirst([
            'conditions' => $conditions,
            'bind' => $bind,
            'order' => "{$column} DESC, id DESC",
        ]);
        if (!$job instanceof STTJob) {
            return 0;
        }

        return (int)$job->{$column};
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function latestStoredRecordTimestamp(array $records, string $field): int
    {
        $latest = 0;
        foreach ($records as $record) {
            $latest = max($latest, (int)($record[$field] ?? 0));
        }

        return $latest;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedRecords(string $settingKey): array
    {
        $records = json_decode(STTSetting::getValue($settingKey, '[]'), true);
        if (!is_array($records)) {
            return [];
        }

        $normalized = [];
        foreach ($records as $recordId => $record) {
            $recordId = (int)$recordId;
            if ($recordId <= 0 || !is_array($record)) {
                continue;
            }

            $normalized[$recordId] = $record;
        }

        return $normalized;
    }

    /**
     * @return array{icon: string, labelClass: string, label: string}
     */
    private function queueJobStatusPresentation(string $status): array
    {
        return match ($status) {
            STTJob::STATUS_PROCESSING => [
                'icon' => 'spinner',
                'labelClass' => 'orange',
                'label' => $this->translation->_('module_local_speech_to_text_Processing'),
            ],
            STTJob::STATUS_ERROR => [
                'icon' => 'exclamation triangle',
                'labelClass' => 'red',
                'label' => $this->translation->_('module_local_speech_to_text_Errors'),
            ],
            default => [
                'icon' => 'clock outline',
                'labelClass' => 'blue',
                'label' => $this->translation->_('module_local_speech_to_text_Pending'),
            ],
        };
    }

    private function queueJobUpdatedTimestamp(STTJob $job): int
    {
        if ((string)$job->status === STTJob::STATUS_PROCESSING) {
            return (int)$job->started_at;
        }
        if ((string)$job->status === STTJob::STATUS_ERROR) {
            return (int)$job->finished_at;
        }

        return (int)$job->created_at;
    }

    private function queueRecordingTimestamp(string $callId, string $filePath, int $fallbackTimestamp): int
    {
        $cdrTimestamp = $this->cdrCallStartTimestamp($callId);
        if ($cdrTimestamp > 0) {
            return $cdrTimestamp;
        }

        $recordingTimestamp = $this->recordingTimestamp($filePath);
        if ($recordingTimestamp > 0) {
            return $recordingTimestamp;
        }

        return max(0, $fallbackTimestamp);
    }

    private function queueDateLabel(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d', $timestamp) : '';
    }

    private function queueTimeLabel(int $timestamp): string
    {
        return $timestamp > 0 ? date('H:i:s', $timestamp) : '';
    }

    private function formatTimestamp(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     * @return array<int, array<string, mixed>>
     */
    private function transcriptRows(int $limit = 100, ?array $dateFilter = null): array
    {
        $rows = [];
        $findParams = [
            'order' => 'created_at DESC, id DESC',
        ];
        if (!($dateFilter['isActive'] ?? false)) {
            $findParams['limit'] = $limit;
        }

        $results = STTResult::find($findParams);

        foreach ($results as $result) {
            if (!$result instanceof STTResult) {
                continue;
            }

            $job = $result->STTJob;
            $job = $job instanceof STTJob ? $job : null;
            $callTimestamp = $this->callTimestamp($result, $job);
            if (!$this->transcriptMatchesDateFilter($callTimestamp, $dateFilter)) {
                continue;
            }

            $hasCallTimestamp = $callTimestamp > 0;
            $filePath = $job instanceof STTJob ? (string)$job->file_path : '';
            $createdAt = (int)$result->created_at;

            $rows[] = [
                'result' => $result,
                'job' => $job,
                'callId' => $job instanceof STTJob ? (string)$job->call_id : '',
                'recordingName' => $filePath === '' ? '' : basename($filePath),
                'callTimestamp' => $callTimestamp,
                'dateKey' => $hasCallTimestamp ? date('Y-m-d', $callTimestamp) : 'unknown',
                'dateGroupLabel' => $hasCallTimestamp
                    ? date('Y-m-d', $callTimestamp)
                    : $this->translation->_('module_local_speech_to_text_CallDateUnknown'),
                'callDateLabel' => $hasCallTimestamp
                    ? date('Y-m-d', $callTimestamp)
                    : $this->translation->_('module_local_speech_to_text_CallDateUnknown'),
                'callTimeLabel' => $hasCallTimestamp ? date('H:i:s', $callTimestamp) : '',
                'createdAtLabel' => $createdAt > 0 ? date('Y-m-d H:i:s', $createdAt) : '',
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [
                $right['callTimestamp'],
                (int)$right['result']->id,
            ] <=> [
                $left['callTimestamp'],
                (int)$left['result']->id,
            ]
        );

        return $rows;
    }

    /**
     * @return array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}
     */
    private function transcriptDateFilterFromRequest(): array
    {
        $dateFrom = $this->normalizeDateQuery((string)$this->request->getQuery('date_from', 'string', ''));
        $dateTo = $this->normalizeDateQuery((string)$this->request->getQuery('date_to', 'string', ''));

        if ($dateFrom === '' && $dateTo === '') {
            $todayStart = strtotime('today 00:00:00');
            $todayStart = $todayStart === false ? time() : $todayStart;
            $start = strtotime('-6 days', $todayStart);
            $start = $start === false ? $todayStart : $start;
            $end = strtotime('today 23:59:59');
            $end = $end === false ? $todayStart : $end;

            return [
                'dateFrom' => date('Y-m-d', $start),
                'dateTo' => date('Y-m-d', $end),
                'start' => $start,
                'end' => $end,
                'isActive' => true,
            ];
        }

        if ($dateFrom === '') {
            $dateFrom = $dateTo;
        }
        if ($dateTo === '') {
            $dateTo = $dateFrom;
        }

        $start = strtotime($dateFrom . ' 00:00:00');
        $end = strtotime($dateTo . ' 23:59:59');
        if ($start === false || $end === false) {
            return [
                'dateFrom' => '',
                'dateTo' => '',
                'start' => 0,
                'end' => 0,
                'isActive' => false,
            ];
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'start' => $start,
            'end' => $end,
            'isActive' => true,
        ];
    }

    private function normalizeDateQuery(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return '';
        }

        $timestamp = strtotime($date . ' 00:00:00');

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     */
    private function transcriptMatchesDateFilter(int $callTimestamp, ?array $dateFilter): bool
    {
        if (!($dateFilter['isActive'] ?? false)) {
            return true;
        }

        return $callTimestamp > 0
            && $callTimestamp >= (int)$dateFilter['start']
            && $callTimestamp <= (int)$dateFilter['end'];
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool} $dateFilter
     * @return array<string, mixed>
     */
    private function transcriptFilterView(array $dateFilter): array
    {
        $dateFrom = $dateFilter['dateFrom'];
        $dateTo = $dateFilter['dateTo'];

        $displayRange = '';
        if ($dateFrom !== '' && $dateTo !== '') {
            $fromTimestamp = strtotime($dateFrom . ' 00:00:00');
            $toTimestamp = strtotime($dateTo . ' 00:00:00');
            if ($fromTimestamp !== false && $toTimestamp !== false) {
                $displayRange = date('d/m/Y', $fromTimestamp) . ' - ' . date('d/m/Y', $toTimestamp);
            }
        }

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'displayRange' => $displayRange,
            'isActive' => $dateFilter['isActive'],
        ];
    }

    private function callTimestamp(STTResult $result, ?STTJob $job): int
    {
        if ($job instanceof STTJob) {
            $cdrTimestamp = $this->cdrCallStartTimestamp((string)$job->call_id);
            if ($cdrTimestamp > 0) {
                return $cdrTimestamp;
            }

            $recordingTimestamp = $this->recordingTimestamp((string)$job->file_path);
            if ($recordingTimestamp > 0) {
                return $recordingTimestamp;
            }

            $jobTimestamp = (int)$job->created_at;
            if ($jobTimestamp > 0) {
                return $jobTimestamp;
            }
        }

        return (int)$result->created_at;
    }

    private function cdrCallStartTimestamp(string $callId): int
    {
        $callId = trim($callId);
        if ($callId === '') {
            return 0;
        }

        $record = CallDetailRecords::findFirst([
            'conditions' => '(linkedid = :call_id: OR UNIQUEID = :call_id:) AND start <> :empty:',
            'bind' => [
                'call_id' => $callId,
                'empty' => '',
            ],
            'order' => 'start ASC, id ASC',
        ]);
        if (!$record instanceof CallDetailRecords) {
            return 0;
        }

        $timestamp = strtotime((string)$record->start);

        return $timestamp === false ? 0 : $timestamp;
    }

    private function recordingTimestamp(string $filePath): int
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return 0;
        }

        $baseName = basename($filePath);
        if (preg_match('/(20\d{2})([01]\d)([0-3]\d)[-_ ]?([0-2]\d)([0-5]\d)([0-5]\d)/', $baseName, $matches) === 1) {
            $timestamp = strtotime(sprintf(
                '%s-%s-%s %s:%s:%s',
                $matches[1],
                $matches[2],
                $matches[3],
                $matches[4],
                $matches[5],
                $matches[6]
            ));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        if (preg_match('~/((?:20)\d{2})/([01]\d)/([0-3]\d)(?:/|$)~', $filePath, $matches) === 1) {
            $timestamp = strtotime(sprintf('%s-%s-%s 00:00:00', $matches[1], $matches[2], $matches[3]));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        $fileTimestamp = @filemtime($filePath);

        return $fileTimestamp === false ? 0 : $fileTimestamp;
    }

    /**
     * @return array{logText: string, lineCount: int, fileCount: int}
     */
    private function moduleLogsPayload(int $sinceTimestamp): array
    {
        $entries = [];
        foreach ($this->moduleLogSources() as $source) {
            $sourceTimestamp = @filemtime($source['path']);
            $lastTimestamp = is_int($sourceTimestamp) ? $sourceTimestamp : 0;
            $lineNumber = 0;

            foreach ($this->tailLines($source['path'], self::MODULE_LOG_TAIL_LINES_PER_FILE) as $line) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                if ($source['filterModuleLines'] && !$this->isModuleSystemLogLine($line)) {
                    continue;
                }

                $timestamp = $this->parseLogTimestamp($line);
                if ($timestamp !== 0) {
                    $lastTimestamp = $timestamp;
                } else {
                    $timestamp = $lastTimestamp;
                }

                if ($timestamp !== 0 && $timestamp < $sinceTimestamp) {
                    continue;
                }

                $entries[] = [
                    'timestamp' => $timestamp,
                    'source' => $source['label'],
                    'lineNumber' => $lineNumber,
                    'line' => $line,
                ];
            }
        }

        usort($entries, static function (array $left, array $right): int {
            return [
                (int)$left['timestamp'],
                (string)$left['source'],
                (int)$left['lineNumber'],
            ] <=> [
                (int)$right['timestamp'],
                (string)$right['source'],
                (int)$right['lineNumber'],
            ];
        });

        if (count($entries) > self::MODULE_LOG_MAX_RESPONSE_LINES) {
            $entries = array_slice($entries, -self::MODULE_LOG_MAX_RESPONSE_LINES);
        }

        $lines = array_map(
            static fn (array $entry): string => sprintf('[%s] %s', $entry['source'], $entry['line']),
            $entries
        );

        return [
            'logText' => implode("\n", $lines),
            'lineCount' => count($lines),
            'fileCount' => count($this->moduleLogSources()),
        ];
    }

    /**
     * @return array<int, array{path: string, label: string, filterModuleLines: bool}>
     */
    private function moduleLogSources(): array
    {
        $coreLogsDir = rtrim(Directories::getDir(Directories::CORE_LOGS_DIR), '/');
        $sources = [];
        $seen = [];
        $moduleLogDir = $coreLogsDir . '/' . self::MODULE_UNIQUE_ID;

        if (is_dir($moduleLogDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($moduleLogDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile() || !$fileInfo->isReadable()) {
                    continue;
                }
                if (preg_match('/\.log(?:\.\d+)?$/', $fileInfo->getFilename()) !== 1) {
                    continue;
                }

                $realPath = $fileInfo->getRealPath();
                if ($realPath === false || isset($seen[$realPath])) {
                    continue;
                }

                $seen[$realPath] = true;
                $relativePath = ltrim(str_replace($moduleLogDir, '', $realPath), '/');
                $sources[] = [
                    'path' => $realPath,
                    'label' => self::MODULE_UNIQUE_ID . '/' . $relativePath,
                    'filterModuleLines' => false,
                ];
            }
        }

        foreach ([$coreLogsDir . '/system/messages', $coreLogsDir . '/messages'] as $systemLogPath) {
            $realPath = realpath($systemLogPath);
            if ($realPath === false || isset($seen[$realPath]) || !is_file($realPath) || !is_readable($realPath)) {
                continue;
            }

            $seen[$realPath] = true;
            $sources[] = [
                'path' => $realPath,
                'label' => str_replace($coreLogsDir . '/', '', $realPath),
                'filterModuleLines' => true,
            ];
        }

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    private function tailLines(string $filePath, int $maxLines): array
    {
        $fileSize = @filesize($filePath);
        if (!is_int($fileSize) || $fileSize <= 0) {
            return [];
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return [];
        }

        $chunkSize = 65536;
        $position = $fileSize;
        $buffer = '';

        while ($position > 0 && substr_count($buffer, "\n") <= $maxLines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            if (fseek($handle, $position) !== 0) {
                break;
            }

            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            if (strlen($buffer) > 8 * 1024 * 1024) {
                break;
            }
        }

        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $buffer);
        if (!is_array($lines)) {
            return [];
        }

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_slice($lines, -$maxLines);
    }

    private function isModuleSystemLogLine(string $line): bool
    {
        return stripos($line, self::MODULE_UNIQUE_ID) !== false
            || stripos($line, 'LocalSpeechToText') !== false
            || stripos($line, 'local-speech-to-text') !== false;
    }

    private function parseLogTimestamp(string $line): int
    {
        $patterns = [
            '/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/' => 'Y-m-d H:i:s',
            '/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/' => 'Y-m-d H:i:s',
            '/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})/' => 'Y/m/d H:i:s',
            '/^\[(\d{1,2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\s+[^\]]+\]/' => 'd-M-Y H:i:s',
            '/^\[[A-Za-z]{3},\s+(\d{1,2}\s+[A-Za-z]{3}\s+\d{2}\s+\d{2}:\d{2}:\d{2}\s+[+-]\d{4})\]/' => 'd M y H:i:s O',
        ];

        foreach ($patterns as $pattern => $format) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }

            $timestamp = strtotime($matches[1]);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        if (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+/', $line, $matches) === 1) {
            $timestamp = strtotime($matches[1] . ' ' . date('Y'));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return 0;
    }

    private function activeTabFromRequest(string $generatedApiKey): string
    {
        if ($generatedApiKey !== '') {
            return 'workers';
        }

        $tab = (string)$this->request->getQuery('tab', 'string', 'settings');

        return in_array($tab, ['settings', 'queue', 'workers', 'transcripts', 'logs'], true) ? $tab : 'settings';
    }

}
