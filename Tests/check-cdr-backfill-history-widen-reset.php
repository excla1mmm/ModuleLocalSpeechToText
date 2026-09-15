<?php

declare(strict_types=1);

/**
 * Regression test for item 4: saving settings must never touch the
 * (now id-based) realtime cursor, and must always reset the backfill cursor
 * together with its completion flag when the history window changes -
 * including widening it all the way to "unlimited" (historyStart === '').
 *
 * Covers:
 *  - WorkerLocalSpeechToTextJobs::backfillCompletedFor() treats both a
 *    narrower->wider bounded window AND a bounded->unlimited window as a
 *    widening that must reset CDR_BACKFILL_DONE / CDR_BACKFILL_DONE_HISTORY_AT.
 *  - App/Controllers/LocalSpeechToTextBaseController::saveSettingsFromPost()
 *    resets the backfill cursor + done flags (not the realtime cursor) when
 *    cdr_history_days changes.
 */

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

        /** @param array<string, mixed> $parameters */
        public static function maximum(array $parameters): int
        {
            return 0;
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
    final class STTJob
    {
        public function getWriteConnection(): mixed
        {
            return null;
        }
    }

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

        /** @param array<string, string> $values */
        public static function reset(array $values): void
        {
            self::$values = $values;
        }

        public static function getValue(string $key, string $default = ''): string
        {
            return self::$values[$key] ?? $default;
        }

        public static function setValue(string $key, string $value): void
        {
            self::$values[$key] = $value;
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
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;

    function expectBackfillWiden(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    $testRoot = sys_get_temp_dir() . '/module-local-stt-backfill-widen-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($testRoot, 0700, true);
    file_put_contents($testRoot . '/Globals.php', "<?php\n");
    $workingDirectory = getcwd();
    chdir($testRoot);
    require_once __DIR__ . '/../Lib/Services/CdrDiscoveryBackoffPolicy.php';
    require_once __DIR__ . '/../Lib/Services/RetryStateStore.php';
    require_once __DIR__ . '/../Lib/Services/CdrRecordEvaluator.php';
    require_once __DIR__ . '/../Lib/Services/RecordingRetryCache.php';
    require_once __DIR__ . '/../Lib/Services/CdrDiscoveryScanner.php';
    require_once __DIR__ . '/../Lib/Services/RecordingRetryProcessor.php';
    require_once __DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? __DIR__ : $workingDirectory);

    $worker = new WorkerLocalSpeechToTextJobs();
    $backfillCompletedFor = new ReflectionMethod($worker, 'backfillCompletedFor');

    // Not finished yet: always false, regardless of the snapshot.
    STTSetting::reset([
        STTSetting::CDR_BACKFILL_DONE => '0',
        STTSetting::CDR_BACKFILL_DONE_HISTORY_AT => '2026-08-01 00:00:00',
    ]);
    expectBackfillWiden(
        false,
        $backfillCompletedFor->invoke($worker, '2026-08-10 00:00:00'),
        'An unfinished backfill must report not-completed'
    );

    // Finished for a bounded window; history unchanged/narrower -> stays done.
    STTSetting::reset([
        STTSetting::CDR_BACKFILL_DONE => '1',
        STTSetting::CDR_BACKFILL_DONE_HISTORY_AT => '2026-08-01 00:00:00',
    ]);
    expectBackfillWiden(
        true,
        $backfillCompletedFor->invoke($worker, '2026-08-05 00:00:00'),
        'A bounded history window that did not widen must stay marked done'
    );
    expectBackfillWiden('1', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE), 'Done flag must be untouched when the window did not widen');

    // Finished for a bounded window; history moved earlier (bounded widening) -> resets.
    STTSetting::reset([
        STTSetting::CDR_BACKFILL_DONE => '1',
        STTSetting::CDR_BACKFILL_DONE_HISTORY_AT => '2026-08-01 00:00:00',
    ]);
    expectBackfillWiden(
        false,
        $backfillCompletedFor->invoke($worker, '2026-07-01 00:00:00'),
        'Moving history_start earlier (bounded widening) must reset the done flag'
    );
    expectBackfillWiden('0', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE), 'Bounded widening must clear the done flag');
    expectBackfillWiden('', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT), 'Bounded widening must clear the done-for snapshot');

    // Finished for a bounded window; history switched to unlimited ('') -> must also reset.
    // This is the case that used to stay stuck done forever before this fix.
    STTSetting::reset([
        STTSetting::CDR_BACKFILL_DONE => '1',
        STTSetting::CDR_BACKFILL_DONE_HISTORY_AT => '2026-08-01 00:00:00',
    ]);
    expectBackfillWiden(
        false,
        $backfillCompletedFor->invoke($worker, ''),
        'Switching cdr_history_days to unlimited must reset a previously-bounded-and-done backfill'
    );
    expectBackfillWiden('0', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE), 'Widening to unlimited must clear the done flag');
    expectBackfillWiden('', STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT), 'Widening to unlimited must clear the done-for snapshot');

    // Finished with an unlimited window already (doneFor === '') -> unlimited stays unlimited, no reset needed.
    STTSetting::reset([
        STTSetting::CDR_BACKFILL_DONE => '1',
        STTSetting::CDR_BACKFILL_DONE_HISTORY_AT => '',
    ]);
    expectBackfillWiden(
        true,
        $backfillCompletedFor->invoke($worker, ''),
        'An already-unlimited, already-done backfill must stay done when history is still unlimited'
    );

    unlink($testRoot . '/Globals.php');
    rmdir($testRoot);

    echo "CDR backfill history-window widening reset OK\n";
}
