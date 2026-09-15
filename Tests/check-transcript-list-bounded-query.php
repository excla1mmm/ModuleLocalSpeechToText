<?php

declare(strict_types=1);

/**
 * Regression test for item 3: TranscriptListService::transcriptRows() must
 * push a date window and a hard row bound into SQL instead of loading the
 * whole m_STTResult table and filtering in PHP, while still:
 *  - capping the final output at $limit rows;
 *  - loading ALL parts of a logical (multi-leg) call once any one of its
 *    parts is discovered as a bounded candidate, even when a sibling part
 *    would not itself have ranked into the first bounded page;
 *  - never touching rows that belong to calls entirely outside the bounded
 *    candidate page.
 *
 * The fake connection below implements real LIMIT/OFFSET pagination and
 * date-window filtering over an in-memory candidate set (unlike the other
 * TranscriptListService tests, which only need to return canned/unfiltered
 * data), so this test can assert on how many rows the query actually
 * touched, not just on the final presentation output.
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
    }

    class STTResult
    {
        /** @var array<int, self> */
        public static array $rows = [];
        public static int $findCalls = 0;
        /** @var array<int, array<string, mixed>> */
        public static array $findBinds = [];
        public static ?BoundedTestConnection $connection = null;

        public int $id = 0;
        public int $job_id = 0;
        public string $created_at = '0';
        public ?STTJob $STTJob = null;

        public static function find(array $params): array
        {
            self::$findCalls++;
            self::$findBinds[] = $params['bind'] ?? [];
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

        public function getReadConnection(): BoundedTestConnection
        {
            return self::$connection ?? throw new \RuntimeException('missing test connection');
        }
    }

    /**
     * A faithful-enough in-memory stand-in for the real SQLite connection:
     * it actually applies LIMIT/OFFSET and the date-bound WHERE clause
     * against a candidate set the test controls, instead of just returning
     * canned pages - so a test can prove the query is genuinely bounded.
     */
    final class BoundedTestConnection
    {
        /** @var array<int, array{id: int, call_id: string, effective_time: int}> */
        public array $candidates = [];
        public int $fetchAllCalls = 0;
        public int $candidateBatchRowsReturned = 0;

        /** @param array<string, mixed> $bind */
        public function fetchAll(string $sql, int $mode, array $bind = []): array
        {
            $this->fetchAllCalls++;

            if (str_contains($sql, 'AS call_id')) {
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

                [$limit, $offset] = $this->parseLimitOffset($sql);
                $page = array_slice($rows, $offset, $limit);
                $this->candidateBatchRowsReturned += count($page);

                return array_map(
                    static fn (array $row): array => ['id' => $row['id'], 'call_id' => $row['call_id']],
                    $page
                );
            }

            // Group-expansion query: `j.call_id IN (:call_id_0, ...)`.
            $wantedCallIds = array_values($bind);

            return array_values(array_map(
                static fn (array $row): array => ['id' => $row['id']],
                array_filter(
                    $this->candidates,
                    static fn (array $row): bool => in_array($row['call_id'], $wantedCallIds, true)
                )
            ));
        }

        /** @return array{0: int, 1: int} */
        private function parseLimitOffset(string $sql): array
        {
            preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $matches);

            return [isset($matches[1]) ? (int)$matches[1] : 0, isset($matches[2]) ? (int)$matches[2] : 0];
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    use MikoPBX\Common\Models\CallDetailRecords;

    class CallRecordingService
    {
        /** @var array<int, int> STTResult id => effective time, mirrors the test's per-leg candidate data */
        public array $effectiveTimeByResultId = [];

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
            return $this->effectiveTimeByResultId[$result->id ?? 0] ?? 0;
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
    use Modules\ModuleLocalSpeechToText\Models\BoundedTestConnection;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    function expectBoundedQuery(bool $condition, string $message): void
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
    $connection = new BoundedTestConnection();
    STTResult::$connection = $connection;

    $nextId = 1;
    $addCandidate = function (string $callId, int $effectiveTime) use (&$nextId, $recordings): void {
        $job = new STTJob();
        $job->call_id = $callId;

        $result = new STTResult();
        $result->id = $nextId;
        $result->job_id = $nextId;
        $result->created_at = (string)$effectiveTime;
        $result->STTJob = $job;

        STTResult::$rows[$result->id] = $result;
        STTResult::$connection->candidates[] = ['id' => $result->id, 'call_id' => $callId, 'effective_time' => $effectiveTime];
        $recordings->effectiveTimeByResultId[$result->id] = $effectiveTime;
        $nextId++;
    };

    // 99 single-part filler calls ranked just below the transferred call's
    // first leg, so together with it they exactly fill the first 100-row
    // candidate page.
    for ($i = 1; $i <= 99; $i++) {
        $addCandidate('filler-' . $i, 2_000_000 - $i);
    }

    // The transferred call: leg A ranks #1 (inside the first page), leg B is
    // far older and would rank #101 on its own - outside the first bounded
    // page - if it were only found via its own effective time. It must still
    // be pulled in once leg A discovers the 'transferred' call_id.
    $addCandidate('transferred', 2_000_000);
    $addCandidate('transferred', 500_000);

    // 50 more calls, ranked even further below leg B, that must never be
    // touched at all: proof the query is bounded rather than scanning
    // everything and filtering in PHP.
    for ($i = 1; $i <= 50; $i++) {
        $addCandidate('untouched-' . $i, 100_000 - $i);
    }

    $service = new TranscriptListService($translation, $request, $recordings);
    $rows = $service->transcriptRows(100);

    expectBoundedQuery(count($rows) === 100, 'transcriptRows() must cap its output at the requested limit');

    $transferredRows = array_values(array_filter($rows, static fn (array $row): bool => $row['callId'] === 'transferred'));
    expectBoundedQuery(count($transferredRows) === 1, 'the transferred call must be represented by exactly one grouped row');
    expectBoundedQuery(
        (int)($transferredRows[0]['partCount'] ?? 0) === 2,
        'both legs of the transferred call must be merged into the same group, even though leg B did not rank into the first bounded page on its own'
    );

    foreach ($rows as $row) {
        expectBoundedQuery(
            !str_starts_with((string)$row['callId'], 'untouched-'),
            'a call entirely outside the bounded candidate page must never appear in the output'
        );
    }

    expectBoundedQuery(
        $connection->fetchAllCalls <= 2,
        "the query must resolve in a small, bounded number of round trips, not one per candidate (got {$connection->fetchAllCalls})"
    );
    expectBoundedQuery(
        $connection->candidateBatchRowsReturned === 100,
        'the bounded candidate query must fetch exactly one 100-row page, never the whole table (' . count(STTResult::$rows) . ' rows exist)'
    );
    expectBoundedQuery(
        STTResult::$findCalls === 1,
        'hydrating the candidate groups must use a single batched ORM query, not one per row'
    );

    // Active date filter: the SQL pre-filter should exclude leg B (500_000)
    // and every 'untouched-*' call (~100_000) up front, while leg A
    // (2_000_000) and every filler (~1_999_9xx) stay in-window. Group
    // expansion still loads leg B alongside leg A (it is not date-bounded,
    // by design - see loadGroupResults()), but the exact per-row
    // transcriptMatchesDateFilter() check in buildRow() must still be the
    // final authority and drop it from the visible group.
    $dateFilter = ['isActive' => true, 'start' => 1_900_000, 'end' => 2_100_000];
    $filteredRows = $service->transcriptRows(100, $dateFilter);

    foreach ($filteredRows as $row) {
        expectBoundedQuery(
            !str_starts_with((string)$row['callId'], 'untouched-'),
            'an active date filter must exclude calls entirely outside the window'
        );
    }
    $filteredTransferredRows = array_values(array_filter(
        $filteredRows,
        static fn (array $row): bool => $row['callId'] === 'transferred'
    ));
    expectBoundedQuery(count($filteredTransferredRows) === 1, 'the in-window leg of the transferred call must still be shown');
    expectBoundedQuery(
        (int)($filteredTransferredRows[0]['partCount'] ?? 0) === 1,
        'the out-of-window leg B must still be dropped by the exact per-row date filter, even though it was loaded for grouping'
    );

    echo "Transcript list bounded-query and multi-leg group completeness checks passed\n";
}
