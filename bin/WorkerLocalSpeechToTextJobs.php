<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\bin;

require_once 'Globals.php';

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Providers\DatabaseProviderBase;
use MikoPBX\Core\Workers\WorkerBase;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallSummaryEvents;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CdrDiscoveryBackoffPolicy;
use Modules\ModuleLocalSpeechToText\Lib\Services\CdrDiscoveryScanner;
use Modules\ModuleLocalSpeechToText\Lib\Services\CdrRecordEvaluator;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\RecordingRetryCache;
use Modules\ModuleLocalSpeechToText\Lib\Services\RecordingRetryProcessor;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerDatabaseFailurePolicy;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerIterationFailureHandler;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerProcessGuard;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptRetentionCoordinator;
use Modules\ModuleLocalSpeechToText\Lib\Services\TransactionRecoveryException;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTSummary;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

class WorkerLocalSpeechToTextJobs extends WorkerBase
{
    private const int SCAN_INTERVAL_SECONDS = 15;
    // Internal CDR discovery budget: not a user setting. Mirrors
    // CdrDiscoveryScanner's own constant so it stays reflectable on this
    // class (see Tests/check-cdr-discovery-batch.php) without duplicating
    // the source of truth.
    private const int CDR_DISCOVERY_BATCH_SIZE = CdrDiscoveryScanner::CDR_DISCOVERY_BATCH_SIZE;
    // Mirrors RecordingRetryProcessor::RETRY_DUE_BATCH_LIMIT for the same reason.
    private const int RETRY_DUE_BATCH_LIMIT = RecordingRetryProcessor::RETRY_DUE_BATCH_LIMIT;
    private const int DISCRETIONARY_WORK_BUDGET_SECONDS = 10;

    private ?CdrRecordEvaluator $cdrRecordEvaluator = null;
    private ?RecordingRetryCache $recordingRetryCache = null;
    private ?CdrDiscoveryScanner $cdrDiscoveryScanner = null;
    private ?RecordingRetryProcessor $recordingRetryProcessor = null;
    private ?TranscriptRetentionCoordinator $transcriptRetentionCoordinator = null;
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
                        $scanPhase = 'call_summary_queue';
                        if (STTSetting::summarizationEnabled()) {
                            // Calls published since the last pass, and calls whose
                            // content changed after their summary was made.
                            $queued = STTSummary::enqueueMissing(STTSetting::summarizationEnabledAt());
                            $requeued = STTSummary::requeueChangedCalls();
                            if ($requeued > 0) {
                                LocalSpeechToTextLogger::event(
                                    'queue',
                                    'summaries_requeued',
                                    'Call summaries queued again after the call changed',
                                    LOG_NOTICE,
                                    ['requeued_count' => $requeued, 'queued_count' => $queued]
                                );
                            }
                        }
                        $scanPhase = 'call_summary_publish';
                        $summaryEvents = (new CallSummaryEvents())->publishReady((new STTCallTranscriptEvent())->getWriteConnection());
                        if ($summaryEvents > 0) {
                            LocalSpeechToTextLogger::event(
                                'transcript',
                                'call_summaries_published',
                                'Call summary events published',
                                LOG_NOTICE,
                                ['published_count' => $summaryEvents]
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
        $this->transcriptRetentionCoordinator()->purgeExpiredTranscripts();
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
        return $this->cdrDiscoveryScanner()->createJobsFromCompletedCdr(
            fn (): bool => $this->needRestart,
            fn (): bool => $this->iterationBudgetExhausted(),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @return int Number of CDR rows the realtime query returned.
     */
    private function createRealtimeJobsFromCompletedCdr(
        array &$delayedRecordings,
        string $historyStart,
        int $scanLimit = self::CDR_DISCOVERY_BATCH_SIZE
    ): int
    {
        return $this->cdrDiscoveryScanner()->createRealtimeJobsFromCompletedCdr(
            $delayedRecordings,
            $historyStart,
            $scanLimit,
            fn (): bool => $this->needRestart,
            fn (): bool => $this->iterationBudgetExhausted(),
        );
    }

    private function backfillCompletedFor(string $historyStart): bool
    {
        return $this->cdrDiscoveryScanner()->backfillCompletedFor($historyStart);
    }

    private function markBackfillCompleted(string $historyStart): void
    {
        $this->cdrDiscoveryScanner()->markBackfillCompleted($historyStart);
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<string, mixed> $record
     */
    private function processCompletedRecord(array &$delayedRecordings, array $record, string $historyStart): void
    {
        $this->cdrDiscoveryScanner()->processCompletedRecord($delayedRecordings, $record, $historyStart);
    }

    private function retryDelayedRecordings(): void
    {
        $this->recordingRetryProcessor()->retryDelayedRecordings(
            fn (): bool => $this->needRestart,
            fn (): bool => $this->iterationBudgetExhausted(),
        );
    }

    private function retrySkippedRecordings(): void
    {
        $this->recordingRetryProcessor()->retrySkippedRecordings(
            fn (): bool => $this->needRestart,
            fn (): bool => $this->iterationBudgetExhausted(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function delayedRecordings(): array
    {
        return $this->recordingRetryCache()->delayedRecordings();
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<string, mixed> $record
     */
    private function rememberDelayedRecording(array &$delayedRecordings, array $record): void
    {
        $this->recordingRetryCache()->rememberDelayedRecording($delayedRecordings, $record);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function rememberSkippedRecording(array $record, string $reason, string $recordingPath = '', int $recordId = 0): void
    {
        $this->recordingRetryCache()->rememberSkippedRecording($record, $reason, $recordingPath, $recordId);
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     */
    private function saveDelayedRecordings(array $delayedRecordings): void
    {
        $this->recordingRetryCache()->saveDelayedRecordings($delayedRecordings);
    }

    private function saveScanCursor(int $recordId): void
    {
        $this->cdrDiscoveryScanner()->saveScanCursor($recordId);
    }

    private function saveBackfillCursor(string $endtime, int $recordId): void
    {
        $this->cdrDiscoveryScanner()->saveBackfillCursor($endtime, $recordId);
    }

    private function initializeRealtimeScanCursor(): void
    {
        $this->cdrDiscoveryScanner()->initializeRealtimeScanCursor();
    }

    private function cdrRecordEvaluator(): CdrRecordEvaluator
    {
        if (!$this->cdrRecordEvaluator instanceof CdrRecordEvaluator) {
            $this->cdrRecordEvaluator = new CdrRecordEvaluator();
        }

        return $this->cdrRecordEvaluator;
    }

    private function recordingRetryCache(): RecordingRetryCache
    {
        if (!$this->recordingRetryCache instanceof RecordingRetryCache) {
            $this->recordingRetryCache = new RecordingRetryCache($this->cdrRecordEvaluator());
        }

        return $this->recordingRetryCache;
    }

    private function cdrDiscoveryScanner(): CdrDiscoveryScanner
    {
        if (!$this->cdrDiscoveryScanner instanceof CdrDiscoveryScanner) {
            $this->cdrDiscoveryScanner = new CdrDiscoveryScanner(
                $this->cdrRecordEvaluator(),
                $this->recordingRetryCache()
            );
        }

        return $this->cdrDiscoveryScanner;
    }

    private function recordingRetryProcessor(): RecordingRetryProcessor
    {
        if (!$this->recordingRetryProcessor instanceof RecordingRetryProcessor) {
            $this->recordingRetryProcessor = new RecordingRetryProcessor(
                $this->cdrRecordEvaluator(),
                $this->recordingRetryCache(),
                self::SCAN_INTERVAL_SECONDS
            );
        }

        return $this->recordingRetryProcessor;
    }

    private function transcriptRetentionCoordinator(): TranscriptRetentionCoordinator
    {
        if (!$this->transcriptRetentionCoordinator instanceof TranscriptRetentionCoordinator) {
            $this->transcriptRetentionCoordinator = new TranscriptRetentionCoordinator();
        }

        return $this->transcriptRetentionCoordinator;
    }
}

WorkerLocalSpeechToTextJobs::startWorker($argv ?? []);
