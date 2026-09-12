<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    final class CallDetailRecords
    {
        public static function find(array $parameters): object
        {
            return new class {
                public function toArray(): array
                {
                    return [];
                }
            };
        }
    }
}

namespace MikoPBX\Common\Providers {
    final class DatabaseProviderBase
    {
        public static function ensureCdrTables(): void
        {
        }
    }
}

namespace MikoPBX\Core\System {
    final class Directories
    {
        public const string AST_MONITOR_DIR = 'monitor';
        public static string $monitorDirectory = '';

        public static function getDir(string $directory): string
        {
            return self::$monitorDirectory;
        }
    }
}

namespace MikoPBX\Core\Workers {
    abstract class WorkerBase
    {
        protected bool $needRestart = false;

        public static function startWorker(array $arguments): void
        {
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    final class RecordingDurationProbe
    {
        public const string STATUS_OK = 'ok';
        public const string STATUS_UNAVAILABLE = 'unavailable';
        public const string STATUS_FAILED = 'failed';
        public const string STATUS_TIMEOUT = 'timeout';

        /** @var array<string, array{status: string, duration_sec: int|null}> */
        public static array $results = [];
        /** @var array<int, string> */
        public static array $calls = [];

        /** @return array{status: string, duration_sec: int|null} */
        public function probe(string $recordingPath): array
        {
            self::$calls[] = $recordingPath;

            return self::$results[$recordingPath] ?? [
                'status' => self::STATUS_FAILED,
                'duration_sec' => null,
            ];
        }
    }

    final class LocalSpeechToTextLogger
    {
        public static array $messages = [];

        public static function event(
            string $component,
            string $event,
            string $message,
            int $level = LOG_INFO,
            array $context = []
        ): void
        {
            self::$messages[] = compact('component', 'event', 'message', 'level', 'context');
        }
    }

    final class CallTranscriptRevisionService
    {
        public function publishReady(): void
        {
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    use PDO;

    final class RetryOverflowConnection
    {
        public function __construct(private readonly PDO $pdo)
        {
        }

        public function execute(string $sql, array $bind = []): bool
        {
            $statement = $this->pdo->prepare($sql);

            return $statement->execute($bind);
        }

        public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bind);

            return $statement->fetchAll($fetchMode);
        }
    }

    final class STTJob
    {
        public int $id = 0;
        public static ?RetryOverflowConnection $connection = null;
        /** @var array<string, array<string, mixed>> */
        public static array $jobs = [];

        public function getWriteConnection(): RetryOverflowConnection
        {
            return self::$connection ?? throw new \RuntimeException('Missing retry test connection');
        }

        public static function existsForFilePath(string $path): bool
        {
            return isset(self::$jobs[$path]);
        }

        public static function createPending(
            string $callId,
            string $filePath,
            int $priority,
            ?int $cdrEndtime = null,
            array $identity = [],
            ?int $recordingDurationSec = null
        ): self {
            if (isset(self::$jobs[$filePath])) {
                throw new \RuntimeException('Duplicate retry job for ' . $filePath);
            }
            self::$jobs[$filePath] = [
                'call_id' => $callId,
                'priority' => $priority,
                'cdr_endtime' => $cdrEndtime,
                'identity' => $identity,
                'recording_duration_sec' => $recordingDurationSec,
            ];

            $job = new self();
            $job->id = count(self::$jobs);

            return $job;
        }

        public static function requeueStaleProcessing(): int
        {
            return 0;
        }
    }

    final class STTSetting
    {
        public const string DELAYED_RECORDINGS = 'delayed_recordings';
        public const string SKIPPED_RECORDINGS = 'skipped_recordings';
        public const string CDR_SCAN_LIMIT = 'cdr_scan_limit';
        public const string CDR_SCAN_CURSOR_ENDTIME = 'cdr_scan_cursor_endtime';
        public const string CDR_SCAN_CURSOR_ID = 'cdr_scan_cursor_id';
        public const string CDR_BACKFILL_CURSOR_ENDTIME = 'cdr_backfill_cursor_endtime';
        public const string CDR_BACKFILL_CURSOR_ID = 'cdr_backfill_cursor_id';
        public const string CDR_BACKFILL_DONE = 'cdr_backfill_done';
        public const string CDR_BACKFILL_DONE_HISTORY_AT = 'cdr_backfill_done_history_at';
        /** @var array<string, string> */
        public static array $values = [
            self::DELAYED_RECORDINGS => '[]',
            self::SKIPPED_RECORDINGS => '[]',
            self::CDR_SCAN_LIMIT => '37',
        ];
        public static string $historyStart = '';
        public static int $maxRecordingDurationMinutes = 60;

        public static function getValue(string $key, string $default = ''): string
        {
            return self::$values[$key] ?? $default;
        }

        public static function setValue(string $key, string $value): void
        {
            self::$values[$key] = $value;
        }

        public static function cdrScanLimit(): int
        {
            return max(1, min(1000, (int)self::getValue(self::CDR_SCAN_LIMIT, '50')));
        }

        public static function cdrHistoryStartDateTime(): string
        {
            return self::$historyStart;
        }

        public static function maxFileSizeBytes(): int
        {
            return 1024 * 1024;
        }

        public static function normalizeRecordingDurationSeconds(mixed $value): ?int
        {
            if (!is_numeric($value) || (float)$value <= 0) {
                return null;
            }

            return (int)ceil((float)$value);
        }

        public static function recordingDurationAllowed(mixed $value): bool
        {
            $seconds = self::normalizeRecordingDurationSeconds($value);

            return $seconds === null || $seconds <= self::$maxRecordingDurationMinutes * 60;
        }

        public static function maxRecordingDurationMinutes(): int
        {
            return self::$maxRecordingDurationMinutes;
        }
    }

    final class STTWorker
    {
        public static function markStaleOffline(): void
        {
        }
    }
}

namespace {
    use MikoPBX\Core\System\Directories;
    use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
    use Modules\ModuleLocalSpeechToText\Lib\Services\RecordingDurationProbe;
    use Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore;
    use Modules\ModuleLocalSpeechToText\Models\RetryOverflowConnection;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;

    function expectWorkerRetry(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for worker retry overflow tests\n");
        exit(1);
    }

    require_once __DIR__ . '/../Lib/Services/RetryStateStore.php';
    require_once __DIR__ . '/../Lib/Services/CdrDiscoveryBackoffPolicy.php';

    $testRoot = sys_get_temp_dir() . '/module-local-stt-retry-worker-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $monitorDirectory = $testRoot . '/monitor';
    mkdir($monitorDirectory, 0700, true);
    file_put_contents($testRoot . '/Globals.php', "<?php\n");
    Directories::$monitorDirectory = $monitorDirectory;

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    STTJob::$connection = new RetryOverflowConnection($pdo);
    $store = new RetryStateStore(STTJob::$connection);
    $store->ensureSchema();

    $workingDirectory = getcwd();
    chdir($testRoot);
    require_once __DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? __DIR__ : $workingDirectory);

    $worker = new WorkerLocalSpeechToTextJobs();
    $rememberDelayed = new ReflectionMethod($worker, 'rememberDelayedRecording');
    $rememberSkipped = new ReflectionMethod($worker, 'rememberSkippedRecording');
    $saveDelayed = new ReflectionMethod($worker, 'saveDelayedRecordings');
    $retryDelayed = new ReflectionMethod($worker, 'retryDelayedRecordings');
    $retrySkipped = new ReflectionMethod($worker, 'retrySkippedRecordings');
    $processCompleted = new ReflectionMethod($worker, 'processCompletedRecord');

    $delayedCache = [];
    for ($id = 1; $id <= 501; $id++) {
        $record = [
            'id' => $id,
            'recordingfile' => "recording-{$id}.wav",
            'UNIQUEID' => "call-{$id}",
            'endtime' => '2026-07-16 12:00:00',
            'duration' => 10,
        ];
        $arguments = [&$delayedCache, $record];
        $rememberDelayed->invokeArgs($worker, $arguments);
    }
    $saveDelayed->invoke($worker, $delayedCache);

    for ($id = 502; $id <= 602; $id++) {
        $record = [
            'id' => $id,
            'recordingfile' => "recording-{$id}.wav",
            'UNIQUEID' => "call-{$id}",
            'endtime' => '2026-07-16 12:00:00',
            'duration' => 10,
        ];
        $rememberSkipped->invoke($worker, $record, 'file_too_large');
    }

    $delayedJson = json_decode(STTSetting::$values[STTSetting::DELAYED_RECORDINGS], true, 32, JSON_THROW_ON_ERROR);
    $skippedJson = json_decode(STTSetting::$values[STTSetting::SKIPPED_RECORDINGS], true, 32, JSON_THROW_ON_ERROR);
    expectWorkerRetry(count($delayedJson) === 500, 'Delayed display JSON must remain bounded at 500');
    expectWorkerRetry(count($skippedJson) === 100, 'Skipped display JSON must remain bounded at 100');
    expectWorkerRetry($store->count() === 602, 'Every overflowed CDR must remain in durable worker retry state');
    expectWorkerRetry(
        count(array_filter(
            LocalSpeechToTextLogger::$messages,
            static fn(array $message): bool => ($message['event'] ?? '') === 'retry_cache_overflow'
        )) >= 2,
        'Both display-cache overflows must be observable in logs'
    );

    for ($id = 1; $id <= 602; $id++) {
        file_put_contents($monitorDirectory . "/recording-{$id}.wav", 'audio');
    }

    $storeCountBeforeSingleTick = $store->count();
    $jobsBeforeSingleTick = count(STTJob::$jobs);
    $retryDelayed->invoke($worker);
    expectWorkerRetry(
        $storeCountBeforeSingleTick - $store->count() === 10 && count(STTJob::$jobs) - $jobsBeforeSingleTick === 10,
        'A single delayed-retry tick must process at most the small internal retry batch limit (10)'
    );

    // Retry batches are capped at a small internal limit (10 per type per tick,
    // decoupled from the 30-row CDR discovery batch) so ffprobe/filesystem retries
    // cannot spike load. With 501 delayed + 101 skipped records, draining needs at
    // least ceil(501/10) = 51 cycles; give it ample headroom.
    for ($cycle = 0; $cycle < 60 && $store->count() > 0; $cycle++) {
        $retryDelayed->invoke($worker);
        $retrySkipped->invoke($worker);
    }

    expectWorkerRetry($store->count() === 0, 'Worker retry cycles must drain every durable overflow record');
    expectWorkerRetry(count(STTJob::$jobs) === 602, 'Every eligible overflow CDR must produce exactly one job');
    $cdrIds = array_map(
        static fn(array $job): int => (int)($job['identity']['cdr_id'] ?? 0),
        STTJob::$jobs
    );
    expectWorkerRetry(count(array_unique($cdrIds)) === 602, 'Recovered jobs must preserve CDR deduplication identity');

    $durationCases = [
        8001 => ['file' => 'duration-boundary.wav', 'duration' => 3600],
        8002 => ['file' => 'duration-too-long.wav', 'duration' => 7200],
        8003 => ['file' => 'duration-unknown.wav', 'duration' => 0],
    ];
    $durationDelayedCache = [];
    RecordingDurationProbe::$results[(realpath($monitorDirectory) ?: $monitorDirectory) . '/duration-too-long.wav'] = [
        'status' => RecordingDurationProbe::STATUS_OK,
        'duration_sec' => 3601,
    ];
    foreach ($durationCases as $id => $case) {
        file_put_contents($monitorDirectory . '/' . $case['file'], 'audio');
        $record = [
            'id' => $id,
            'recordingfile' => $case['file'],
            'UNIQUEID' => 'duration-call-' . $id,
            'endtime' => '2026-07-17 12:00:00',
            'duration' => $case['duration'],
        ];
        $arguments = [&$durationDelayedCache, $record, ''];
        $processCompleted->invokeArgs($worker, $arguments);
    }

    $resolvedMonitorDirectory = realpath($monitorDirectory) ?: $monitorDirectory;
    $boundaryJob = STTJob::$jobs[$resolvedMonitorDirectory . '/duration-boundary.wav'] ?? null;
    expectWorkerRetry(
        is_array($boundaryJob) && $boundaryJob['recording_duration_sec'] === 3600,
        'Duration exactly at the limit must create a job and persist its duration'
    );
    expectWorkerRetry(
        !isset(STTJob::$jobs[$resolvedMonitorDirectory . '/duration-too-long.wav']),
        'Duration above the limit must not create a job'
    );
    $durationSkipped = json_decode(STTSetting::$values[STTSetting::SKIPPED_RECORDINGS], true, 32, JSON_THROW_ON_ERROR);
    expectWorkerRetry(
        ($durationSkipped[8002]['reason'] ?? '') === 'duration_too_long'
            && ($durationSkipped[8002]['recording_duration_sec'] ?? null) === 3601
            && ($durationSkipped[8002]['recording_duration_source'] ?? '') === 'media_probe',
        'Over-duration recordings must retain the measured duration and its source'
    );
    expectWorkerRetry(
        isset(STTJob::$jobs[$resolvedMonitorDirectory . '/duration-unknown.wav'])
            && STTJob::$jobs[$resolvedMonitorDirectory . '/duration-unknown.wav']['recording_duration_sec'] === null,
        'Unknown zero duration must remain eligible and persist as null'
    );

    $mismatchedPath = $monitorDirectory . '/duration-cdr-mismatch.webm';
    file_put_contents($mismatchedPath, 'webm-audio');
    $resolvedMismatchedPath = realpath($mismatchedPath) ?: $mismatchedPath;
    RecordingDurationProbe::$results[$resolvedMismatchedPath] = [
        'status' => RecordingDurationProbe::STATUS_OK,
        'duration_sec' => 9,
    ];
    $mismatchedRecord = [
        'id' => 8004,
        'recordingfile' => 'duration-cdr-mismatch.webm',
        'UNIQUEID' => 'duration-call-8004',
        'endtime' => '2026-07-17 12:00:00',
        'duration' => 515119,
    ];
    $arguments = [&$durationDelayedCache, $mismatchedRecord, ''];
    $processCompleted->invokeArgs($worker, $arguments);
    $mismatchJob = STTJob::$jobs[$resolvedMismatchedPath] ?? null;
    expectWorkerRetry(
        is_array($mismatchJob)
            && $mismatchJob['recording_duration_sec'] === 9
            && $mismatchJob['priority'] === 5,
        'A short media file with an overlong CDR duration must create a normal pending job using probed duration'
    );
    expectWorkerRetry(
        RecordingDurationProbe::$calls === [
            $resolvedMonitorDirectory . '/duration-too-long.wav',
            $resolvedMismatchedPath,
        ],
        'All and only over-limit CDR recordings must be probed'
    );

    $missingMismatchRecord = [
        'id' => 8005,
        'recordingfile' => 'duration-cdr-mismatch-missing.webm',
        'UNIQUEID' => 'duration-call-8005',
        'endtime' => '2026-07-17 12:00:00',
        'duration' => 515119,
    ];
    $arguments = [&$durationDelayedCache, $missingMismatchRecord, ''];
    $processCompleted->invokeArgs($worker, $arguments);
    expectWorkerRetry(
        isset($durationDelayedCache[8005]) && count(RecordingDurationProbe::$calls) === 2,
        'A missing over-limit CDR recording must remain delayed without invoking the media probe'
    );
    $store->remove(8005);

    STTSetting::$maxRecordingDurationMinutes = 120;
    $retrySkipped->invoke($worker);
    expectWorkerRetry(
        isset(STTJob::$jobs[$resolvedMonitorDirectory . '/duration-too-long.wav']) && $store->count() === 0,
        'Increasing the duration limit must allow skipped recordings to recover'
    );
    expectWorkerRetry(
        count(RecordingDurationProbe::$calls) === 2,
        'A verified media duration must be reused during retries without another probe'
    );
    STTSetting::$maxRecordingDurationMinutes = 60;

    $probeRetryPath = $monitorDirectory . '/duration-probe-retry.webm';
    file_put_contents($probeRetryPath, 'webm-audio');
    $resolvedProbeRetryPath = realpath($probeRetryPath) ?: $probeRetryPath;
    $probeRetryRecord = [
        'id' => 8006,
        'recordingfile' => 'duration-probe-retry.webm',
        'UNIQUEID' => 'duration-call-8006',
        'endtime' => '2026-07-17 12:00:00',
        'duration' => 515119,
    ];
    $arguments = [&$durationDelayedCache, $probeRetryRecord, ''];
    $processCompleted->invokeArgs($worker, $arguments);
    $probeFailureSkipped = json_decode(
        STTSetting::$values[STTSetting::SKIPPED_RECORDINGS],
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    expectWorkerRetry(
        ($probeFailureSkipped[8006]['reason'] ?? '') === 'duration_probe_failed'
            && !isset(STTJob::$jobs[$resolvedProbeRetryPath]),
        'An indeterminate media duration must remain in explicit retryable state'
    );
    RecordingDurationProbe::$results[$resolvedProbeRetryPath] = [
        'status' => RecordingDurationProbe::STATUS_OK,
        'duration_sec' => 10,
    ];
    $retrySkipped->invoke($worker);
    expectWorkerRetry(
        isset(STTJob::$jobs[$resolvedProbeRetryPath])
            && STTJob::$jobs[$resolvedProbeRetryPath]['recording_duration_sec'] === 10,
        'A duration probe failure must recover automatically after a successful retry'
    );

    STTSetting::$historyStart = '2026-07-17 00:00:00';
    $rememberSkipped->invoke($worker, [
        'id' => 700,
        'recordingfile' => 'old-recording.wav',
        'UNIQUEID' => 'old-call',
        'endtime' => '2026-07-16 12:00:00',
    ], 'recording_missing_timeout');
    $retrySkipped->invoke($worker);
    expectWorkerRetry($store->count() === 0, 'Skipped CDRs outside retention must stop consuming retry scans');

    STTSetting::$historyStart = '';
    $store->remember(702, RetryStateStore::TYPE_SKIPPED, [
        'recordingfile' => 'permanently-missing.wav',
        'call_id' => 'expired-call',
        'endtime' => '2026-07-16 12:00:00',
        'first_seen' => time() - 2592001,
    ], 'recording_missing_timeout', time() - 2592001);
    $retrySkipped->invoke($worker);
    expectWorkerRetry(
        $store->count() === 0,
        'Unlimited CDR history must still terminate permanently ineligible retry scans after a finite lifetime'
    );

    $oldProbeFailurePath = $monitorDirectory . '/duration-probe-old.webm';
    file_put_contents($oldProbeFailurePath, 'webm-audio');
    $store->remember(703, RetryStateStore::TYPE_SKIPPED, [
        'recordingfile' => 'duration-probe-old.webm',
        'call_id' => 'duration-probe-old-call',
        'endtime' => '2026-07-16 12:00:00',
        'recording_duration_sec' => 515119,
        'recording_duration_source' => '',
        'first_seen' => time() - 2592001,
    ], 'duration_probe_failed', time() - 2592001);
    $retrySkipped->invoke($worker);
    expectWorkerRetry(
        $store->count() === 1 && $store->due(RetryStateStore::TYPE_SKIPPED, 10, time()) === [],
        'An old duration probe failure must remain retryable while its CDR stays inside the history window'
    );
    $store->remove(703);

    $rememberSkipped->invoke($worker, [
        'id' => 701,
        'recordingfile' => 'still-missing.wav',
        'UNIQUEID' => 'missing-call',
        'endtime' => '2026-07-16 12:00:00',
    ], 'recording_missing_timeout');
    $retrySkipped->invoke($worker);
    expectWorkerRetry(
        $store->count() === 1 && $store->due(RetryStateStore::TYPE_SKIPPED, 10, time()) === [],
        'Still-potential skipped CDRs must remain durable but leave the immediate scan via backoff'
    );
    $store->remove(701);

    foreach (glob($monitorDirectory . '/*') ?: [] as $recording) {
        unlink($recording);
    }
    unlink($testRoot . '/Globals.php');
    rmdir($monitorDirectory);
    rmdir($testRoot);

    echo "Worker retry overflow behavior OK\n";
}
