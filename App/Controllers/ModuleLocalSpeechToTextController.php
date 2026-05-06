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

        $this->view->transcriptsPath = MenuProvider::DASHBOARD_PATH . '?tab=transcripts';
        $this->view->result = $result;
        $this->view->conversation = $this->conversationFromTranscript((string)$result->transcript);
        $job = $result->STTJob;
        $this->view->recording = $this->recordingCard($result, $job instanceof STTJob ? $job : null);

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

    /**
     * @return array<string, mixed>
     */
    private function conversationFromTranscript(string $transcript): array
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

            $start = $this->floatValue($segment['start'] ?? null);
            $end = $this->floatValue($segment['end'] ?? null);
            $utterance = [
                'text' => $text,
                'speakerKey' => $speakerKey,
                'speakerLabel' => $speakers[$speakerKey]['label'],
                'start' => $start,
                'end' => $end,
                'startLabel' => $this->timeLabel($start),
                'rangeLabel' => $this->rangeLabel($start, $end),
            ];
            $speakers[$speakerKey]['segments'][] = $utterance;
            $timeline[] = $utterance;
        }

        foreach ($speakers as &$speaker) {
            usort(
                $speaker['segments'],
                static fn (array $left, array $right): int => ($left['start'] <=> $right['start'])
            );
        }
        unset($speaker);

        usort(
            $timeline,
            static fn (array $left, array $right): int => [$left['start'], $left['end']] <=> [$right['start'], $right['end']]
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
    private function recordingCard(STTResult $result, ?STTJob $job): array
    {
        $cdr = $job instanceof STTJob ? $this->cdrRecordForJob($job) : null;
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
        $duration = (float)($result->duration ?? 0);
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

    private function activeTabFromRequest(string $generatedApiKey): string
    {
        if ($generatedApiKey !== '') {
            return 'workers';
        }

        $tab = (string)$this->request->getQuery('tab', 'string', 'settings');

        return in_array($tab, ['settings', 'queue', 'workers', 'transcripts'], true) ? $tab : 'settings';
    }

}
