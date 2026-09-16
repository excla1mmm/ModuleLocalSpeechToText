<?php

declare(strict_types=1);

/**
 * Regression test: the transcripts list must survive its own date pre-filter
 * when the bound values are bound the way PDO actually binds them.
 *
 * PDO - and therefore Phalcon's SQLite adapter, which does not pass a type
 * map - sends integer parameters as TEXT. SQLite only coerces such a text
 * operand to a number when the other side of the comparison carries numeric
 * affinity. A bare `CASE ... END` expression carries none, so comparing it
 * against :date_bound_start/:date_bound_end compared storage classes instead
 * of numbers (every integer sorts before every string) and the candidate
 * query returned zero rows for every call - the tab rendered "no transcripts"
 * while the database was full of them, and boundedCandidateBatch()'s silent
 * catch meant nothing was logged.
 *
 * check-transcript-list-date-bound-margin.php covers the *width* of the same
 * bound, but it filters candidates in PHP, so it cannot see an affinity bug.
 * This test runs the service's real SQL against a real in-memory SQLite
 * through a PDO connection that binds exactly like production does, so the
 * affinity of EFFECTIVE_TIME_SQL is what decides whether it passes.
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
        public static ?DateBoundSqliteConnection $connection = null;

        public int $id = 0;
        public int $job_id = 0;
        public string $created_at = '0';
        public ?STTJob $STTJob = null;

        /** @return array<int, self> */
        public static function find(array $params): array
        {
            $ids = array_map('intval', (array)($params['bind']['ids'] ?? []));

            return array_values(array_filter(
                self::$rows,
                static fn (self $row): bool => in_array($row->id, $ids, true)
            ));
        }

        public function getReadConnection(): DateBoundSqliteConnection
        {
            return self::$connection ?? throw new \RuntimeException('missing test connection');
        }
    }

    /**
     * Executes the service's SQL against a real SQLite database, binding
     * parameters the way PDO::execute(array) does in production: everything
     * as a string, with no type map.
     */
    final class DateBoundSqliteConnection
    {
        /** @var array<int, string> */
        public array $executedSql = [];

        public function __construct(private readonly \PDO $pdo)
        {
        }

        /** @param array<string, mixed> $bind @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
        {
            $this->executedSql[] = $sql;
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bind);

            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    use MikoPBX\Common\Models\CallDetailRecords;

    class CallRecordingService
    {
        /** @var array<int, int> true call start per result id */
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
    use Modules\ModuleLocalSpeechToText\Models\DateBoundSqliteConnection;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    function expectDateBoundSqlite(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite extension is required for this test\n");
        exit(1);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE m_STTJob (
            id INTEGER PRIMARY KEY,
            call_id VARCHAR(0) NOT NULL,
            file_path VARCHAR(0) NOT NULL DEFAULT \'\',
            cdr_start_ms INTEGER NULL,
            cdr_end_ms INTEGER NULL,
            cdr_endtime INTEGER NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE m_STTResult (
            id INTEGER PRIMARY KEY,
            job_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );

    $connection = new DateBoundSqliteConnection($pdo);
    STTResult::$connection = $connection;
    $recordings = new CallRecordingService();

    $windowStart = 1_789_000_000;
    $windowEnd = $windowStart + 86_399;

    $addCall = function (int $id, string $callId, int $callStart) use ($pdo, $recordings): void {
        $startMs = $callStart * 1000;
        $insertJob = $pdo->prepare(
            'INSERT INTO m_STTJob (id, call_id, cdr_start_ms, cdr_end_ms, cdr_endtime)
             VALUES (:id, :call_id, :start_ms, :end_ms, :endtime)'
        );
        $insertJob->execute([
            'id' => $id,
            'call_id' => $callId,
            'start_ms' => $startMs,
            'end_ms' => $startMs + 60_000,
            'endtime' => $callStart + 60,
        ]);
        $insertResult = $pdo->prepare(
            'INSERT INTO m_STTResult (id, job_id, created_at) VALUES (:id, :job_id, :created_at)'
        );
        $insertResult->execute(['id' => $id, 'job_id' => $id, 'created_at' => $callStart + 120]);

        $job = new STTJob();
        $job->call_id = $callId;
        $job->cdr_start_ms = $startMs;
        $job->cdr_end_ms = $startMs + 60_000;
        $job->cdr_endtime = $callStart + 60;

        $result = new STTResult();
        $result->id = $id;
        $result->job_id = $id;
        $result->created_at = (string)($callStart + 120);
        $result->STTJob = $job;

        STTResult::$rows[$id] = $result;
        $recordings->trueCallStartByResultId[$id] = $callStart;
    };

    // Inside the requested window - must be listed.
    $addCall(1, 'call-inside-window', $windowStart + 3_600);
    // A week before the window - must stay filtered out, so a passing test
    // cannot be explained by the date bound silently doing nothing.
    $addCall(2, 'call-before-window', $windowStart - 7 * 86_400);

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

    $service = new TranscriptListService($translation, $request, $recordings);
    $rows = $service->transcriptRows(100, [
        'dateFrom' => date('Y-m-d', $windowStart),
        'dateTo' => date('Y-m-d', $windowEnd),
        'start' => $windowStart,
        'end' => $windowEnd,
        'isActive' => true,
    ]);

    $callIds = array_map(static fn (array $row): string => (string)$row['callId'], $rows);

    expectDateBoundSqlite(
        in_array('call-inside-window', $callIds, true),
        'a call inside the requested window must be listed - if this fails, the date bound is comparing an '
        . 'affinity-less expression against PDO\'s text-bound parameters again and the whole tab renders empty'
    );
    expectDateBoundSqlite(
        !in_array('call-before-window', $callIds, true),
        'a call a week before the window must still be excluded by the date bound'
    );
    expectDateBoundSqlite(
        count($callIds) === 1,
        'exactly one call was expected, got: ' . implode(', ', $callIds)
    );

    // Guard the mechanism itself: the same query must keep working when the
    // bound values arrive as strings, which is what PDO sends for an int.
    $boundedSql = '';
    foreach ($connection->executedSql as $sql) {
        if (str_contains($sql, 'AS call_id') && str_contains($sql, ':date_bound_start')) {
            $boundedSql = $sql;
            break;
        }
    }
    expectDateBoundSqlite($boundedSql !== '', 'the date-bounded candidate query must have run');

    $statement = $pdo->prepare($boundedSql);
    $statement->execute([
        'date_bound_start' => (string)$windowStart,
        'date_bound_end' => (string)($windowEnd + 86_400),
    ]);
    $textBoundRows = $statement->fetchAll(PDO::FETCH_ASSOC);
    expectDateBoundSqlite(
        count($textBoundRows) === 1 && (int)$textBoundRows[0]['id'] === 1,
        'the candidate query must match the in-window row even when both bounds are bound as text, got '
        . json_encode($textBoundRows)
    );

    echo "Transcript list date-bound SQLite binding checks passed\n";
}
