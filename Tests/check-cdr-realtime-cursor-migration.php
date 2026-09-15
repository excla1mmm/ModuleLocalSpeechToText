<?php

declare(strict_types=1);

/**
 * Regression test for the realtime cursor's legacy-to-id migration
 * (WorkerLocalSpeechToTextJobs::migrateRealtimeScanCursorToIdMode()).
 *
 * Reproduces the exact counter-example that showed naively reusing the
 * legacy CDR_SCAN_CURSOR_ID as an id watermark is wrong: under the old
 * (endtime, id) ordering, the stored cursor id is the id of the row with
 * the greatest *endtime* processed so far - not the greatest id. A row
 * inserted earlier (smaller id) but with a later endtime can still be
 * sitting in the old scanner's backlog when the upgrade happens:
 *
 *   - row C: endtime 10:05, id 120 - already processed by the legacy
 *     scanner; its (endtime, id) pair is the stored cursor.
 *   - row R: endtime 10:10, id 100 - inserted *before* C (smaller id) but
 *     with a *later* endtime, so the legacy scanner still considers it
 *     pending (endtime 10:10 > cursor endtime 10:05).
 *
 * A naive `id > 120` cursor would permanently skip R (id 100 < 120). The
 * migration must instead compute the smallest id among rows the legacy
 * query would still return, and back the new cursor off to just below it.
 */

namespace MikoPBX\Common\Models {
    final class CallDetailRecords
    {
        /** @var array<int, array<string, mixed>> */
        public static array $rows = [];
        public static int $findCalls = 0;
        public static int $minimumCalls = 0;
        /** @var array<int, array<string, mixed>> */
        public static array $capturedFindBinds = [];

        /** @param array<string, mixed> $parameters */
        public static function maximum(array $parameters): int
        {
            $ids = array_map(static fn (array $row): int => (int)$row['id'], self::$rows);

            return $ids === [] ? 0 : max($ids);
        }

        /**
         * Mirrors migrateRealtimeScanCursorToIdMode()'s exact predicate:
         * the same base filters as the realtime query, plus the legacy
         * (endtime, id) "still pending" backlog condition.
         *
         * @param array<string, mixed> $parameters
         */
        public static function minimum(array $parameters): ?int
        {
            self::$minimumCalls++;
            $bind = is_array($parameters['bind'] ?? null) ? $parameters['bind'] : [];
            $legacyEndtime = (string)($bind['legacy_endtime'] ?? '');
            $legacyId = (int)($bind['legacy_id'] ?? 0);

            $matches = array_filter(self::$rows, static function (array $row) use ($legacyEndtime, $legacyId): bool {
                if (!self::matchesBaseFilters($row)) {
                    return false;
                }
                $endtime = (string)$row['endtime'];

                return $endtime > $legacyEndtime || ($endtime === $legacyEndtime && (int)$row['id'] > $legacyId);
            });

            if ($matches === []) {
                return null;
            }

            return min(array_map(static fn (array $row): int => (int)$row['id'], $matches));
        }

        /** @param array<string, mixed> $parameters */
        public static function find(array $parameters): object
        {
            self::$findCalls++;
            $bind = is_array($parameters['bind'] ?? null) ? $parameters['bind'] : [];
            self::$capturedFindBinds[] = $bind;
            $cursorId = (int)($bind['cursor_id'] ?? 0);

            $matches = array_values(array_filter(
                self::$rows,
                static fn (array $row): bool => self::matchesBaseFilters($row) && (int)$row['id'] > $cursorId
            ));
            usort($matches, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
            $limit = (int)($parameters['limit'] ?? count($matches));
            $matches = array_slice($matches, 0, max(0, $limit));

            return new class ($matches) {
                public function __construct(private readonly array $rows)
                {
                }

                public function toArray(): array
                {
                    return $this->rows;
                }
            };
        }

        /** @param array<string, mixed> $row */
        private static function matchesBaseFilters(array $row): bool
        {
            return (string)($row['recordingfile'] ?? '') !== ''
                && (string)($row['endtime'] ?? '') !== ''
                && (string)($row['work_completed'] ?? '') === '1';
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
        /** @var array<int, array{component: string, event: string, message: string, level: int, context: array}> */
        public static array $events = [];

        public static function event(string $component, string $event, string $message, int $level = LOG_INFO, array $context = []): void
        {
            self::$events[] = [
                'component' => $component,
                'event' => $event,
                'message' => $message,
                'level' => $level,
                'context' => $context,
            ];
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
        public static array $createdForPaths = [];
        public static ?DiscoveryBatchConnection $connection = null;

        public function getWriteConnection(): DiscoveryBatchConnection
        {
            return self::$connection ?? throw new \RuntimeException('Missing test connection');
        }

        public static function existsForFilePath(string $path): bool
        {
            return in_array($path, self::$createdForPaths, true);
        }

        public static function createPending(
            string $callId,
            string $filePath,
            int $priority,
            ?int $cdrEndtime = null,
            array $identity = [],
            ?int $recordingDurationSec = null
        ): self {
            self::$createdForPaths[] = $filePath;

            return new self();
        }
    }

    final class STTSetting
    {
        public const string DELAYED_RECORDINGS = 'delayed_recordings';
        public const string SKIPPED_RECORDINGS = 'skipped_recordings';
        public const string CDR_SCAN_CURSOR_ENDTIME = 'cdr_scan_cursor_endtime';
        public const string CDR_SCAN_CURSOR_ID = 'cdr_scan_cursor_id';
        public const string CDR_SCAN_CURSOR_MODE = 'cdr_scan_cursor_mode';
        public const string CDR_SCAN_CURSOR_MODE_ID = 'id';
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
                self::CDR_BACKFILL_DONE => '1',
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
    use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;

    function expectCursorMigration(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    require_once __DIR__ . '/../Lib/Services/CdrDiscoveryBackoffPolicy.php';
    require_once __DIR__ . '/../Lib/Services/RetryStateStore.php';
    require_once __DIR__ . '/../Lib/Services/CdrRecordEvaluator.php';
    require_once __DIR__ . '/../Lib/Services/RecordingRetryCache.php';
    require_once __DIR__ . '/../Lib/Services/CdrDiscoveryScanner.php';
    require_once __DIR__ . '/../Lib/Services/RecordingRetryProcessor.php';

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for the realtime cursor migration test\n");
        exit(1);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    STTJob::$connection = new \Modules\ModuleLocalSpeechToText\Models\DiscoveryBatchConnection($pdo);
    (new \Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore(STTJob::$connection))->ensureSchema();

    $testRoot = sys_get_temp_dir() . '/module-local-stt-cursor-migration-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($testRoot, 0700, true);
    file_put_contents($testRoot . '/Globals.php', "<?php\n");
    $workingDirectory = getcwd();
    chdir($testRoot);
    require_once __DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? __DIR__ : $workingDirectory);

    // Row C: already processed by the legacy (endtime, id) scanner - its
    // (endtime, id) pair is exactly the stored legacy cursor.
    CallDetailRecords::$rows[] = [
        'id' => 120,
        'recordingfile' => 'exten/call-c.wav',
        'UNIQUEID' => 'call-c',
        'linkedid' => 'call-c',
        'endtime' => '2026-08-10 10:05:00',
        'work_completed' => '1',
        'duration' => 30,
    ];
    // Row R: the backlog counter-example - inserted before C (smaller id)
    // but finished later (later endtime), so the legacy scanner still
    // considers it pending.
    CallDetailRecords::$rows[] = [
        'id' => 100,
        'recordingfile' => 'exten/call-r.wav',
        'UNIQUEID' => 'call-r',
        'linkedid' => 'call-r',
        'endtime' => '2026-08-10 10:10:00',
        'work_completed' => '1',
        'duration' => 45,
    ];

    STTSetting::reset();
    STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, '2026-08-10 10:05:00');
    STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ID, '120');
    // CDR_SCAN_CURSOR_MODE deliberately left unset: this is a legacy install
    // that predates the id-mode cursor.

    $worker = new WorkerLocalSpeechToTextJobs();
    $initializeCursor = new ReflectionMethod($worker, 'initializeRealtimeScanCursor');
    $createRealtime = new ReflectionMethod($worker, 'createRealtimeJobsFromCompletedCdr');
    $delayedRecordingsMethod = new ReflectionMethod($worker, 'delayedRecordings');

    $initializeCursor->invoke($worker);

    expectCursorMigration(
        STTSetting::CDR_SCAN_CURSOR_MODE_ID,
        STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_MODE),
        'Migration must switch the cursor mode to id'
    );
    // min(legacy_id=120, pendingMinId=100 - 1=99) = 99.
    expectCursorMigration(
        '99',
        STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID),
        'Migration must back the cursor off to just below row R\'s id, not reuse the legacy id (120) verbatim'
    );
    expectCursorMigration(1, CallDetailRecords::$minimumCalls, 'Migration must compute the legacy backlog minimum exactly once');

    $migrationEvents = array_values(array_filter(
        LocalSpeechToTextLogger::$events,
        static fn (array $event): bool => $event['event'] === 'realtime_cursor_migrated'
    ));
    expectCursorMigration(1, count($migrationEvents), 'Migration must log exactly one realtime_cursor_migrated event');
    expectCursorMigration('2026-08-10 10:05:00', $migrationEvents[0]['context']['legacy_endtime'] ?? null, 'Migration event must record the legacy endtime');
    expectCursorMigration(120, $migrationEvents[0]['context']['legacy_id'] ?? null, 'Migration event must record the legacy id');
    expectCursorMigration(99, $migrationEvents[0]['context']['new_cursor_id'] ?? null, 'Migration event must record the new cursor id');

    // With the migrated cursor (99), row R (id 100) - which a naive `id >
    // 120` cursor would have skipped forever - must now be scanned.
    $delayedRecordings = $delayedRecordingsMethod->invoke($worker);
    $rowCount = $createRealtime->invokeArgs($worker, [&$delayedRecordings, '', 30]);
    expectCursorMigration(2, $rowCount, 'Both row R and row C must be scanned once the cursor sits below both of their ids');
    expectCursorMigration(
        true,
        isset($delayedRecordings[100]),
        'Row R (the counter-example backlog row) must reach processCompletedRecord() after migration'
    );
    expectCursorMigration('120', STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID), 'Cursor must advance past both rows after the post-migration scan');

    // A second call to initializeRealtimeScanCursor() (the next scan
    // iteration) must not re-migrate: mode is already 'id'.
    $initializeCursor->invoke($worker);
    expectCursorMigration(1, CallDetailRecords::$minimumCalls, 'Migration must not run a second time once mode is id');
    expectCursorMigration('120', STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID), 'A second initialize call must leave the cursor untouched');

    unlink($testRoot . '/Globals.php');
    rmdir($testRoot);

    echo "CDR realtime cursor legacy-to-id migration behavior OK\n";
}
