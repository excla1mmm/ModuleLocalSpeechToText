<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\bin;

require_once 'Globals.php';

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Providers\DatabaseProviderBase;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

class WorkerLocalSpeechToTextJobs extends WorkerBase
{
    private const int SCAN_INTERVAL_SECONDS = 15;
    private const int DEFAULT_SCAN_LIMIT = 200;
    private const int LONG_CALL_SECONDS = 1800;
    private const int DELAYED_RECORDING_TTL_SECONDS = 86400;
    private const int MAX_DELAYED_RECORDINGS = 500;
    private const int MAX_SKIPPED_RECORDINGS = 100;

    public static function getCheckInterval(): int
    {
        return self::SCAN_INTERVAL_SECONDS;
    }

    public function start(array $argv): void
    {
        DatabaseProviderBase::ensureCdrTables();

        LocalSpeechToTextLogger::syslog(
            __CLASS__,
            sprintf('Worker started (PID:%d)', getmypid()),
            LOG_NOTICE
        );

        while ($this->needRestart === false) {
            pcntl_signal_dispatch();

            try {
                STTWorker::markStaleOffline();
                $requeued = STTJob::requeueStaleProcessing();
                if ($requeued > 0) {
                    LocalSpeechToTextLogger::syslog(
                        __CLASS__,
                        sprintf('Requeued %d stale processing transcription job(s)', $requeued),
                        LOG_NOTICE
                    );
                }
                $this->retryDelayedRecordings();
                $this->retrySkippedRecordings();
                $this->createJobsFromCompletedCdr();
                (new CallTranscriptRevisionService())->publishReady();
            } catch (Throwable $exception) {
                LocalSpeechToTextLogger::syslog(
                    __CLASS__,
                    'Error while scanning CDR recordings: ' . $exception->getMessage(),
                    LOG_ERR
                );
            }

            sleep(self::SCAN_INTERVAL_SECONDS);
        }

        LocalSpeechToTextLogger::syslog(
            __CLASS__,
            sprintf('Worker stopped (PID:%d)', getmypid()),
            LOG_NOTICE
        );
    }

    private function createJobsFromCompletedCdr(): void
    {
        $delayedRecordings = $this->delayedRecordings();
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        $this->initializeRealtimeScanCursor();
        $this->createRealtimeJobsFromCompletedCdr($delayedRecordings, $historyStart);
        if ($this->needRestart) {
            $this->saveDelayedRecordings($delayedRecordings);
            return;
        }

        $this->createBackfillJobsFromCompletedCdr($delayedRecordings, $historyStart);
        $this->saveDelayedRecordings($delayedRecordings);
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    private function createRealtimeJobsFromCompletedCdr(array &$delayedRecordings, string $historyStart): void
    {
        [$cursorEndtime, $cursorId] = $this->scanCursor();
        $conditions = 'recordingfile <> :empty: AND endtime <> :empty: AND work_completed = :completed:'
            . ' AND (endtime > :cursor_endtime: OR (endtime = :cursor_endtime: AND id > :cursor_id:))';
        $bind = [
            'empty' => '',
            'completed' => '1',
            'cursor_endtime' => $cursorEndtime,
            'cursor_id' => $cursorId,
        ];
        if ($historyStart !== '') {
            $conditions .= ' AND endtime >= :history_start:';
            $bind['history_start'] = $historyStart;
        }

        $records = CallDetailRecords::find([
            'conditions' => $conditions,
            'bind' => $bind,
            'order' => 'endtime ASC, id ASC',
            'limit' => $this->scanLimit(),
        ]);

        $lastEndtime = $cursorEndtime;
        $lastId = $cursorId;

        foreach ($records->toArray() as $record) {
            if ($this->needRestart) {
                $this->saveScanCursor($lastEndtime, $lastId);
                return;
            }

            $this->processCompletedRecord($delayedRecordings, $record, $historyStart);

            $recordEndtime = $this->endtime($record);
            $recordId = $this->recordId($record);
            if (
                $recordEndtime !== ''
                && ($recordEndtime > $lastEndtime || ($recordEndtime === $lastEndtime && $recordId > $lastId))
            ) {
                $lastEndtime = $recordEndtime;
                $lastId = $recordId;
            }
        }

        $this->saveScanCursor($lastEndtime, $lastId);
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    private function createBackfillJobsFromCompletedCdr(array &$delayedRecordings, string $historyStart): void
    {
        // Backfill is a one-shot scan inside the current retention window.
        // Once the tail is drained, we keep the flag set and stop hammering the CDR
        // table every SCAN_INTERVAL_SECONDS until the retention window is widened.
        if ($this->backfillCompletedFor($historyStart)) {
            return;
        }

        [$cursorEndtime, $cursorId] = $this->backfillCursor();
        if ($cursorEndtime === '') {
            $cursorEndtime = date('Y-m-d H:i:s');
            $cursorId = PHP_INT_MAX;
        }

        // The retention window slides forward each day; if the cursor has already
        // slid past it, every subsequent scan is guaranteed to be empty. Mark done.
        if ($historyStart !== '' && $cursorEndtime < $historyStart) {
            $this->markBackfillCompleted($historyStart);
            return;
        }

        $conditions = 'recordingfile <> :empty: AND endtime <> :empty: AND work_completed = :completed:'
            . ' AND (endtime < :cursor_endtime: OR (endtime = :cursor_endtime: AND id < :cursor_id:))';
        $bind = [
            'empty' => '',
            'completed' => '1',
            'cursor_endtime' => $cursorEndtime,
            'cursor_id' => $cursorId,
        ];
        if ($historyStart !== '') {
            $conditions .= ' AND endtime >= :history_start:';
            $bind['history_start'] = $historyStart;
        }

        $scanLimit = $this->scanLimit();
        $records = CallDetailRecords::find([
            'conditions' => $conditions,
            'bind' => $bind,
            'order' => 'endtime DESC, id DESC',
            'limit' => $scanLimit,
        ]);

        $rows = $records->toArray();
        $rowCount = count($rows);

        $lastEndtime = '';
        $lastId = 0;

        foreach ($rows as $record) {
            if ($this->needRestart) {
                if ($lastEndtime !== '') {
                    $this->saveBackfillCursor($lastEndtime, $lastId);
                }
                return;
            }

            $this->processCompletedRecord($delayedRecordings, $record, $historyStart);
            $lastEndtime = $this->endtime($record);
            $lastId = $this->recordId($record);
        }

        if ($lastEndtime !== '') {
            $this->saveBackfillCursor($lastEndtime, $lastId);
        }

        // A short page is the only reliable signal that the tail has been drained:
        // empty result is the special case where $rowCount === 0 < $scanLimit.
        if ($rowCount < $scanLimit) {
            $this->markBackfillCompleted($historyStart);
        }
    }

    private function backfillCompletedFor(string $historyStart): bool
    {
        if (STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE, '0') !== '1') {
            return false;
        }

        // Auto-reset the flag if the operator widens the retention window
        // (history_start moved earlier than the snapshot we stored when we finished).
        $doneFor = trim(STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, ''));
        if ($historyStart !== '' && $doneFor !== '' && $historyStart < $doneFor) {
            STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE, '0');
            STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, '');
            return false;
        }

        return true;
    }

    private function markBackfillCompleted(string $historyStart): void
    {
        STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE, '1');
        STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, $historyStart);
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<string, mixed> $record
     */
    private function processCompletedRecord(array &$delayedRecordings, array $record, string $historyStart): void
    {
        if (!$this->recordWithinHistoryWindow($record, $historyStart)) {
            $this->rememberSkippedRecording($record, 'outside_history_window');
            return;
        }

        $recordingPath = $this->recordingPath((string)($record['recordingfile'] ?? ''));
        if ($recordingPath === null) {
            $this->rememberDelayedRecording($delayedRecordings, $record);
            return;
        }

        $this->forgetDelayedRecording($delayedRecordings, $this->recordId($record));
        if (!$this->recordingWithinSizeLimit($recordingPath)) {
            $this->logSkippedLargeRecording($recordingPath);
            $this->rememberSkippedRecording($record, 'file_too_large', $recordingPath);
            return;
        }

        if (STTJob::existsForFilePath($recordingPath)) {
            return;
        }

        $this->createJob($record, $recordingPath);
    }

    private function createJob(array $record, string $recordingPath): void
    {
        STTJob::createPending(
            $this->callId($record),
            $recordingPath,
            $this->priority($record),
            $this->recordEndtimeTimestamp($record),
            $this->recordingIdentity($record, $recordingPath)
        );

        LocalSpeechToTextLogger::syslog(
            __CLASS__,
            sprintf('Created transcription job for recording %s', $recordingPath),
            LOG_INFO
        );
    }

    private function retryDelayedRecordings(): void
    {
        $delayedRecordings = $this->delayedRecordings();
        if ($delayedRecordings === []) {
            return;
        }

        $changed = false;
        $now = time();
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        foreach ($delayedRecordings as $recordId => $record) {
            if ($this->needRestart) {
                if ($changed) {
                    $this->saveDelayedRecordings($delayedRecordings);
                }
                return;
            }

            if (!$this->recordWithinHistoryWindow($record, $historyStart)) {
                $this->rememberSkippedRecording($record, 'outside_history_window', '', (int)$recordId);
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            if ($now - (int)($record['first_seen'] ?? $now) > self::DELAYED_RECORDING_TTL_SECONDS) {
                $this->rememberSkippedRecording($record, 'recording_missing_timeout', '', (int)$recordId);
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            $recordingPath = $this->recordingPath((string)($record['recordingfile'] ?? ''));
            if ($recordingPath === null) {
                continue;
            }

            if (!$this->recordingWithinSizeLimit($recordingPath)) {
                $this->logSkippedLargeRecording($recordingPath);
                $this->rememberSkippedRecording($record, 'file_too_large', $recordingPath, (int)$recordId);
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            if (!STTJob::existsForFilePath($recordingPath)) {
                STTJob::createPending(
                    (string)($record['call_id'] ?? ''),
                    $recordingPath,
                    (int)($record['priority'] ?? 5),
                    (int)($record['cdr_endtime'] ?? 0) ?: null,
                    $this->recordingIdentity($record, $recordingPath)
                );
                LocalSpeechToTextLogger::syslog(
                    __CLASS__,
                    sprintf('Created delayed transcription job for recording %s', $recordingPath),
                    LOG_INFO
                );
            }

            unset($delayedRecordings[$recordId]);
            $changed = true;
        }

        if ($changed) {
            $this->saveDelayedRecordings($delayedRecordings);
        }
    }

    private function retrySkippedRecordings(): void
    {
        $skippedRecordings = $this->skippedRecordings();
        if ($skippedRecordings === []) {
            return;
        }

        $changed = false;
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        foreach ($skippedRecordings as $recordId => $record) {
            if ($this->needRestart) {
                if ($changed) {
                    $this->saveSkippedRecordings($skippedRecordings);
                }
                return;
            }

            if (!$this->recordWithinHistoryWindow($record, $historyStart)) {
                continue;
            }

            $recordingFile = trim((string)($record['recordingfile'] ?? ''));
            if ($recordingFile === '') {
                $recordingFile = (string)($record['recording_path'] ?? '');
            }
            $recordingPath = $this->recordingPath($recordingFile);
            if ($recordingPath === null) {
                continue;
            }

            if (!$this->recordingWithinSizeLimit($recordingPath)) {
                continue;
            }

            if (!STTJob::existsForFilePath($recordingPath)) {
                STTJob::createPending(
                    (string)($record['call_id'] ?? ''),
                    $recordingPath,
                    (int)($record['priority'] ?? 5),
                    (int)($record['cdr_endtime'] ?? 0) ?: null,
                    $this->recordingIdentity($record, $recordingPath)
                );
                LocalSpeechToTextLogger::syslog(
                    __CLASS__,
                    sprintf('Recovered skipped transcription job for recording %s', $recordingPath),
                    LOG_INFO
                );
            }

            unset($skippedRecordings[$recordId]);
            $changed = true;
        }

        if ($changed) {
            $this->saveSkippedRecordings($skippedRecordings);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function delayedRecordings(): array
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
    private function rememberDelayedRecording(array &$delayedRecordings, array $record): void
    {
        $recordId = $this->recordId($record);
        if ($recordId <= 0) {
            return;
        }

        $now = time();
        $delayedRecordings[$recordId] = [
            'recordingfile' => (string)($record['recordingfile'] ?? ''),
            'call_id' => $this->callId($record),
            'priority' => $this->priority($record),
            'endtime' => $this->endtime($record),
            'cdr_endtime' => $this->recordEndtimeTimestamp($record),
            'cdr_id' => $this->recordId($record),
            'cdr_uniqueid' => trim((string)($record['UNIQUEID'] ?? '')),
            'cdr_start_ms' => $this->dateTimeMilliseconds((string)($record['start'] ?? '')),
            'cdr_end_ms' => $this->dateTimeMilliseconds((string)($record['endtime'] ?? '')),
            'first_seen' => (int)($delayedRecordings[$recordId]['first_seen'] ?? $now),
            'last_seen' => $now,
        ];

        if (count($delayedRecordings) > self::MAX_DELAYED_RECORDINGS) {
            uasort(
                $delayedRecordings,
                static fn (array $left, array $right): int => ((int)($left['first_seen'] ?? 0)) <=> ((int)($right['first_seen'] ?? 0))
            );
            $delayedRecordings = array_slice($delayedRecordings, -self::MAX_DELAYED_RECORDINGS, null, true);
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function rememberSkippedRecording(array $record, string $reason, string $recordingPath = '', int $recordId = 0): void
    {
        $recordId = $recordId > 0 ? $recordId : $this->recordId($record);
        if ($recordId <= 0) {
            return;
        }

        $recordingFile = trim((string)($record['recordingfile'] ?? ''));
        if ($recordingFile === '') {
            $recordingFile = $recordingPath;
        }

        $cdrEndtime = (int)($record['cdr_endtime'] ?? 0);
        if ($cdrEndtime <= 0) {
            $cdrEndtime = (int)($this->recordEndtimeTimestamp($record) ?? 0);
        }

        $skippedRecordings = $this->skippedRecordings();
        $skippedRecordings[$recordId] = [
            'recordingfile' => $recordingFile,
            'recording_path' => $recordingPath,
            'call_id' => (string)($record['call_id'] ?? $this->callId($record)),
            'priority' => (int)($record['priority'] ?? $this->priority($record)),
            'endtime' => $this->endtime($record),
            'cdr_endtime' => $cdrEndtime,
            'cdr_id' => (int)($record['cdr_id'] ?? $record['id'] ?? $recordId),
            'cdr_uniqueid' => trim((string)($record['cdr_uniqueid'] ?? $record['UNIQUEID'] ?? '')),
            'cdr_start_ms' => (int)($record['cdr_start_ms'] ?? 0) ?: $this->dateTimeMilliseconds((string)($record['start'] ?? '')),
            'cdr_end_ms' => (int)($record['cdr_end_ms'] ?? 0) ?: $this->dateTimeMilliseconds((string)($record['endtime'] ?? '')),
            'reason' => $reason,
            'created_at' => time(),
        ];

        if (count($skippedRecordings) > self::MAX_SKIPPED_RECORDINGS) {
            uasort(
                $skippedRecordings,
                static fn (array $left, array $right): int => ((int)($left['created_at'] ?? 0)) <=> ((int)($right['created_at'] ?? 0))
            );
            $skippedRecordings = array_slice($skippedRecordings, -self::MAX_SKIPPED_RECORDINGS, null, true);
        }

        $this->saveSkippedRecordings($skippedRecordings);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function skippedRecordings(): array
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
    private function saveSkippedRecordings(array $skippedRecordings): void
    {
        STTSetting::setValue(
            STTSetting::SKIPPED_RECORDINGS,
            json_encode($skippedRecordings, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    private function forgetDelayedRecording(array &$delayedRecordings, int $recordId): void
    {
        unset($delayedRecordings[$recordId]);
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    private function saveDelayedRecordings(array $delayedRecordings): void
    {
        STTSetting::setValue(
            STTSetting::DELAYED_RECORDINGS,
            json_encode($delayedRecordings, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function scanCursor(): array
    {
        return [
            trim(STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, '')),
            (int)STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID, '0'),
        ];
    }

    private function initializeRealtimeScanCursor(): void
    {
        [$cursorEndtime] = $this->scanCursor();
        if ($cursorEndtime !== '') {
            return;
        }

        $this->saveScanCursor(date('Y-m-d H:i:s'), 0);
    }

    private function saveScanCursor(string $endtime, int $recordId): void
    {
        STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, $endtime);
        STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ID, (string)max(0, $recordId));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function backfillCursor(): array
    {
        return [
            trim(STTSetting::getValue(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, '')),
            (int)STTSetting::getValue(STTSetting::CDR_BACKFILL_CURSOR_ID, '0'),
        ];
    }

    private function saveBackfillCursor(string $endtime, int $recordId): void
    {
        STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, $endtime);
        STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ID, (string)max(0, $recordId));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordId(array $record): int
    {
        return (int)($record['id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function endtime(array $record): string
    {
        return trim((string)($record['endtime'] ?? ''));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordEndtimeTimestamp(array $record): ?int
    {
        $endtime = $this->endtime($record);
        if ($endtime === '') {
            return null;
        }

        $timestamp = strtotime($endtime);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function recordWithinHistoryWindow(array $record, string $historyStart): bool
    {
        if ($historyStart === '') {
            return true;
        }

        $endtime = $this->endtime($record);
        if ($endtime === '') {
            return true;
        }

        return $endtime >= $historyStart;
    }

    private function scanLimit(): int
    {
        $limit = (int)STTSetting::getValue(STTSetting::CDR_SCAN_LIMIT, (string)self::DEFAULT_SCAN_LIMIT);

        return max(1, min(1000, $limit));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function callId(array $record): string
    {
        $linkedId = trim((string)($record['linkedid'] ?? ''));
        if ($linkedId !== '') {
            return $linkedId;
        }

        return trim((string)($record['UNIQUEID'] ?? ''));
    }

    /**
     * @param array<string, mixed> $record
     * @return array{cdr_id: int|null, cdr_uniqueid: string, cdr_start_ms: int|null, cdr_end_ms: int|null, recording_fingerprint: string}
     */
    private function recordingIdentity(array $record, string $recordingPath): array
    {
        $cdrId = (int)($record['cdr_id'] ?? $record['id'] ?? 0);
        $uniqueId = trim((string)($record['cdr_uniqueid'] ?? $record['UNIQUEID'] ?? ''));
        $startMs = (int)($record['cdr_start_ms'] ?? 0) ?: $this->dateTimeMilliseconds((string)($record['start'] ?? ''));
        $endMs = (int)($record['cdr_end_ms'] ?? 0) ?: $this->dateTimeMilliseconds((string)($record['endtime'] ?? ''));
        $size = is_file($recordingPath) ? (int)filesize($recordingPath) : 0;
        $modifiedAt = is_file($recordingPath) ? (int)filemtime($recordingPath) : 0;
        $fingerprintMaterial = implode('|', [
            (string)$cdrId,
            $uniqueId,
            (string)($startMs ?? 0),
            (string)($endMs ?? 0),
            (string)$size,
            (string)$modifiedAt,
        ]);

        return [
            'cdr_id' => $cdrId > 0 ? $cdrId : null,
            'cdr_uniqueid' => $uniqueId,
            'cdr_start_ms' => $startMs,
            'cdr_end_ms' => $endMs,
            'recording_fingerprint' => hash('sha256', $fingerprintMaterial),
        ];
    }

    private function dateTimeMilliseconds(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $date instanceof \DateTimeImmutable
            ? ((int)$date->format('U') * 1000) + (int)substr($date->format('u'), 0, 3)
            : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function priority(array $record): int
    {
        $duration = (int)($record['duration'] ?? 0);
        if ($duration >= self::LONG_CALL_SECONDS) {
            return 8;
        }

        return 5;
    }

    private function recordingPath(string $recordingFile): ?string
    {
        $recordingFile = trim($recordingFile);
        if ($recordingFile === '' || $recordingFile === '-') {
            return null;
        }

        $monitorDir = Directories::getDir(Directories::AST_MONITOR_DIR);
        $candidate = str_starts_with($recordingFile, '/')
            ? $recordingFile
            : $monitorDir . '/' . ltrim($recordingFile, '/');

        $allowedBase = realpath($monitorDir);
        $resolvedPath = realpath($candidate);
        if ($allowedBase === false || $resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            return null;
        }

        $allowedPrefix = rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolvedPath !== $allowedBase && strncmp($resolvedPath, $allowedPrefix, strlen($allowedPrefix)) !== 0) {
            return null;
        }

        return $resolvedPath;
    }

    private function recordingWithinSizeLimit(string $recordingPath): bool
    {
        if (!is_file($recordingPath) || !is_readable($recordingPath)) {
            return false;
        }

        clearstatcache(true, $recordingPath);
        $fileSize = filesize($recordingPath);

        return $fileSize !== false && $fileSize <= STTSetting::maxFileSizeBytes();
    }

    private function logSkippedLargeRecording(string $recordingPath): void
    {
        LocalSpeechToTextLogger::syslog(
            __CLASS__,
            sprintf('Skipped transcription job for %s: file exceeds configured maximum size', $recordingPath),
            LOG_WARNING
        );
    }
}

WorkerLocalSpeechToTextJobs::startWorker($argv ?? []);
