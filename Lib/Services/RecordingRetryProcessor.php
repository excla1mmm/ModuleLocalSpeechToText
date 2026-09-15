<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * Retry orchestration for delayed (recording not readable yet) and skipped
 * (permanently ineligible until conditions change) CDR recordings, driven
 * off RetryStateStore's due-schedule.
 *
 * Extracted verbatim from bin/WorkerLocalSpeechToTextJobs.php (moved, not
 * rewritten). $needRestart / $iterationBudgetExhausted are supplied as
 * closures by the worker so this class observes the exact same live,
 * per-iteration flags the worker itself checks, without owning them.
 */
final class RecordingRetryProcessor
{
    // Durable delayed/skipped retry state lives in the module DB, not the core CDR
    // table; it gets its own small cap so ffprobe/filesystem retries can't spike load.
    public const int RETRY_DUE_BATCH_LIMIT = 10;
    private const int DELAYED_RECORDING_TTL_SECONDS = 86400;
    private const int MAX_SKIPPED_RETRY_SECONDS = 3600;

    public function __construct(
        private readonly CdrRecordEvaluator $evaluator,
        private readonly RecordingRetryCache $retryCache,
        private readonly int $scanIntervalSeconds
    ) {
    }

    public function retryDelayedRecordings(Closure $needRestart, Closure $iterationBudgetExhausted): void
    {
        $retryRecords = $this->retryCache->retryStateStore()->due(
            RetryStateStore::TYPE_DELAYED,
            self::RETRY_DUE_BATCH_LIMIT
        );
        if ($retryRecords === []) {
            return;
        }

        $delayedRecordings = $this->retryCache->delayedRecordings();
        $changed = false;
        $now = time();
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        foreach ($retryRecords as $retryRecord) {
            if ($iterationBudgetExhausted()) {
                if ($changed) {
                    $this->retryCache->saveDelayedRecordings($delayedRecordings);
                }
                return;
            }
            $recordId = (int)$retryRecord['cdr_id'];
            $record = $retryRecord['payload'];
            $record['first_seen'] = (int)$retryRecord['first_seen'];
            if ($needRestart()) {
                if ($changed) {
                    $this->retryCache->saveDelayedRecordings($delayedRecordings);
                }
                return;
            }

            if (!$this->evaluator->recordWithinHistoryWindow($record, $historyStart)) {
                $this->retryCache->rememberSkippedRecording($record, 'outside_history_window', '', (int)$recordId);
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            if ($now - (int)($record['first_seen'] ?? $now) > self::DELAYED_RECORDING_TTL_SECONDS) {
                $this->retryCache->rememberSkippedRecording($record, 'recording_missing_timeout', '', (int)$recordId);
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            $recordingPath = $this->evaluator->recordingPath((string)($record['recordingfile'] ?? ''));
            if ($recordingPath === null) {
                $this->retryCache->retryStateStore()->defer(
                    $recordId,
                    RetryStateStore::TYPE_DELAYED,
                    $this->scanIntervalSeconds
                );
                continue;
            }

            if (!$this->evaluator->recordingWithinSizeLimit($recordingPath)) {
                $this->retryCache->rememberSkippedRecording($record, 'file_too_large', $recordingPath, (int)$recordId);
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            $duration = $this->evaluator->recordingDurationDecision($record, $recordingPath);
            if ($duration['state'] !== 'allowed') {
                $record = $this->evaluator->recordWithDurationDecision($record, $duration);
                $this->retryCache->rememberSkippedRecording(
                    $record,
                    $duration['state'] === 'too_long' ? 'duration_too_long' : 'duration_probe_failed',
                    $recordingPath,
                    (int)$recordId
                );
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            if (!STTJob::existsForFilePath($recordingPath)) {
                $job = STTJob::createPending(
                    (string)($record['call_id'] ?? ''),
                    $recordingPath,
                    $this->evaluator->priorityForDuration($duration['duration_sec']),
                    (int)($record['cdr_endtime'] ?? 0) ?: null,
                    $this->evaluator->recordingIdentity($record, $recordingPath),
                    $duration['duration_sec']
                );
                $this->evaluator->logJobCreated(
                    $job,
                    $duration['duration_sec'],
                    'delayed_recording_recovered'
                );
            }

            unset($delayedRecordings[$recordId]);
            $this->retryCache->retryStateStore()->remove($recordId);
            $changed = true;
        }

        if ($changed) {
            $this->retryCache->saveDelayedRecordings($delayedRecordings);
        }
    }

    public function retrySkippedRecordings(Closure $needRestart, Closure $iterationBudgetExhausted): void
    {
        $retryRecords = $this->retryCache->retryStateStore()->due(
            RetryStateStore::TYPE_SKIPPED,
            self::RETRY_DUE_BATCH_LIMIT
        );
        if ($retryRecords === []) {
            return;
        }

        $skippedRecordings = $this->retryCache->skippedRecordings();
        $changed = false;
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        foreach ($retryRecords as $retryRecord) {
            if ($iterationBudgetExhausted()) {
                if ($changed) {
                    $this->retryCache->saveSkippedRecordings($skippedRecordings);
                }
                return;
            }
            $recordId = (int)$retryRecord['cdr_id'];
            $record = $retryRecord['payload'];
            if ($needRestart()) {
                if ($changed) {
                    $this->retryCache->saveSkippedRecordings($skippedRecordings);
                }
                return;
            }

            if (!$this->evaluator->recordWithinHistoryWindow($record, $historyStart)) {
                unset($skippedRecordings[$recordId]);
                $this->retryCache->retryStateStore()->remove($recordId);
                $changed = true;
                LocalSpeechToTextLogger::event(
                    'scanner',
                    'recording_retry_dropped',
                    'Recording retry state removed',
                    LOG_INFO,
                    ['skip_reason' => 'outside_history_window']
                );
                continue;
            }

            $recordingFile = trim((string)($record['recordingfile'] ?? ''));
            if ($recordingFile === '') {
                $recordingFile = (string)($record['recording_path'] ?? '');
            }
            $recordingPath = $this->evaluator->recordingPath($recordingFile);
            if ($recordingPath === null) {
                if ($this->retryCache->expireSkippedRetry($skippedRecordings, $retryRecord)) {
                    $changed = true;
                    continue;
                }
                $this->retryCache->retryStateStore()->deferWithBackoff(
                    $recordId,
                    RetryStateStore::TYPE_SKIPPED,
                    $this->scanIntervalSeconds,
                    self::MAX_SKIPPED_RETRY_SECONDS
                );
                continue;
            }

            if (!$this->evaluator->recordingWithinSizeLimit($recordingPath)) {
                if ($this->retryCache->expireSkippedRetry($skippedRecordings, $retryRecord)) {
                    $changed = true;
                    continue;
                }
                $this->retryCache->retryStateStore()->deferWithBackoff(
                    $recordId,
                    RetryStateStore::TYPE_SKIPPED,
                    $this->scanIntervalSeconds,
                    self::MAX_SKIPPED_RETRY_SECONDS
                );
                continue;
            }

            $duration = $this->evaluator->recordingDurationDecision($record, $recordingPath);
            if ($duration['state'] !== 'allowed') {
                $reason = $duration['state'] === 'too_long' ? 'duration_too_long' : 'duration_probe_failed';
                if (
                    $reason !== (string)$retryRecord['reason']
                    || $duration['source'] !== trim((string)($record['recording_duration_source'] ?? ''))
                    || $duration['duration_sec'] !== $this->evaluator->recordingDurationSeconds($record)
                ) {
                    $record = $this->evaluator->recordWithDurationDecision($record, $duration);
                    $this->retryCache->rememberSkippedRecording($record, $reason, $recordingPath, $recordId);
                }
                $retryRecord['reason'] = $reason;
                if ($this->retryCache->expireSkippedRetry($skippedRecordings, $retryRecord)) {
                    $changed = true;
                    continue;
                }
                $this->retryCache->retryStateStore()->deferWithBackoff(
                    $recordId,
                    RetryStateStore::TYPE_SKIPPED,
                    $this->scanIntervalSeconds,
                    self::MAX_SKIPPED_RETRY_SECONDS
                );
                continue;
            }

            if (!STTJob::existsForFilePath($recordingPath)) {
                $job = STTJob::createPending(
                    (string)($record['call_id'] ?? ''),
                    $recordingPath,
                    $this->evaluator->priorityForDuration($duration['duration_sec']),
                    (int)($record['cdr_endtime'] ?? 0) ?: null,
                    $this->evaluator->recordingIdentity($record, $recordingPath),
                    $duration['duration_sec']
                );
                $this->evaluator->logJobCreated(
                    $job,
                    $duration['duration_sec'],
                    'skipped_recording_recovered'
                );
            }

            unset($skippedRecordings[$recordId]);
            $this->retryCache->retryStateStore()->remove($recordId);
            $changed = true;
        }

        if ($changed) {
            $this->retryCache->saveSkippedRecordings($skippedRecordings);
        }
    }
}
