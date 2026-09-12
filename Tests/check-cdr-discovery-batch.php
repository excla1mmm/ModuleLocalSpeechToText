<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    final class CallDetailRecords
    {
        /** @var array<int, array<int, array<string, mixed>>> Queue of canned result pages, consumed in call order. */
        public static array $pages = [];
        /** @var array<int, int> Captured 'limit' bind for every find() call. */
        public static array $capturedLimits = [];
        public static int $findCalls = 0;
        public static bool $throwOnNextFind = false;

        public static function find(array $parameters): object
        {
            self::$findCalls++;
            self::$capturedLimits[] = (int)($parameters['limit'] ?? 0);
            if (self::$throwOnNextFind) {
                self::$throwOnNextFind = false;
                throw new \PDOException('database is locked', 5);
            }
            $rows = array_shift(self::$pages) ?? [];

            return new class ($rows) {
                public function __construct(private readonly array $rows)
                {
                }

                public function toArray(): array
                {
                    return $this->rows;
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

        public function probe(string $recordingPath): array
        {
            return ['status' => self::STATUS_OK, 'duration_sec' => 5];
        }
    }

    final class LocalSpeechToTextLogger
    {
        public static function event(string $component, string $event, string $message, int $level = LOG_INFO, array $context = []): void
        {
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
    final class DiscoveryBatchConnection
    {
        public function __construct(private readonly \PDO $pdo)
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
        public static int $createdCount = 0;
        public static ?DiscoveryBatchConnection $connection = null;

        public function getWriteConnection(): DiscoveryBatchConnection
        {
            return self::$connection ?? throw new \RuntimeException('Missing test connection');
        }

        public static function existsForFilePath(string $path): bool
        {
            return false;
        }

        public static function createPending(
            string $callId,
            string $filePath,
            int $priority,
            ?int $cdrEndtime = null,
            array $identity = [],
            ?int $recordingDurationSec = null
        ): self {
            self::$createdCount++;

            return new self();
        }
    }

    final class STTSetting
    {
        public const string DELAYED_RECORDINGS = 'delayed_recordings';
        public const string SKIPPED_RECORDINGS = 'skipped_recordings';
        public const string CDR_SCAN_CURSOR_ENDTIME = 'cdr_scan_cursor_endtime';
        public const string CDR_SCAN_CURSOR_ID = 'cdr_scan_cursor_id';
        public const string CDR_BACKFILL_CURSOR_ENDTIME = 'cdr_backfill_cursor_endtime';
        public const string CDR_BACKFILL_CURSOR_ID = 'cdr_backfill_cursor_id';
        public const string CDR_BACKFILL_DONE = 'cdr_backfill_done';
        public const string CDR_BACKFILL_DONE_HISTORY_AT = 'cdr_backfill_done_history_at';
        /** @var array<string, string> */
        public static array $values = [];

        public static function reset(): void
        {
            self::$values = [
                self::DELAYED_RECORDINGS => '[]',
                self::SKIPPED_RECORDINGS => '[]',
                self::CDR_BACKFILL_DONE => '0',
            ];
        }

        public static function getValue(string $key, string $default = ''): string
        {
            return self::$values[$key] ?? $default;
        }

        public static function setValue(string $key, string $value): void
        {
            self::$values[$key] = $value;
        }

        public static function cdrHistoryStartDateTime(): string
        {
            return '';
        }

        public static function maxFileSizeBytes(): int
        {
            return 1024 * 1024;
        }

        public static function normalizeRecordingDurationSeconds(mixed $value): ?int
        {
            return null;
        }

        public static function recordingDurationAllowed(mixed $value): bool
        {
            return true;
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
    use MikoPBX\Common\Models\CallDetailRecords;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;

    function expectDiscoveryBatch(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    require_once __DIR__ . '/../Lib/Services/CdrDiscoveryBackoffPolicy.php';
    require_once __DIR__ . '/../Lib/Services/RetryStateStore.php';

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for CDR discovery batch tests\n");
        exit(1);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \Modules\ModuleLocalSpeechToText\Models\STTJob::$connection = new \Modules\ModuleLocalSpeechToText\Models\DiscoveryBatchConnection($pdo);
    (new \Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore(\Modules\ModuleLocalSpeechToText\Models\STTJob::$connection))->ensureSchema();

    $testRoot = sys_get_temp_dir() . '/module-local-stt-discovery-batch-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($testRoot, 0700, true);
    file_put_contents($testRoot . '/Globals.php', "<?php\n");
    $workingDirectory = getcwd();
    chdir($testRoot);
    require_once __DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? __DIR__ : $workingDirectory);

    $reflectionClass = new ReflectionClass(WorkerLocalSpeechToTextJobs::class);
    expectDiscoveryBatch(
        30,
        $reflectionClass->getConstant('CDR_DISCOVERY_BATCH_SIZE'),
        'Internal CDR discovery batch size must be fixed at 30'
    );
    expectDiscoveryBatch(
        10,
        $reflectionClass->getConstant('RETRY_DUE_BATCH_LIMIT'),
        'Internal retry due-batch limit must be small and decoupled from the CDR batch size'
    );

    $makeRow = static function (int $id): array {
        return [
            'id' => $id,
            'recordingfile' => '', // Empty -> processCompletedRecord treats it as delayed, never creates a job.
            'UNIQUEID' => "call-{$id}",
            'endtime' => sprintf('2026-07-16 12:%02d:00', $id % 60),
            'duration' => 5,
        ];
    };

    // Scenario 1: realtime returns rows -> backfill must not run in the same pass.
    STTSetting::reset();
    CallDetailRecords::$pages = [array_map($makeRow, range(1, 5))];
    CallDetailRecords::$capturedLimits = [];
    CallDetailRecords::$findCalls = 0;
    $worker = new WorkerLocalSpeechToTextJobs();
    $createJobs = new ReflectionMethod($worker, 'createJobsFromCompletedCdr');
    $outcome = $createJobs->invoke($worker);
    expectDiscoveryBatch(1, CallDetailRecords::$findCalls, 'Non-empty realtime page must skip backfill in the same discovery pass');
    expectDiscoveryBatch([30], CallDetailRecords::$capturedLimits, 'Realtime query must request the fixed batch size of 30');
    expectDiscoveryBatch(['active' => true, 'idle' => false], $outcome, 'Non-empty realtime page must be classified as active');

    // Scenario 2: realtime empty, backfill returns a full page (30 rows) -> backlog still active, not marked done.
    STTSetting::reset();
    CallDetailRecords::$pages = [[], array_map($makeRow, range(1, 30))];
    CallDetailRecords::$capturedLimits = [];
    CallDetailRecords::$findCalls = 0;
    $worker = new WorkerLocalSpeechToTextJobs();
    $outcome = (new ReflectionMethod($worker, 'createJobsFromCompletedCdr'))->invoke($worker);
    expectDiscoveryBatch(2, CallDetailRecords::$findCalls, 'Empty realtime page must trigger exactly one backfill query');
    expectDiscoveryBatch([30, 30], CallDetailRecords::$capturedLimits, 'Backfill query must also request the fixed batch size of 30');
    expectDiscoveryBatch(['active' => true, 'idle' => false], $outcome, 'A full backfill page must be classified as active (more backlog likely)');
    expectDiscoveryBatch('0', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE), 'A full backfill page must leave backfill unfinished');

    // Scenario 3: realtime empty, backfill returns a short page (< 30 rows) -> backfill completes, idle confirmed.
    STTSetting::reset();
    CallDetailRecords::$pages = [[], array_map($makeRow, range(1, 5))];
    $worker = new WorkerLocalSpeechToTextJobs();
    $outcome = (new ReflectionMethod($worker, 'createJobsFromCompletedCdr'))->invoke($worker);
    expectDiscoveryBatch(['active' => true, 'idle' => false], $outcome, 'A non-empty short backfill page still did real work and must be classified as active');
    expectDiscoveryBatch('1', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE), 'A short backfill page must mark backfill completed');

    // Scenario 4: realtime empty, backfill empty (and completes) -> idle confirmed.
    STTSetting::reset();
    CallDetailRecords::$pages = [[], []];
    $worker = new WorkerLocalSpeechToTextJobs();
    $outcome = (new ReflectionMethod($worker, 'createJobsFromCompletedCdr'))->invoke($worker);
    expectDiscoveryBatch(['active' => false, 'idle' => true], $outcome, 'Empty realtime and empty (completed) backfill must be classified as idle');
    expectDiscoveryBatch('1', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE), 'An empty backfill page must mark backfill completed');

    // Scenario 5: realtime empty, backfill already marked done previously -> idle confirmed without another query.
    STTSetting::reset();
    STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE, '1');
    CallDetailRecords::$pages = [[]];
    CallDetailRecords::$findCalls = 0;
    $worker = new WorkerLocalSpeechToTextJobs();
    $outcome = (new ReflectionMethod($worker, 'createJobsFromCompletedCdr'))->invoke($worker);
    expectDiscoveryBatch(1, CallDetailRecords::$findCalls, 'Already-completed backfill must not run another CDR query');
    expectDiscoveryBatch(['active' => false, 'idle' => true], $outcome, 'Already-completed backfill with empty realtime must be classified as idle');

    // Scenario 6: a database failure during discovery must not be classified as idle.
    STTSetting::reset();
    CallDetailRecords::$pages = [];
    CallDetailRecords::$throwOnNextFind = true;
    $worker = new WorkerLocalSpeechToTextJobs();
    $runIfDue = new ReflectionMethod($worker, 'runCdrDiscoveryIfDue');
    $delayProperty = new ReflectionProperty($worker, 'discoveryDelaySeconds');
    $nextAtProperty = new ReflectionProperty($worker, 'nextDiscoveryAt');
    $delayProperty->setValue($worker, 180);
    $nextAtProperty->setValue($worker, 0);
    try {
        $runIfDue->invoke($worker);
        throw new RuntimeException('Expected database failure to propagate out of discovery');
    } catch (\PDOException) {
        // expected
    }
    expectDiscoveryBatch(180, $delayProperty->getValue($worker), 'A database failure during discovery must not shrink the backoff delay back to idle-growth or activity-reset');
    expectDiscoveryBatch(0, $nextAtProperty->getValue($worker), 'A database failure during discovery must not advance the next-discovery schedule, so it is retried on the very next tick');

    // Scenario 7: runCdrDiscoveryIfDue gates discovery by the schedule, and grows/reset the delay correctly.
    STTSetting::reset();
    CallDetailRecords::$pages = [[], []]; // idle: empty realtime + empty (completes) backfill
    CallDetailRecords::$findCalls = 0;
    $worker = new WorkerLocalSpeechToTextJobs();
    $runIfDue = new ReflectionMethod($worker, 'runCdrDiscoveryIfDue');
    $delayProperty = new ReflectionProperty($worker, 'discoveryDelaySeconds');
    $nextAtProperty = new ReflectionProperty($worker, 'nextDiscoveryAt');
    expectDiscoveryBatch(15, $delayProperty->getValue($worker), 'A freshly constructed worker must start with the 15 second base delay');
    expectDiscoveryBatch(0, $nextAtProperty->getValue($worker), 'A freshly constructed worker must be due for discovery immediately');

    $runIfDue->invoke($worker);
    expectDiscoveryBatch(2, CallDetailRecords::$findCalls, 'Discovery must run on the first, immediately-due tick (realtime + backfill since realtime was empty)');
    expectDiscoveryBatch(30, $delayProperty->getValue($worker), 'Idle discovery must grow the delay from 15 to 30');

    // Not yet due: pretend we're still inside the 30 second window.
    $nextAtProperty->setValue($worker, time() + 30);
    CallDetailRecords::$pages = [[], []];
    CallDetailRecords::$findCalls = 0;
    $runIfDue->invoke($worker);
    expectDiscoveryBatch(0, CallDetailRecords::$findCalls, 'Discovery must not run before its scheduled time');
    expectDiscoveryBatch(30, $delayProperty->getValue($worker), 'Delay must be unchanged while discovery is not due');

    // Force it due again and confirm activity resets the delay back to 15.
    $nextAtProperty->setValue($worker, 0);
    CallDetailRecords::$pages = [array_map($makeRow, range(1, 3))]; // realtime has rows -> active
    CallDetailRecords::$findCalls = 0;
    $runIfDue->invoke($worker);
    expectDiscoveryBatch(1, CallDetailRecords::$findCalls, 'Discovery must run again once due');
    expectDiscoveryBatch(15, $delayProperty->getValue($worker), 'Any activity must reset the delay back to the 15 second base');

    unlink($testRoot . '/Globals.php');
    rmdir($testRoot);

    echo "CDR discovery batch and backoff wiring OK\n";
}
