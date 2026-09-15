<?php

declare(strict_types=1);

/**
 * Regression test for item 2: the realtime CDR scanner must use a strictly
 * id-based cursor, not an (endtime, id) cursor, so it never permanently
 * skips a leg of a transferred/multi-leg call.
 *
 * Core assigns cdr_general.id as a fresh autoincrement in *completion*
 * order (CallDetailRecordsTmp::afterSave() inserts a brand new row without
 * copying `id` when a linkedid's rows are moved out of the temp table; see
 * Core/src/Common/Models/CallDetailRecordsTmp.php and
 * Core/src/Core/Workers/WorkerCdr.php). For a transferred call, an early
 * leg's own `endtime` can be much older than the id it is eventually
 * assigned, because the whole call (and therefore that leg's insertion into
 * cdr_general) only finishes once every leg has hung up.
 *
 * This test reproduces exactly the scenario from the bug report: a short
 * call Y finishes and is scanned first, advancing the cursor. Only *after*
 * that does leg A of an earlier-starting, later-finishing transferred call
 * X get inserted - with a higher id, but an endtime that predates Y's. An
 * endtime-ordered cursor would permanently skip leg A; the id-based cursor
 * must pick it up on the very next scan.
 */

namespace MikoPBX\Common\Models {
    final class CallDetailRecords
    {
        /** @var array<int, array<string, mixed>> Rows "in the CDR table" at scan time. */
        public static array $rows = [];
        public static int $findCalls = 0;
        /** @var array<int, array<string, mixed>> Bind arrays captured for every find() call. */
        public static array $capturedBinds = [];

        /** @param array<string, mixed> $parameters */
        public static function maximum(array $parameters): int
        {
            $ids = array_map(static fn (array $row): int => (int)$row['id'], self::$rows);

            return $ids === [] ? 0 : max($ids);
        }

        /**
         * Applies the same predicate createRealtimeJobsFromCompletedCdr()
         * binds (recordingfile <> '', endtime <> '', work_completed = 1,
         * id > cursor_id[, endtime >= history_start]) against the in-memory
         * rows, ordered by id ASC - a faithful-enough stand-in for the real
         * SQLite query to exercise the cursor logic end to end.
         *
         * @param array<string, mixed> $parameters
         */
        public static function find(array $parameters): object
        {
            self::$findCalls++;
            $bind = is_array($parameters['bind'] ?? null) ? $parameters['bind'] : [];
            self::$capturedBinds[] = $bind;
            $cursorId = (int)($bind['cursor_id'] ?? 0);
            $historyStart = isset($bind['history_start']) ? (string)$bind['history_start'] : null;

            $matches = array_values(array_filter(
                self::$rows,
                static function (array $row) use ($cursorId, $historyStart): bool {
                    if ((string)($row['recordingfile'] ?? '') === '') {
                        return false;
                    }
                    if ((string)($row['endtime'] ?? '') === '') {
                        return false;
                    }
                    if ((string)($row['work_completed'] ?? '') !== '1') {
                        return false;
                    }
                    if ((int)$row['id'] <= $cursorId) {
                        return false;
                    }
                    if ($historyStart !== null && (string)$row['endtime'] < $historyStart) {
                        return false;
                    }

                    return true;
                }
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
        /** @var array<int, string> File paths a job was created for, in creation order. */
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
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;

    function expectMultilegCursor(mixed $expected, mixed $actual, string $message): void
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
        fwrite(STDERR, "pdo_sqlite is required for the multi-leg realtime cursor regression test\n");
        exit(1);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    STTJob::$connection = new \Modules\ModuleLocalSpeechToText\Models\DiscoveryBatchConnection($pdo);
    (new \Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore(STTJob::$connection))->ensureSchema();

    $testRoot = sys_get_temp_dir() . '/module-local-stt-multileg-cursor-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($testRoot, 0700, true);
    file_put_contents($testRoot . '/Globals.php', "<?php\n");
    $workingDirectory = getcwd();
    chdir($testRoot);
    require_once __DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? __DIR__ : $workingDirectory);

    // Recording files never resolve to a real file under the fake monitor
    // directory, so every matched row is filed as "delayed" rather than
    // turned into a job - that is fine: reaching processCompletedRecord()
    // (and therefore rememberDelayedRecording()) at all is exactly what
    // proves the row was not skipped by the cursor.
    STTSetting::reset();
    $worker = new WorkerLocalSpeechToTextJobs();
    $createRealtime = new ReflectionMethod($worker, 'createRealtimeJobsFromCompletedCdr');
    $initializeCursor = new ReflectionMethod($worker, 'initializeRealtimeScanCursor');
    $delayedRecordingsMethod = new ReflectionMethod($worker, 'delayedRecordings');

    // Simulate an already-running scanner that has already completed the
    // legacy-to-id cursor migration (not a fresh install, and not mid
    // migration - that path is covered by
    // check-cdr-realtime-cursor-migration.php): the cursor is already
    // initialized, in 'id' mode, and sitting at id 1.
    STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, '2026-08-10 09:00:00');
    STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ID, '1');
    STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_MODE, STTSetting::CDR_SCAN_CURSOR_MODE_ID);
    $initializeCursor->invoke($worker);
    expectMultilegCursor(
        '1',
        STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID),
        'An already-initialized, already-migrated cursor must not be reset by initializeRealtimeScanCursor()'
    );

    // Pass 1: short call Y finishes and is the only completed row so far.
    // Its endtime (10:02) is later than leg A's, even though leg A started
    // (and will finish) earlier in wall-clock terms.
    CallDetailRecords::$rows = [
        [
            'id' => 2,
            'recordingfile' => 'exten/call-y.wav',
            'UNIQUEID' => 'call-y',
            'linkedid' => 'call-y',
            'endtime' => '2026-08-10 10:02:00',
            'work_completed' => '1',
            'duration' => 30,
        ],
    ];
    CallDetailRecords::$findCalls = 0;
    $delayedRecordings = $delayedRecordingsMethod->invoke($worker);
    $rowCount = $createRealtime->invokeArgs($worker, [&$delayedRecordings, '', 30]);
    expectMultilegCursor(1, $rowCount, 'Pass 1 must process call Y');
    expectMultilegCursor('2', STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID), 'Cursor must advance to Y\'s id after pass 1');
    expectMultilegCursor(
        true,
        isset($delayedRecordings[2]),
        'Call Y must have reached processCompletedRecord() (recorded as delayed, since its file never resolves)'
    );

    // Pass 2: the transferred call X finishes. Leg A is only now inserted
    // into cdr_general (higher id than Y's, since it completed later), but
    // its own endtime (10:00) predates Y's (10:02) - this is exactly the
    // ordering inversion that made the old (endtime, id) cursor skip it
    // forever. It must still be picked up here because 3 > 2 (the id
    // cursor), regardless of its older endtime.
    CallDetailRecords::$rows = [
        [
            'id' => 3,
            'recordingfile' => 'exten/call-x-leg-a.wav',
            'UNIQUEID' => 'call-x-leg-a',
            'linkedid' => 'call-x',
            'endtime' => '2026-08-10 10:00:00',
            'work_completed' => '1',
            'duration' => 45,
        ],
    ];
    CallDetailRecords::$findCalls = 0;
    $delayedRecordings = $delayedRecordingsMethod->invoke($worker);
    $rowCount = $createRealtime->invokeArgs($worker, [&$delayedRecordings, '', 30]);
    expectMultilegCursor(
        1,
        $rowCount,
        'Pass 2 must process leg A of the transferred call, even though its endtime is older than the previously scanned row'
    );
    expectMultilegCursor(
        true,
        isset($delayedRecordings[3]),
        'Leg A must have reached processCompletedRecord() and not be silently dropped by the cursor'
    );
    expectMultilegCursor('3', STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID), 'Cursor must advance to leg A\'s id after pass 2');

    unlink($testRoot . '/Globals.php');
    rmdir($testRoot);

    echo "CDR realtime id-cursor correctly scans out-of-order (transferred call) legs OK\n";
}
