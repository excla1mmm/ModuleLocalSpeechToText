<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * Storage for the delayed/skipped recording display caches (STTSetting JSON
 * blobs) and the durable RetryStateStore records that back them.
 *
 * Extracted verbatim from bin/WorkerLocalSpeechToTextJobs.php (moved, not
 * rewritten).
 */
final class RecordingRetryCache
{
    private const int MAX_DELAYED_RECORDINGS = 500;
    private const int MAX_SKIPPED_RECORDINGS = 100;
    private const int MAX_SKIPPED_RETRY_AGE_SECONDS = 2592000;

    private ?RetryStateStore $retryStateStore = null;

    public function __construct(private readonly CdrRecordEvaluator $evaluator)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function delayedRecordings(): array
    {
        $json = STTSetting::getValue(STTSetting::DELAYED_RECORDINGS, '[]');
        $records = json_decode($json, true);
        if (!is_array($records)) {
            return [];
        }

        $delayedRecordings = [];
        foreach ($records as $recordId => $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordId = (int)$recordId;
            if ($recordId <= 0 || trim((string)($record['recordingfile'] ?? '')) === '') {
                continue;
            }

            $delayedRecordings[$recordId] = $record;
        }

        return $delayedRecordings;
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<string, mixed> $record
     */
    public function rememberDelayedRecording(array &$delayedRecordings, array $record): void
    {
        $recordId = $this->evaluator->recordId($record);
        if ($recordId <= 0) {
            return;
        }

        $isNew = !isset($delayedRecordings[$recordId]);
        $now = time();
        $retryRecord = [
            'recordingfile' => (string)($record['recordingfile'] ?? ''),
            'call_id' => $this->evaluator->callId($record),
            'priority' => $this->evaluator->priority($record),
            'endtime' => $this->evaluator->endtime($record),
            'cdr_endtime' => $this->evaluator->recordEndtimeTimestamp($record),
            'cdr_id' => $this->evaluator->recordId($record),
            'cdr_uniqueid' => trim((string)($record['UNIQUEID'] ?? '')),
            'cdr_start_ms' => $this->evaluator->dateTimeMilliseconds((string)($record['start'] ?? '')),
            'cdr_end_ms' => $this->evaluator->dateTimeMilliseconds((string)($record['endtime'] ?? '')),
            'recording_duration_sec' => $this->evaluator->recordingDurationSeconds($record),
            'recording_duration_source' => trim((string)($record['recording_duration_source'] ?? '')),
            'first_seen' => (int)($delayedRecordings[$recordId]['first_seen'] ?? $now),
            'last_seen' => $now,
        ];
        $delayedRecordings[$recordId] = $retryRecord;
        $this->retryStateStore()->remember(
            $recordId,
            RetryStateStore::TYPE_DELAYED,
            $retryRecord,
            'recording_missing',
            $now
        );
        if ($isNew) {
            LocalSpeechToTextLogger::event(
                'scanner',
                'recording_delayed',
                'Recording is not available yet; retry scheduled',
                LOG_INFO,
                [
                    'skip_reason' => 'recording_missing',
                    'recording_duration_ms' => $this->evaluator->recordingDurationSeconds($record) !== null
                        ? $this->evaluator->recordingDurationSeconds($record) * 1000
                        : null,
                ]
            );
        }

        if (count($delayedRecordings) > self::MAX_DELAYED_RECORDINGS) {
            uasort(
                $delayedRecordings,
                static fn (array $left, array $right): int => ((int)($left['first_seen'] ?? 0)) <=> ((int)($right['first_seen'] ?? 0))
            );
            $delayedRecordings = array_slice($delayedRecordings, -self::MAX_DELAYED_RECORDINGS, null, true);
            LocalSpeechToTextLogger::event(
                'scanner',
                'retry_cache_overflow',
                'Recording retry display cache reached its limit',
                LOG_WARNING,
                ['cache_type' => 'delayed']
            );
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    public function rememberSkippedRecording(array $record, string $reason, string $recordingPath = '', int $recordId = 0): void
    {
        $recordId = $recordId > 0 ? $recordId : $this->evaluator->recordId($record);
        if ($recordId <= 0) {
            return;
        }

        $recordingFile = trim((string)($record['recordingfile'] ?? ''));
        if ($recordingFile === '') {
            $recordingFile = $recordingPath;
        }

        $cdrEndtime = (int)($record['cdr_endtime'] ?? 0);
        if ($cdrEndtime <= 0) {
            $cdrEndtime = (int)($this->evaluator->recordEndtimeTimestamp($record) ?? 0);
        }

        $skippedRecordings = $this->skippedRecordings();
        $isNew = !isset($skippedRecordings[$recordId]);
        $retryRecord = [
            'recordingfile' => $recordingFile,
            'recording_path' => $recordingPath,
            'call_id' => (string)($record['call_id'] ?? $this->evaluator->callId($record)),
            'priority' => (int)($record['priority'] ?? $this->evaluator->priority($record)),
            'endtime' => $this->evaluator->endtime($record),
            'cdr_endtime' => $cdrEndtime,
            'cdr_id' => (int)($record['cdr_id'] ?? $record['id'] ?? $recordId),
            'cdr_uniqueid' => trim((string)($record['cdr_uniqueid'] ?? $record['UNIQUEID'] ?? '')),
            'cdr_start_ms' => (int)($record['cdr_start_ms'] ?? 0) ?: $this->evaluator->dateTimeMilliseconds((string)($record['start'] ?? '')),
            'cdr_end_ms' => (int)($record['cdr_end_ms'] ?? 0) ?: $this->evaluator->dateTimeMilliseconds((string)($record['endtime'] ?? '')),
            'recording_duration_sec' => $this->evaluator->recordingDurationSeconds($record),
            'recording_duration_source' => trim((string)($record['recording_duration_source'] ?? '')),
            'reason' => $reason,
            'created_at' => time(),
        ];
        $skippedRecordings[$recordId] = $retryRecord;
        $this->retryStateStore()->remember(
            $recordId,
            RetryStateStore::TYPE_SKIPPED,
            $retryRecord,
            $reason
        );
        if ($isNew) {
            LocalSpeechToTextLogger::event(
                'scanner',
                'recording_skipped',
                'Recording skipped before transcription',
                LOG_WARNING,
                [
                    'skip_reason' => $reason,
                    'recording_duration_ms' => $this->evaluator->recordingDurationSeconds($record) !== null
                        ? $this->evaluator->recordingDurationSeconds($record) * 1000
                        : null,
                ]
            );
        }

        if (count($skippedRecordings) > self::MAX_SKIPPED_RECORDINGS) {
            uasort(
                $skippedRecordings,
                static fn (array $left, array $right): int => ((int)($left['created_at'] ?? 0)) <=> ((int)($right['created_at'] ?? 0))
            );
            $skippedRecordings = array_slice($skippedRecordings, -self::MAX_SKIPPED_RECORDINGS, null, true);
            LocalSpeechToTextLogger::event(
                'scanner',
                'retry_cache_overflow',
                'Recording retry display cache reached its limit',
                LOG_WARNING,
                ['cache_type' => 'skipped']
            );
        }

        $this->saveSkippedRecordings($skippedRecordings);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function skippedRecordings(): array
    {
        $json = STTSetting::getValue(STTSetting::SKIPPED_RECORDINGS, '[]');
        $records = json_decode($json, true);
        if (!is_array($records)) {
            return [];
        }

        $skippedRecordings = [];
        foreach ($records as $recordId => $record) {
            if (!is_array($record)) {
                continue;
            }

            $recordId = (int)$recordId;
            if ($recordId <= 0) {
                continue;
            }

            $skippedRecordings[$recordId] = $record;
        }

        return $skippedRecordings;
    }

    /**
     * @param array<int, array<string, mixed>> $skippedRecordings
     */
    public function saveSkippedRecordings(array $skippedRecordings): void
    {
        STTSetting::setValue(
            STTSetting::SKIPPED_RECORDINGS,
            json_encode($skippedRecordings, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    public function forgetDelayedRecording(array &$delayedRecordings, int $recordId): void
    {
        unset($delayedRecordings[$recordId]);
        $this->retryStateStore()->remove($recordId);
    }

    public function retryStateStore(): RetryStateStore
    {
        if (!$this->retryStateStore instanceof RetryStateStore) {
            $this->retryStateStore = new RetryStateStore((new STTJob())->getWriteConnection());
        }

        return $this->retryStateStore;
    }

    /**
     * @param array<int, array<string, mixed>> $skippedRecordings
     * @param array{cdr_id: int, first_seen: int} $retryRecord
     */
    public function expireSkippedRetry(array &$skippedRecordings, array $retryRecord): bool
    {
        if ((string)($retryRecord['reason'] ?? '') === 'duration_probe_failed') {
            return false;
        }

        $firstSeen = (int)$retryRecord['first_seen'];
        if ($firstSeen <= 0 || time() - $firstSeen < self::MAX_SKIPPED_RETRY_AGE_SECONDS) {
            return false;
        }

        $recordId = (int)$retryRecord['cdr_id'];
        unset($skippedRecordings[$recordId]);
        $this->retryStateStore()->remove($recordId);
        LocalSpeechToTextLogger::event(
            'scanner',
            'recording_retry_expired',
            'Recording retry lifetime expired',
            LOG_WARNING,
            ['skip_reason' => 'retry_lifetime_exhausted']
        );

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    public function saveDelayedRecordings(array $delayedRecordings): void
    {
        STTSetting::setValue(
            STTSetting::DELAYED_RECORDINGS,
            json_encode($delayedRecordings, JSON_THROW_ON_ERROR)
        );
    }
}
