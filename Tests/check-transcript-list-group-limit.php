<?php

declare(strict_types=1);

/**
 * Regression test: TranscriptListService::transcriptRows()'s candidate
 * batching loop must stop once $limit distinct *logical calls* have been
 * accepted, not once $limit raw STTResult *parts* have been accepted. The
 * final page-size limit (array_slice(..., $limit)) is applied after
 * groupLogicalCalls() collapses same-call parts into one row, so counting
 * raw rows during batching can stop the loop far short of a full page
 * whenever multi-leg calls are involved: with two-leg calls, the very first
 * 100-row candidate batch already contains 100 raw rows but only ~50
 * distinct calls, and the old row-counting loop condition would have
 * stopped right there.
 *
 * This mirrors check-transcript-list-bounded-query.php's connection stub
 * (real LIMIT/OFFSET pagination over an in-memory candidate set) so the
 * assertions can be about how many candidate batches were actually needed,
 * not just about the final output shape.
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
        public static ?GroupLimitTestConnection $connection = null;

        public int $id = 0;
        public int $job_id = 0;
        public string $created_at = '0';
        public ?STTJob $STTJob = null;

        public static function find(array $params): array
        {
            self::$findCalls++;
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

        public function getReadConnection(): GroupLimitTestConnection
        {
            return self::$connection ?? throw new \RuntimeException('missing test connection');
        }
    }

    final class GroupLimitTestConnection
    {
        /** @var array<int, array{id: int, call_id: string, effective_time: int}> */
        public array $candidates = [];
        public int $candidateBatchCalls = 0;

        /** @param array<string, mixed> $bind */
        public function fetchAll(string $sql, int $mode, array $bind = []): array
        {
            if (str_contains($sql, 'AS call_id')) {
                $this->candidateBatchCalls++;
                $rows = $this->candidates;
                usort(
                    $rows,
                    static fn (array $left, array $right): int => [$right['effective_time'], $right['id']] <=> [$left['effective_time'], $left['id']]
                );
                [$limit, $offset] = $this->parseLimitOffset($sql);

                return array_map(
                    static fn (array $row): array => ['id' => $row['id'], 'call_id' => $row['call_id']],
                    array_slice($rows, $offset, $limit)
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
        /** @var array<int, int> STTResult id => effective time */
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
    use Modules\ModuleLocalSpeechToText\Models\GroupLimitTestConnection;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    function expectGroupLimit(bool $condition, string $message): void
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
    $connection = new GroupLimitTestConnection();
    STTResult::$connection = $connection;

    $nextId = 1;
    // 150 two-leg calls, ranked by call number (call 1 highest / newest).
    // Both legs of the same call share a rank (contiguous effective_time),
    // so the first 100-row candidate page contains exactly the two legs of
    // calls 1..50 - 50 distinct logical calls, not 100.
    for ($callNumber = 1; $callNumber <= 150; $callNumber++) {
        $effectiveTime = 10_000_000 - $callNumber;
        foreach (['a', 'b'] as $leg) {
            $job = new STTJob();
            $job->call_id = 'multi-' . $callNumber;

            $result = new STTResult();
            $result->id = $nextId;
            $result->job_id = $nextId;
            $result->created_at = (string)$effectiveTime;
            $result->STTJob = $job;

            STTResult::$rows[$result->id] = $result;
            $connection->candidates[] = ['id' => $result->id, 'call_id' => $job->call_id, 'effective_time' => $effectiveTime];
            $recordings->effectiveTimeByResultId[$result->id] = $effectiveTime;
            $nextId++;
        }
    }

    $service = new TranscriptListService($translation, $request, $recordings);
    $rows = $service->transcriptRows(100);

    expectGroupLimit(count($rows) === 100, 'a full page of 100 distinct logical calls must be returned, not fewer');
    foreach ($rows as $row) {
        expectGroupLimit((int)($row['partCount'] ?? 0) === 2, 'every returned call must show both of its legs merged');
        expectGroupLimit(str_starts_with((string)$row['callId'], 'multi-'), 'unexpected call in the bounded page');
    }
    expectGroupLimit(
        $connection->candidateBatchCalls >= 2,
        'the candidate loop must keep pulling batches (row-counting would have stopped after the first, 50-call batch); got '
            . $connection->candidateBatchCalls . ' batch(es)'
    );

    echo "Transcript list group-count (not row-count) batching limit checks passed\n";
}
