<?php

declare(strict_types=1);

/**
 * Regression test: the SQL date pre-filter in TranscriptListService must be
 * a superset of the exact per-row callTimestamp() check, not a stricter
 * approximation of it.
 *
 * callTimestamp() prefers the call's *start* (a live CDR lookup, or
 * cdr_start_ms). EFFECTIVE_TIME_SQL, used only to keep the candidate query
 * cheap, cannot do that live CDR lookup and falls back to cdr_endtime (call
 * *end*) for a legacy job with no cdr_start_ms. For a call that starts
 * inside the requested date window but ends after it (recordings run up to
 * 180 minutes), a pre-filter bound only at the window's exact end would
 * wrongly exclude that row before it ever reaches the exact per-row check.
 *
 * This reproduces exactly that: a legacy job (cdr_start_ms = 0) whose
 * cdr_endtime lands an hour past the window end, but whose true call start
 * (returned by the CallRecordingService stub, standing in for the live CDR
 * lookup callTimestamp() would perform) is inside the window - it must
 * still be listed. A second job further out than the safety margin proves
 * the widened bound is not simply disabled.
 */

namespace MikoPBX\Common\Models {
    class CallDetailRecords
    {
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    class STTJob
    {
        public string $call_id = '';
        public string $file_path = '';
        public int $cdr_start_ms = 0;
        public int $cdr_end_ms = 0;
        public int $cdr_endtime = 0;
    }

    class STTResult
    {
        /** @var array<int, self> */
        public static array $rows = [];
        public static ?DateBoundMarginTestConnection $connection = null;

        public int $id = 0;
        public int $job_id = 0;
        public string $created_at = '0';
        public ?STTJob $STTJob = null;

        public static function find(array $params): array
        {
            $rows = self::$rows;
            if (isset($params['bind']['ids']) && is_array($params['bind']['ids'])) {
                $ids = array_map('intval', $params['bind']['ids']);
                $rows = array_values(array_filter(
                    $rows,
                    static fn (self $row): bool => in_array($row->id, $ids, true)
                ));
            }

            return $rows;
        }

        public function getReadConnection(): DateBoundMarginTestConnection
        {
            return self::$connection ?? throw new \RuntimeException('missing test connection');
        }
    }

    /**
     * Applies the real date_bound_start/date_bound_end BETWEEN filter (as
     * production SQL would) against cdr_endtime-derived effective times, so
     * this test proves the margin is actually applied in the bind values
     * TranscriptListService sends, not just asserted in isolation.
     */
    final class DateBoundMarginTestConnection
    {
        /** @var array<int, array{id: int, call_id: string, effective_time: int}> */
        public array $candidates = [];
        /** @var array<int, array<string, mixed>> */
        public array $capturedBinds = [];

        /** @param array<string, mixed> $bind */
        public function fetchAll(string $sql, int $mode, array $bind = []): array
        {
            if (str_contains($sql, 'AS call_id')) {
                $this->capturedBinds[] = $bind;

                $rows = $this->candidates;
                if (isset($bind['date_bound_start'], $bind['date_bound_end'])) {
                    $start = (int)$bind['date_bound_start'];
                    $end = (int)$bind['date_bound_end'];
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => $row['effective_time'] >= $start && $row['effective_time'] <= $end
                    ));
                }
                usort(
                    $rows,
                    static fn (array $left, array $right): int => [$right['effective_time'], $right['id']] <=> [$left['effective_time'], $left['id']]
                );

                return array_map(
                    static fn (array $row): array => ['id' => $row['id'], 'call_id' => $row['call_id']],
                    $rows
                );
            }

            // Group-expansion query: `j.call_id IN (:call_id_0, ...)` - not
            // date-bounded, by design, so every part of a candidate call is
            // loaded regardless of where its own effective_time falls.
            $wantedCallIds = array_values($bind);

            return array_values(array_map(
                static fn (array $row): array => ['id' => $row['id']],
                array_filter(
                    $this->candidates,
                    static fn (array $row): bool => in_array($row['call_id'], $wantedCallIds, true)
                )
            ));
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    use MikoPBX\Common\Models\CallDetailRecords;

    class CallRecordingService
    {
        /**
         * Stands in for callTimestamp()'s exact priority: keyed by result
         * id, this is the *true* call start the production live-CDR lookup
         * would have found - independent of, and earlier than, the
         * cdr_endtime the SQL pre-filter had to approximate with.
         *
         * @var array<int, int>
         */
        public array $trueCallStartByResultId = [];

        public function cdrRecordForJob(mixed $job): ?CallDetailRecords
        {
            return null;
        }

        public function cdrRecordForResult(mixed $result): ?CallDetailRecords
        {
            return null;
        }

        public function callTimestamp(mixed $result, mixed $job, mixed $cdr): int
        {
            return $this->trueCallStartByResultId[$result->id ?? 0] ?? 0;
        }

        public function recordingPathForPlayback(mixed $job, mixed $cdr): string
        {
            return '';
        }
    }
}

namespace {
    if (!class_exists('Phalcon\\Db\\Enum')) {
        eval('namespace Phalcon\\Db; class Enum { public const int FETCH_ASSOC = 2; }');
    }
    require_once dirname(__DIR__) . '/Lib/Services/UsersUICallScope.php';
    require_once dirname(__DIR__) . '/Lib/Services/UsersUITranscriptAccessService.php';
    require_once dirname(__DIR__) . '/Lib/Services/CallDirectionResolver.php';
    require_once dirname(__DIR__) . '/Lib/Services/TranscriptListService.php';

    use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptListService;
    use Modules\ModuleLocalSpeechToText\Models\DateBoundMarginTestConnection;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    function expectDateBoundMargin(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    $translation = new class {
        public function _(string $key): string
        {
            return $key;
        }
    };
    $request = new class {
        public function getQuery(string $name, string $filter, string $default): string
        {
            return $default;
        }
    };

    $recordings = new CallRecordingService();
    $connection = new DateBoundMarginTestConnection();
    STTResult::$connection = $connection;

    $windowStart = 2_000_000;
    $windowEnd = 2_003_000;

    $addLegacyJob = function (int $id, string $callId, int $cdrEndtime, int $trueCallStart) use ($recordings, $connection): void {
        $job = new STTJob();
        $job->call_id = $callId;
        $job->cdr_start_ms = 0; // legacy row: no cdr_start_ms captured.
        $job->cdr_endtime = $cdrEndtime;

        $result = new STTResult();
        $result->id = $id;
        $result->job_id = $id;
        $result->created_at = (string)$cdrEndtime;
        $result->STTJob = $job;

        STTResult::$rows[$id] = $result;
        // Mirrors EFFECTIVE_TIME_SQL: cdr_start_ms unset -> falls back to cdr_endtime.
        $connection->candidates[] = ['id' => $id, 'call_id' => $callId, 'effective_time' => $cdrEndtime];
        $recordings->trueCallStartByResultId[$id] = $trueCallStart;
    };

    // Call A: true start is inside the window, but the recording ran long
    // enough that cdr_endtime lands one hour past the window's exact end -
    // well inside the one-day safety margin.
    $addLegacyJob(1, 'legacy-in-window', $windowEnd + 3600, $windowStart + 500);

    // Call B: a control - its true start is also inside the window, but its
    // cdr_endtime lands *beyond* the margin entirely. It must NOT be
    // returned: the margin widens the candidate pre-filter, it does not
    // disable it, and the SQL stage never sees the (mocked) true start.
    $addLegacyJob(2, 'legacy-beyond-margin', $windowEnd + 86400 + 3600, $windowStart + 700);

    $service = new TranscriptListService($translation, $request, $recordings);
    $rows = $service->transcriptRows(100, ['isActive' => true, 'start' => $windowStart, 'end' => $windowEnd]);

    expectDateBoundMargin(count($connection->capturedBinds) >= 1, 'the bounded candidate query must have run');
    $sentBind = $connection->capturedBinds[0];
    expectDateBoundMargin(
        (int)($sentBind['date_bound_end'] ?? 0) === $windowEnd + 86400,
        'the SQL date_bound_end must be widened by exactly the one-day safety margin, got ' . var_export($sentBind['date_bound_end'] ?? null, true)
    );

    $callIds = array_map(static fn (array $row): string => (string)$row['callId'], $rows);
    expectDateBoundMargin(
        in_array('legacy-in-window', $callIds, true),
        'a legacy job whose true call start is inside the window must be listed even though cdr_endtime (used by the SQL pre-filter) is past the exact window end'
    );
    expectDateBoundMargin(
        !in_array('legacy-beyond-margin', $callIds, true),
        'a call whose cdr_endtime is beyond the safety margin must still be excluded by the pre-filter'
    );

    echo "Transcript list date-bound safety-margin checks passed\n";
}
