<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\bin;

require_once 'Globals.php';

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Providers\DatabaseProviderBase;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CdrDiscoveryBackoffPolicy;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\RecordingDurationProbe;
use Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerDatabaseFailurePolicy;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerIterationFailureHandler;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerProcessGuard;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptRetentionService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TransactionRecoveryException;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

class WorkerLocalSpeechToTextJobs extends WorkerBase
{
    private const int SCAN_INTERVAL_SECONDS = 15;
    // Internal CDR discovery budget: not a user setting. Realtime and backfill share
    // at most this many core CDR rows per pass; pending backfill always gets a slice.
    private const int CDR_DISCOVERY_BATCH_SIZE = 30;
    private const int BACKFILL_RESERVED_BATCH_SIZE = 5;
    // Durable delayed/skipped retry state lives in the module DB, not the core CDR
    // table; it gets its own small cap so ffprobe/filesystem retries can't spike load.
    private const int RETRY_DUE_BATCH_LIMIT = 10;
    private const int DISCRETIONARY_WORK_BUDGET_SECONDS = 10;
    private const int LONG_CALL_SECONDS = 1800;
    private const int DELAYED_RECORDING_TTL_SECONDS = 86400;
    private const int MAX_DELAYED_RECORDINGS = 500;
    private const int MAX_SKIPPED_RECORDINGS = 100;
    private const int MAX_SKIPPED_RETRY_SECONDS = 3600;
    private const int MAX_SKIPPED_RETRY_AGE_SECONDS = 2592000;
    private const int TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS = 3600;

    private ?RetryStateStore $retryStateStore = null;
    private ?RecordingDurationProbe $recordingDurationProbe = null;
    private bool $runtimePragmasConfigured = false;
    private int $discoveryDelaySeconds = CdrDiscoveryBackoffPolicy::BASE_DELAY_SECONDS;
    private int $nextDiscoveryAt = 0;
    private float $iterationDeadlineAt = 0.0;

    public static function getCheckInterval(): int
    {
        return self::SCAN_INTERVAL_SECONDS;
    }

    private static function remainingScanIntervalSeconds(float $startedAt, float $finishedAt): int
    {
        return max(0, (int)ceil(self::SCAN_INTERVAL_SECONDS - ($finishedAt - $startedAt)));
    }

    private function iterationBudgetExhausted(): bool
    {
        return $this->iterationDeadlineAt > 0.0 && microtime(true) >= $this->iterationDeadlineAt;
    }

    public function start(array $argv): void
    {
        $processGuard = null;
        $processGuardAcquired = false;
        $consecutiveDatabaseFailures = 0;
        $recycleRequested = false;
        $stopReason = 'signal';

        try {
            try {
                $processGuard = new ScannerProcessGuard();
                $processGuardAcquired = $processGuard->acquire();
                if (!$processGuardAcquired) {
                    LocalSpeechToTextLogger::event(
                        'system',
                        'scanner_duplicate_rejected',
                        'Duplicate STT recording scanner rejected',
                        LOG_NOTICE,
                        ['pid' => getmypid()]
                    );
                    return;
                }

                $startupBackoffSeconds = $processGuard->startupBackoffSeconds();
                if ($startupBackoffSeconds > 0) {
                    $backoffContext = ['delay_seconds' => $startupBackoffSeconds];
                    $stateErrorCode = $processGuard->lastStateErrorCode();
                    if ($stateErrorCode !== null) {
                        $backoffContext['error_code'] = $stateErrorCode;
                    }
                    LocalSpeechToTextLogger::event(
                        'system',
                        'scanner_recovery_backoff_applied',
                        'STT scanner recovery backoff applied',
                        LOG_WARNING,
                        $backoffContext
                    );
                    sleep($startupBackoffSeconds);
                }
            } catch (Throwable $exception) {
                LocalSpeechToTextLogger::event(
                    'system',
                    'operation_failed',
                    'STT scanner process guard failed',
                    LOG_ERR,
                    [
                        'operation' => 'scanner_process_guard',
                        'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                    ]
                );
                return;
            }

            try {
                $processGuard->assertOwnership();
            } catch (Throwable $ownershipException) {
                $stopReason = 'process_guard_lost';
                $this->logProcessGuardLost($ownershipException);
                $this->logScannerStopped($stopReason);
                return;
            }

            pcntl_signal_dispatch();
            if ($this->needRestart) {
                return;
            }

            LocalSpeechToTextLogger::event(
                'system',
                'scanner_started',
                'STT recording scanner started',
                LOG_NOTICE,
                ['pid' => getmypid()]
            );

            $lifecycleGate = new ModuleLifecycleGate();
            $cdrTablesInitialized = false;
            while ($this->needRestart === false) {
                $iterationStartedAt = microtime(true);
                pcntl_signal_dispatch();

                try {
                    $processGuard->assertOwnership();
                } catch (Throwable $ownershipException) {
                    $stopReason = 'process_guard_lost';
                    $this->logProcessGuardLost($ownershipException);
                    break;
                }

                $writeLease = $lifecycleGate->acquireWriteLease();
                if ($writeLease === null) {
                    sleep(self::SCAN_INTERVAL_SECONDS);
                    continue;
                }

                $iterationSucceeded = false;
                try {
                    (new ModuleDatabaseWriterMutex())->synchronized(function () use (&$cdrTablesInitialized, &$scanPhase, $lifecycleGate, $iterationStartedAt): void {
                        $this->iterationDeadlineAt = $iterationStartedAt + self::DISCRETIONARY_WORK_BUDGET_SECONDS;
                        if (!$this->runtimePragmasConfigured) {
                            $scanPhase = 'runtime_pragmas';
                            (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
                            $this->runtimePragmasConfigured = true;
                        }
                        if (!$cdrTablesInitialized) {
                            $scanPhase = 'cdr_schema';
                            DatabaseProviderBase::ensureCdrTables();
                            $cdrTablesInitialized = true;
                        }
                        $scanPhase = 'stale_workers';
                        STTWorker::markStaleOffline();
                        $scanPhase = 'stale_leases';
                        $requeued = STTJob::requeueStaleProcessing();
                        if ($requeued > 0) {
                            LocalSpeechToTextLogger::event(
                                'queue',
                                'stale_leases_recovered',
                                'Expired transcription leases were recovered',
                                LOG_NOTICE,
                                ['recovered_count' => $requeued]
                            );
                        }

                        // A module disable was requested while this iteration was already
                        // running. Skip the discretionary work below (new job creation,
                        // retries, publishing, retention) so the write lease is released
                        // as soon as possible instead of racing the drain timeout.
                        if ($lifecycleGate->currentState() !== ModuleLifecycleGate::STATE_OPEN) {
                            LocalSpeechToTextLogger::event(
                                'system',
                                'scanner_iteration_curtailed',
                                'STT scanner skipped discretionary work because a module disable drain is in progress',
                                LOG_NOTICE,
                                ['scan_phase' => $scanPhase]
                            );
                            return;
                        }

                        $scanPhase = 'delayed_recordings';
                        $this->retryDelayedRecordings();
                        if ($this->iterationBudgetExhausted()) {
                            return;
                        }
                        $scanPhase = 'skipped_recordings';
                        $this->retrySkippedRecordings();
                        if ($this->iterationBudgetExhausted()) {
                            return;
                        }
                        $scanPhase = 'cdr_scan';
                        $this->runCdrDiscoveryIfDue();
                        if ($this->iterationBudgetExhausted()) {
                            return;
                        }
                        $scanPhase = 'call_transcript_publish';
                        $publication = (new CallTranscriptRevisionService())->publishReady();
                        if ($publication['published'] > 0) {
                            LocalSpeechToTextLogger::event(
                                'transcript',
                                'call_transcripts_published',
                                'Call transcript revisions published',
                                LOG_NOTICE,
                                [
                                    'published_count' => $publication['published'],
                                    'scanned_count' => $publication['scanned'],
                                    'blocked_jobs' => $publication['blocked_jobs'],
                                ]
                            );
                        }
                        $scanPhase = 'retention_cleanup';
                        $this->purgeExpiredTranscripts();
                    });
                    $iterationSucceeded = true;
                } catch (ModuleDatabaseWriterBusyException) {
                    $consecutiveDatabaseFailures = 0;
                    LocalSpeechToTextLogger::event(
                        'storage',
                        'database_writer_busy',
                        'STT scanner iteration deferred while another database writer is active',
                        LOG_NOTICE,
                        ['operation' => 'scanner_iteration']
                    );
                } catch (Throwable $exception) {
                    $outcome = (new ScannerIterationFailureHandler())->handle(
                        $exception,
                        fn (): array => $this->scannerConnections(),
                        $consecutiveDatabaseFailures
                    );
                    $consecutiveDatabaseFailures = $outcome['consecutive_database_failures'];

                    LocalSpeechToTextLogger::event(
                        'system',
                        'operation_failed',
                        'STT recording scan failed',
                        LOG_ERR,
                        [
                            'operation' => 'scanner_iteration',
                            'scan_phase' => $scanPhase,
                            'database_error_kind' => $outcome['database_error_kind'],
                            'consecutive_failures' => $consecutiveDatabaseFailures,
                            'recovery_action' => $outcome['recovery_action'],
                            'recovery_succeeded' => $outcome['recovery_succeeded'],
                            'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                        ]
                    );

                    $recoveryException = $outcome['recovery_exception'];
                    if ($recoveryException !== null) {
                        LocalSpeechToTextLogger::event(
                            'storage',
                            'database_operation_failed',
                            'Scanner database recovery failed',
                            LOG_CRIT,
                            [
                                'operation' => 'scanner_database_recovery',
                                'error_code' => LocalSpeechToTextLogSanitizer::errorCode($recoveryException),
                            ]
                        );
                    }

                    if ($outcome['recovery_action'] === ScannerDatabaseFailurePolicy::ACTION_RECYCLE) {
                        try {
                            $processGuard->recordRecycle();
                        } catch (Throwable $stateException) {
                            LocalSpeechToTextLogger::event(
                                'system',
                                'operation_failed',
                                'STT scanner recycle state could not be persisted',
                                LOG_WARNING,
                                [
                                    'operation' => 'scanner_recycle_state',
                                    'error_code' => LocalSpeechToTextLogSanitizer::errorCode($stateException),
                                ]
                            );
                        }
                        $recycleRequested = true;
                        $stopReason = 'database_recycle';
                        LocalSpeechToTextLogger::event(
                            'system',
                            'scanner_recycle_requested',
                            'STT scanner process recycle requested',
                            LOG_WARNING,
                            ['pid' => getmypid()]
                        );
                    }
                } finally {
                    $this->iterationDeadlineAt = 0.0;
                    $lifecycleGate->releaseWriteLease($writeLease);
                }

                if ($iterationSucceeded) {
                    $consecutiveDatabaseFailures = 0;
                    try {
                        $processGuard->recordHealthy();
                    } catch (Throwable $stateException) {
                        LocalSpeechToTextLogger::event(
                            'system',
                            'operation_failed',
                            'STT scanner healthy state could not be persisted',
                            LOG_WARNING,
                            [
                                'operation' => 'scanner_healthy_state',
                                'error_code' => LocalSpeechToTextLogSanitizer::errorCode($stateException),
                            ]
                        );
                        try {
                            $processGuard->assertOwnership();
                        } catch (Throwable $ownershipException) {
                            $stopReason = 'process_guard_lost';
                            $this->logProcessGuardLost($ownershipException);
                            break;
                        }
                    }
                }
                if ($recycleRequested) {
                    break;
                }

                $sleepSeconds = self::remainingScanIntervalSeconds($iterationStartedAt, microtime(true));
                if ($sleepSeconds > 0) {
                    sleep($sleepSeconds);
                }
            }

            $this->logScannerStopped($stopReason);
        } finally {
            if ($processGuardAcquired && $processGuard instanceof ScannerProcessGuard) {
                $processGuard->release();
            }
        }
    }

    private function logProcessGuardLost(Throwable $exception): void
    {
        LocalSpeechToTextLogger::event(
            'system',
            'operation_failed',
            'STT scanner process guard ownership lost',
            LOG_CRIT,
            [
                'operation' => 'scanner_process_guard_ownership',
                'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
            ]
        );
    }

    private function logScannerStopped(string $stopReason): void
    {
        LocalSpeechToTextLogger::event(
            'system',
            'scanner_stopped',
            'STT recording scanner stopped',
            LOG_NOTICE,
            [
                'pid' => getmypid(),
                'stop_reason' => $stopReason,
            ]
        );
    }

    private function purgeExpiredTranscripts(): void
    {
        $retentionDays = STTSetting::transcriptRetentionDays();
        if ($retentionDays === 0) {
            return;
        }

        $now = time();
        $lastCleanupAt = max(0, (int)STTSetting::getValue(
            STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT,
            '0'
        ));
        if ($lastCleanupAt > $now - self::TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS) {
            return;
        }

        $summary = (new TranscriptRetentionService())->purgeExpired($retentionDays, $now);
        STTSetting::setValue(
            STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT,
            $summary['has_more'] ? '0' : (string)$now
        );
        if ($summary['calls'] > 0 || $summary['results'] > 0) {
            LocalSpeechToTextLogger::event(
                'transcript',
                'retention_cleanup_completed',
                'Expired transcript data removed',
                LOG_NOTICE,
                [
                    'calls_count' => $summary['calls'],
                    'results_count' => $summary['results'],
                    'has_more' => $summary['has_more'],
                ]
            );
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function scannerConnections(): array
    {
        try {
            return [
                (new STTJob())->getWriteConnection(),
                (new CallDetailRecords())->getReadConnection(),
            ];
        } catch (Throwable $exception) {
            throw new TransactionRecoveryException(
                'Unable to resolve scanner database connections for recovery',
                0,
                $exception
            );
        }
    }

    private function runCdrDiscoveryIfDue(): void
    {
        if ($this->iterationBudgetExhausted() || time() < $this->nextDiscoveryAt) {
            return;
        }

        $outcome = $this->createJobsFromCompletedCdr();
        if ($outcome['active']) {
            $this->discoveryDelaySeconds = CdrDiscoveryBackoffPolicy::delayAfterActivity();
            $this->nextDiscoveryAt = time() + $this->discoveryDelaySeconds;
        } elseif ($outcome['idle']) {
            $this->nextDiscoveryAt = time() + $this->discoveryDelaySeconds;
            $this->discoveryDelaySeconds = CdrDiscoveryBackoffPolicy::delayAfterIdle($this->discoveryDelaySeconds);
        }
        // Neither flag set means the pass was interrupted by a restart signal;
        // leave the schedule untouched so discovery is reconsidered on the very
        // next tick instead of being pushed further into the future.

    }

    /**
     * @return array{active: bool, idle: bool}
     */
    private function createJobsFromCompletedCdr(): array
    {
        $delayedRecordings = $this->delayedRecordings();
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        $this->initializeRealtimeScanCursor();
        $backfillPending = !$this->backfillCompletedFor($historyStart);
        $realtimeLimit = $backfillPending
            ? self::CDR_DISCOVERY_BATCH_SIZE - self::BACKFILL_RESERVED_BATCH_SIZE
            : self::CDR_DISCOVERY_BATCH_SIZE;
        $realtimeRowCount = $this->createRealtimeJobsFromCompletedCdr(
            $delayedRecordings,
            $historyStart,
            $realtimeLimit
        );
        if ($this->needRestart || $this->iterationBudgetExhausted()) {
            $this->saveDelayedRecordings($delayedRecordings);
            return ['active' => false, 'idle' => false];
        }

        $remainingBudget = self::CDR_DISCOVERY_BATCH_SIZE - $realtimeRowCount;
        if (!$backfillPending || $remainingBudget <= 0) {
            $this->saveDelayedRecordings($delayedRecordings);
            return $realtimeRowCount > 0
                ? ['active' => true, 'idle' => false]
                : ['active' => false, 'idle' => true];
        }

        $backfill = $this->createBackfillJobsFromCompletedCdr(
            $delayedRecordings,
            $historyStart,
            $remainingBudget
        );
        $this->saveDelayedRecordings($delayedRecordings);

        if ($backfill['interrupted']) {
            return ['active' => false, 'idle' => false];
        }

        return $realtimeRowCount > 0 || $backfill['row_count'] > 0
            ? ['active' => true, 'idle' => false]
            : ['active' => false, 'idle' => true];
    }

    /**
     * The realtime cursor is a plain `id` watermark, not `endtime`. Core
     * assigns `cdr_general.id` as a fresh autoincrement when a linkedid's
     * rows are moved out of the temp table - in completion order, not in
     * endtime order (see CallDetailRecordsTmp::afterSave() /
     * WorkerCdr::processCompletedRows() in Core). For a transferred or
     * multi-leg call, an early leg can be inserted (and get its id) long
     * after a later, short call that already advanced an endtime-based
     * cursor past that leg's endtime - permanently skipping it. `id` has no
     * such ordering inversion: it is assigned once, monotonically, at
     * insertion time, so `id > cursor` never misses a row and never needs
     * revisiting one already seen.
     *
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @return int Number of CDR rows the realtime query returned.
     */
    private function createRealtimeJobsFromCompletedCdr(
        array &$delayedRecordings,
        string $historyStart,
        int $scanLimit = self::CDR_DISCOVERY_BATCH_SIZE
    ): int
    {
        $cursorId = $this->scanCursor();
        $conditions = 'recordingfile <> :empty: AND endtime <> :empty: AND work_completed = :completed:'
            . ' AND id > :cursor_id:';
        $bind = [
            'empty' => '',
            'completed' => '1',
            'cursor_id' => $cursorId,
        ];
        if ($historyStart !== '') {
            $conditions .= ' AND endtime >= :history_start:';
            $bind['history_start'] = $historyStart;
        }

        $records = CallDetailRecords::find([
            'conditions' => $conditions,
            'bind' => $bind,
            'order' => 'id ASC',
            'limit' => $scanLimit,
        ]);

        $rows = $records->toArray();
        $processedRowCount = 0;

        $lastId = $cursorId;

        foreach ($rows as $record) {
            if ($this->needRestart || $this->iterationBudgetExhausted()) {
                $this->saveScanCursor($lastId);
                return $processedRowCount;
            }

            $this->processCompletedRecord($delayedRecordings, $record, $historyStart);
            $processedRowCount++;

            $recordId = $this->recordId($record);
            if ($recordId > $lastId) {
                $lastId = $recordId;
            }
        }

        $this->saveScanCursor($lastId);

        return $processedRowCount;
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @return array{row_count: int, interrupted: bool}
     */
    private function createBackfillJobsFromCompletedCdr(
        array &$delayedRecordings,
        string $historyStart,
        int $scanLimit = self::CDR_DISCOVERY_BATCH_SIZE
    ): array
    {
        // Backfill is a one-shot scan inside the current retention window.
        // Once the tail is drained, we keep the flag set and stop hammering the CDR
        // table every discovery pass until the retention window is widened.
        if ($this->backfillCompletedFor($historyStart)) {
            return ['row_count' => 0, 'interrupted' => false];
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
            return ['row_count' => 0, 'interrupted' => false];
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
            if ($this->needRestart || $this->iterationBudgetExhausted()) {
                if ($lastEndtime !== '') {
                    $this->saveBackfillCursor($lastEndtime, $lastId);
                }
                return ['row_count' => 0, 'interrupted' => true];
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

        return ['row_count' => $rowCount, 'interrupted' => false];
    }

    private function backfillCompletedFor(string $historyStart): bool
    {
        if (STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE, '0') !== '1') {
            return false;
        }

        // Auto-reset the flag if the operator widens the retention window:
        // either history_start moved earlier than the snapshot we stored
        // when we finished, or the window was widened all the way to
        // "unlimited" (historyStart === '' while we previously finished
        // against a bounded window). saveSettingsFromPost() already resets
        // these settings directly when cdr_history_days changes; this check
        // is a second line of defense so backfill self-heals even if this
        // method is reached with stale flags some other way.
        $doneFor = trim(STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, ''));
        $historyWindowWidened = $doneFor !== ''
            && ($historyStart === '' || $historyStart < $doneFor);
        if ($historyWindowWidened) {
            STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE, '0');
            STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, '');
            return false;
        }

        return true;
    }

    private function markBackfillCompleted(string $historyStart): void
    {
        STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, $historyStart);
        STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE, '1');
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
            $this->rememberSkippedRecording($record, 'file_too_large', $recordingPath);
            return;
        }

        $duration = $this->recordingDurationDecision($record, $recordingPath);
        if ($duration['state'] !== 'allowed') {
            $record = $this->recordWithDurationDecision($record, $duration);
            $this->rememberSkippedRecording(
                $record,
                $duration['state'] === 'too_long' ? 'duration_too_long' : 'duration_probe_failed',
                $recordingPath
            );
            return;
        }

        if (STTJob::existsForFilePath($recordingPath)) {
            return;
        }

        $this->createJob($record, $recordingPath, $duration['duration_sec']);
    }

    private function createJob(array $record, string $recordingPath, ?int $recordingDurationSeconds): void
    {
        $job = STTJob::createPending(
            $this->callId($record),
            $recordingPath,
            $this->priorityForDuration($recordingDurationSeconds),
            $this->recordEndtimeTimestamp($record),
            $this->recordingIdentity($record, $recordingPath),
            $recordingDurationSeconds
        );

        $this->logJobCreated($job, $recordingDurationSeconds);
    }

    private function retryDelayedRecordings(): void
    {
        $retryRecords = $this->retryStateStore()->due(
            RetryStateStore::TYPE_DELAYED,
            self::RETRY_DUE_BATCH_LIMIT
        );
        if ($retryRecords === []) {
            return;
        }

        $delayedRecordings = $this->delayedRecordings();
        $changed = false;
        $now = time();
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        foreach ($retryRecords as $retryRecord) {
            if ($this->iterationBudgetExhausted()) {
                if ($changed) {
                    $this->saveDelayedRecordings($delayedRecordings);
                }
                return;
            }
            $recordId = (int)$retryRecord['cdr_id'];
            $record = $retryRecord['payload'];
            $record['first_seen'] = (int)$retryRecord['first_seen'];
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
                $this->retryStateStore()->defer(
                    $recordId,
                    RetryStateStore::TYPE_DELAYED,
                    self::SCAN_INTERVAL_SECONDS
                );
                continue;
            }

            if (!$this->recordingWithinSizeLimit($recordingPath)) {
                $this->rememberSkippedRecording($record, 'file_too_large', $recordingPath, (int)$recordId);
                unset($delayedRecordings[$recordId]);
                $changed = true;
                continue;
            }

            $duration = $this->recordingDurationDecision($record, $recordingPath);
            if ($duration['state'] !== 'allowed') {
                $record = $this->recordWithDurationDecision($record, $duration);
                $this->rememberSkippedRecording(
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
                    $this->priorityForDuration($duration['duration_sec']),
                    (int)($record['cdr_endtime'] ?? 0) ?: null,
                    $this->recordingIdentity($record, $recordingPath),
                    $duration['duration_sec']
                );
                $this->logJobCreated(
                    $job,
                    $duration['duration_sec'],
                    'delayed_recording_recovered'
                );
            }

            unset($delayedRecordings[$recordId]);
            $this->retryStateStore()->remove($recordId);
            $changed = true;
        }

        if ($changed) {
            $this->saveDelayedRecordings($delayedRecordings);
        }
    }

    private function retrySkippedRecordings(): void
    {
        $retryRecords = $this->retryStateStore()->due(
            RetryStateStore::TYPE_SKIPPED,
            self::RETRY_DUE_BATCH_LIMIT
        );
        if ($retryRecords === []) {
            return;
        }

        $skippedRecordings = $this->skippedRecordings();
        $changed = false;
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        foreach ($retryRecords as $retryRecord) {
            if ($this->iterationBudgetExhausted()) {
                if ($changed) {
                    $this->saveSkippedRecordings($skippedRecordings);
                }
                return;
            }
            $recordId = (int)$retryRecord['cdr_id'];
            $record = $retryRecord['payload'];
            if ($this->needRestart) {
                if ($changed) {
                    $this->saveSkippedRecordings($skippedRecordings);
                }
                return;
            }

            if (!$this->recordWithinHistoryWindow($record, $historyStart)) {
                unset($skippedRecordings[$recordId]);
                $this->retryStateStore()->remove($recordId);
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
            $recordingPath = $this->recordingPath($recordingFile);
            if ($recordingPath === null) {
                if ($this->expireSkippedRetry($skippedRecordings, $retryRecord)) {
                    $changed = true;
                    continue;
                }
                $this->retryStateStore()->deferWithBackoff(
                    $recordId,
                    RetryStateStore::TYPE_SKIPPED,
                    self::SCAN_INTERVAL_SECONDS,
                    self::MAX_SKIPPED_RETRY_SECONDS
                );
                continue;
            }

            if (!$this->recordingWithinSizeLimit($recordingPath)) {
                if ($this->expireSkippedRetry($skippedRecordings, $retryRecord)) {
                    $changed = true;
                    continue;
                }
                $this->retryStateStore()->deferWithBackoff(
                    $recordId,
                    RetryStateStore::TYPE_SKIPPED,
                    self::SCAN_INTERVAL_SECONDS,
                    self::MAX_SKIPPED_RETRY_SECONDS
                );
                continue;
            }

            $duration = $this->recordingDurationDecision($record, $recordingPath);
            if ($duration['state'] !== 'allowed') {
                $reason = $duration['state'] === 'too_long' ? 'duration_too_long' : 'duration_probe_failed';
                if (
                    $reason !== (string)$retryRecord['reason']
                    || $duration['source'] !== trim((string)($record['recording_duration_source'] ?? ''))
                    || $duration['duration_sec'] !== $this->recordingDurationSeconds($record)
                ) {
                    $record = $this->recordWithDurationDecision($record, $duration);
                    $this->rememberSkippedRecording($record, $reason, $recordingPath, $recordId);
                }
                $retryRecord['reason'] = $reason;
                if ($this->expireSkippedRetry($skippedRecordings, $retryRecord)) {
                    $changed = true;
                    continue;
                }
                $this->retryStateStore()->deferWithBackoff(
                    $recordId,
                    RetryStateStore::TYPE_SKIPPED,
                    self::SCAN_INTERVAL_SECONDS,
                    self::MAX_SKIPPED_RETRY_SECONDS
                );
                continue;
            }

            if (!STTJob::existsForFilePath($recordingPath)) {
                $job = STTJob::createPending(
                    (string)($record['call_id'] ?? ''),
                    $recordingPath,
                    $this->priorityForDuration($duration['duration_sec']),
                    (int)($record['cdr_endtime'] ?? 0) ?: null,
                    $this->recordingIdentity($record, $recordingPath),
                    $duration['duration_sec']
                );
                $this->logJobCreated(
                    $job,
                    $duration['duration_sec'],
                    'skipped_recording_recovered'
                );
            }

            unset($skippedRecordings[$recordId]);
            $this->retryStateStore()->remove($recordId);
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

        $isNew = !isset($delayedRecordings[$recordId]);
        $now = time();
        $retryRecord = [
            'recordingfile' => (string)($record['recordingfile'] ?? ''),
            'call_id' => $this->callId($record),
            'priority' => $this->priority($record),
            'endtime' => $this->endtime($record),
            'cdr_endtime' => $this->recordEndtimeTimestamp($record),
            'cdr_id' => $this->recordId($record),
            'cdr_uniqueid' => trim((string)($record['UNIQUEID'] ?? '')),
            'cdr_start_ms' => $this->dateTimeMilliseconds((string)($record['start'] ?? '')),
            'cdr_end_ms' => $this->dateTimeMilliseconds((string)($record['endtime'] ?? '')),
            'recording_duration_sec' => $this->recordingDurationSeconds($record),
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
                    'recording_duration_ms' => $this->recordingDurationSeconds($record) !== null
                        ? $this->recordingDurationSeconds($record) * 1000
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
        $isNew = !isset($skippedRecordings[$recordId]);
        $retryRecord = [
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
            'recording_duration_sec' => $this->recordingDurationSeconds($record),
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
                    'recording_duration_ms' => $this->recordingDurationSeconds($record) !== null
                        ? $this->recordingDurationSeconds($record) * 1000
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
        $this->retryStateStore()->remove($recordId);
    }

    private function retryStateStore(): RetryStateStore
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
    private function expireSkippedRetry(array &$skippedRecordings, array $retryRecord): bool
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
    private function saveDelayedRecordings(array $delayedRecordings): void
    {
        STTSetting::setValue(
            STTSetting::DELAYED_RECORDINGS,
            json_encode($delayedRecordings, JSON_THROW_ON_ERROR)
        );
    }

    private function scanCursor(): int
    {
        return (int)STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID, '0');
    }

    /**
     * Prepares the id-based realtime cursor before every scan.
     * CDR_SCAN_CURSOR_ENDTIME is no longer part of the realtime *query* (see
     * createRealtimeJobsFromCompletedCdr()), but it is still read here, for
     * two different purposes depending on CDR_SCAN_CURSOR_MODE:
     *
     * - Fresh install (marker empty): start the cursor at the current
     *   MAX(id) so realtime picks up only calls completed from now on;
     *   existing history is covered by the separate backfill scan. Mode is
     *   set to 'id' directly - there is no legacy cursor to migrate from.
     * - Legacy install (marker set, mode not yet 'id'): the marker still
     *   holds a real (endtime, id) cursor written by a prior run of the old
     *   endtime-ordered scanner. Reusing CDR_SCAN_CURSOR_ID as-is here would
     *   be wrong: under (endtime, id) ordering the stored id is the id of
     *   the row with the greatest *endtime* processed so far, not the
     *   greatest id, so any still-pending backlog row with a smaller id but
     *   a larger endtime (batch-limited scanning had not reached it yet)
     *   would be permanently skipped by a naive `id > old_id` cursor. See
     *   migrateRealtimeScanCursorToIdMode() for the actual derivation.
     * - Mode already 'id': nothing to do, cursor is used as-is.
     */
    private function initializeRealtimeScanCursor(): void
    {
        $endtimeMarker = trim(STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, ''));
        if ($endtimeMarker === '') {
            $maxId = (int)(CallDetailRecords::maximum(['column' => 'id']) ?: 0);
            $this->saveScanCursor($maxId);
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, date('Y-m-d H:i:s'));
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_MODE, STTSetting::CDR_SCAN_CURSOR_MODE_ID);
            return;
        }

        if (STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_MODE, '') === STTSetting::CDR_SCAN_CURSOR_MODE_ID) {
            return;
        }

        $this->migrateRealtimeScanCursorToIdMode($endtimeMarker);
    }

    /**
     * One-time migration from the legacy (endtime, id) realtime cursor to a
     * plain id watermark. Must find the smallest id among CDR rows the OLD
     * query would still have returned (same base filters, same
     * (endtime > :legacy_endtime: OR (endtime = :legacy_endtime: AND
     * id > :legacy_id:)) backlog condition) and back the new cursor off to
     * just below it, so nothing already pending under the old ordering gets
     * skipped by the new `id > cursor` condition. When no such backlog row
     * exists, the legacy id is already a safe id-cursor value as-is.
     *
     * The cursor is persisted before the mode marker: if the process is
     * interrupted in between, CDR_SCAN_CURSOR_MODE is still not 'id' on the
     * next run, so this method runs again and recomputes the exact same
     * result from the still-untouched legacy endtime/id pair - idempotent,
     * not a partial or duplicated migration.
     */
    private function migrateRealtimeScanCursorToIdMode(string $legacyEndtime): void
    {
        $legacyId = (int)STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID, '0');
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        $conditions = 'recordingfile <> :empty: AND endtime <> :empty: AND work_completed = :completed:'
            . ' AND (endtime > :legacy_endtime: OR (endtime = :legacy_endtime: AND id > :legacy_id:))';
        $bind = [
            'empty' => '',
            'completed' => '1',
            'legacy_endtime' => $legacyEndtime,
            'legacy_id' => $legacyId,
        ];
        if ($historyStart !== '') {
            $conditions .= ' AND endtime >= :history_start:';
            $bind['history_start'] = $historyStart;
        }

        $pendingMinId = CallDetailRecords::minimum([
            'column' => 'id',
            'conditions' => $conditions,
            'bind' => $bind,
        ]);
        $pendingMinId = $pendingMinId === null || $pendingMinId === '' ? null : (int)$pendingMinId;

        $newCursorId = $pendingMinId !== null ? min($legacyId, $pendingMinId - 1) : $legacyId;

        $this->saveScanCursor($newCursorId);
        STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_MODE, STTSetting::CDR_SCAN_CURSOR_MODE_ID);

        LocalSpeechToTextLogger::event(
            'scanner',
            'realtime_cursor_migrated',
            'Realtime CDR scan cursor migrated from endtime ordering to id ordering',
            LOG_NOTICE,
            [
                'legacy_endtime' => $legacyEndtime,
                'legacy_id' => $legacyId,
                'new_cursor_id' => $newCursorId,
            ]
        );
    }

    private function saveScanCursor(int $recordId): void
    {
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
        STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ID, (string)max(0, $recordId));
        STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, $endtime);
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
        return $this->priorityForDuration($this->recordingDurationSeconds($record));
    }

    private function priorityForDuration(?int $duration): int
    {
        if ($duration !== null && $duration >= self::LONG_CALL_SECONDS) {
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

    /**
     * @param array<string, mixed> $record
     */
    private function recordingDurationSeconds(array $record): ?int
    {
        return STTSetting::normalizeRecordingDurationSeconds(
            $record['recording_duration_sec'] ?? $record['duration'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array{state: 'allowed'|'too_long'|'probe_failed', duration_sec: int|null, source: string}
     */
    private function recordingDurationDecision(array $record, string $recordingPath): array
    {
        $durationSeconds = $this->recordingDurationSeconds($record);
        $source = trim((string)($record['recording_duration_source'] ?? ''));
        if ($source === 'media_probe') {
            if ($durationSeconds === null) {
                return [
                    'state' => 'probe_failed',
                    'duration_sec' => null,
                    'source' => '',
                ];
            }
            return [
                'state' => STTSetting::recordingDurationAllowed($durationSeconds) ? 'allowed' : 'too_long',
                'duration_sec' => $durationSeconds,
                'source' => $source,
            ];
        }
        if ($durationSeconds !== null && STTSetting::recordingDurationAllowed($durationSeconds)) {
            return [
                'state' => 'allowed',
                'duration_sec' => $durationSeconds,
                'source' => $durationSeconds === null ? '' : 'cdr',
            ];
        }

        $probe = $this->recordingDurationProbe()->probe($recordingPath);
        if ($probe['status'] !== RecordingDurationProbe::STATUS_OK || $probe['duration_sec'] === null) {
            return [
                'state' => 'probe_failed',
                'duration_sec' => $durationSeconds,
                'source' => '',
            ];
        }

        return [
            'state' => STTSetting::recordingDurationAllowed($probe['duration_sec']) ? 'allowed' : 'too_long',
            'duration_sec' => $probe['duration_sec'],
            'source' => 'media_probe',
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array{state: string, duration_sec: int|null, source: string} $duration
     * @return array<string, mixed>
     */
    private function recordWithDurationDecision(array $record, array $duration): array
    {
        $record['recording_duration_sec'] = $duration['duration_sec'];
        $record['recording_duration_source'] = $duration['source'];

        return $record;
    }

    private function recordingDurationProbe(): RecordingDurationProbe
    {
        if (!$this->recordingDurationProbe instanceof RecordingDurationProbe) {
            $this->recordingDurationProbe = new RecordingDurationProbe();
        }

        return $this->recordingDurationProbe;
    }

    private function logJobCreated(STTJob $job, ?int $recordingDurationSeconds, ?string $reason = null): void
    {
        LocalSpeechToTextLogger::event(
            'queue',
            'job_created',
            'Transcription job created',
            LOG_INFO,
            [
                'job_id' => (int)$job->id,
                'recording_duration_ms' => $recordingDurationSeconds !== null
                    ? $recordingDurationSeconds * 1000
                    : null,
                'skip_reason' => $reason,
            ]
        );
    }
}

WorkerLocalSpeechToTextJobs::startWorker($argv ?? []);
