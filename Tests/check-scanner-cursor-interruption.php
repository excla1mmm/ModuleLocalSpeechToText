<?php

declare(strict_types=1);

namespace MikoPBX\Core\Workers {
    abstract class WorkerBase
    {
        protected bool $needRestart = false;

        public static function startWorker(array $arguments): void
        {
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
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
        /** @var array<int, array{key: string, value: string}> */
        public static array $writes = [];
        public static ?int $failOnWrite = null;

        public static function getValue(string $key, string $default = ''): string
        {
            return self::$values[$key] ?? $default;
        }

        public static function setValue(string $key, string $value): void
        {
            self::$writes[] = ['key' => $key, 'value' => $value];
            if (self::$failOnWrite === count(self::$writes)) {
                throw new \RuntimeException('injected interrupted settings write');
            }
            self::$values[$key] = $value;
        }

        /** @param array<string, string> $values */
        public static function reset(array $values, ?int $failOnWrite): void
        {
            self::$values = $values;
            self::$writes = [];
            self::$failOnWrite = $failOnWrite;
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    function expectScannerCursor(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function expectInterruptedCursorWrite(ReflectionMethod $method, object $worker, mixed ...$arguments): void
    {
        try {
            $method->invoke($worker, ...$arguments);
        } catch (RuntimeException $exception) {
            expectScannerCursor(
                $exception->getMessage() === 'injected interrupted settings write',
                'cursor interruption exposed an unexpected exception'
            );
            return;
        }

        throw new RuntimeException('expected interrupted settings write was not thrown');
    }

    /** @param array{endtime: string, id: int} $row */
    function backfillCursorIncludes(string $endtime, int $id, array $row): bool
    {
        return $row['endtime'] < $endtime
            || ($row['endtime'] === $endtime && $row['id'] < $id);
    }

    $temporaryRoot = sys_get_temp_dir() . '/local-stt-scanner-cursor-' . bin2hex(random_bytes(8));
    register_shutdown_function(
        static function () use ($temporaryRoot): void {
            $globals = $temporaryRoot . '/Globals.php';
            if (is_file($globals)) {
                unlink($globals);
            }
            if (is_dir($temporaryRoot)) {
                rmdir($temporaryRoot);
            }
        }
    );
    mkdir($temporaryRoot, 0700, true);
    file_put_contents($temporaryRoot . '/Globals.php', "<?php\n");

    $root = dirname(__DIR__);
    $workingDirectory = getcwd();
    chdir($temporaryRoot);
    require_once $root . '/Lib/Services/CdrDiscoveryBackoffPolicy.php';
    require_once $root . '/bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? $root : $workingDirectory);

    $worker = new WorkerLocalSpeechToTextJobs();
    $saveRealtime = new ReflectionMethod($worker, 'saveScanCursor');
    $saveBackfill = new ReflectionMethod($worker, 'saveBackfillCursor');
    $markBackfillCompleted = new ReflectionMethod($worker, 'markBackfillCompleted');

    // The realtime cursor is now a single `id` watermark (see item 2's fix in
    // bin/WorkerLocalSpeechToTextJobs.php): saveScanCursor() makes exactly one
    // STTSetting write, so it is atomic from the caller's point of view - an
    // interrupted write either fully applies the new id, or leaves the
    // previous id untouched. Either way no row can be skipped: with the old
    // id left in place, the next scan simply reprocesses everything with
    // id > old_id again, which is idempotent (STTJob::existsForFilePath /
    // the UNIQUE file_path constraint absorb any repeats).
    STTSetting::reset([
        STTSetting::CDR_SCAN_CURSOR_ID => '9000',
    ], 1);
    expectInterruptedCursorWrite($saveRealtime, $worker, 9500);
    expectScannerCursor(
        (int)STTSetting::$values[STTSetting::CDR_SCAN_CURSOR_ID] === 9000,
        'an interrupted realtime cursor write must leave the previous id cursor untouched, never a partial value'
    );

    STTSetting::reset([
        STTSetting::CDR_SCAN_CURSOR_ID => '3',
    ], null);
    $saveRealtime->invoke($worker, 4);
    expectScannerCursor(
        (int)STTSetting::$values[STTSetting::CDR_SCAN_CURSOR_ID] === 4,
        'a successful realtime cursor write must persist the new id cursor'
    );

    STTSetting::reset([
        STTSetting::CDR_BACKFILL_CURSOR_ENDTIME => '2026-08-10 11:00:00',
        STTSetting::CDR_BACKFILL_CURSOR_ID => '2',
    ], 2);
    expectInterruptedCursorWrite($saveBackfill, $worker, '2026-08-10 10:00:00', 9000);
    $backfillEndtime = STTSetting::$values[STTSetting::CDR_BACKFILL_CURSOR_ENDTIME];
    $backfillId = (int)STTSetting::$values[STTSetting::CDR_BACKFILL_CURSOR_ID];
    expectScannerCursor(
        backfillCursorIncludes($backfillEndtime, $backfillId, ['endtime' => '2026-08-10 10:00:00', 'id' => 8999]),
        'interrupted descending cursor write skipped a later row after an adversarial prior ID'
    );

    STTSetting::reset([
        STTSetting::CDR_BACKFILL_CURSOR_ENDTIME => '2026-08-10 10:00:00',
        STTSetting::CDR_BACKFILL_CURSOR_ID => '9000',
    ], 2);
    expectInterruptedCursorWrite($saveBackfill, $worker, '2026-08-10 10:00:00', 8000);
    expectScannerCursor(
        backfillCursorIncludes(
            STTSetting::$values[STTSetting::CDR_BACKFILL_CURSOR_ENDTIME],
            (int)STTSetting::$values[STTSetting::CDR_BACKFILL_CURSOR_ID],
            ['endtime' => '2026-08-10 10:00:00', 'id' => 7999]
        ),
        'interrupted descending cursor write skipped an unprocessed row sharing the cursor end time'
    );

    STTSetting::reset([
        STTSetting::CDR_BACKFILL_DONE => '0',
        STTSetting::CDR_BACKFILL_DONE_HISTORY_AT => '2026-08-01 00:00:00',
    ], 2);
    expectInterruptedCursorWrite(
        $markBackfillCompleted,
        $worker,
        '2026-08-05 00:00:00'
    );
    expectScannerCursor(
        STTSetting::$values[STTSetting::CDR_BACKFILL_DONE] === '0',
        'interrupted backfill completion published a false completed state'
    );
    expectScannerCursor(
        STTSetting::$values[STTSetting::CDR_BACKFILL_DONE_HISTORY_AT] === '2026-08-05 00:00:00',
        'backfill completion did not persist its history snapshot before the done flag'
    );

    echo "Scanner cursor interruption behavior OK\n";
}
