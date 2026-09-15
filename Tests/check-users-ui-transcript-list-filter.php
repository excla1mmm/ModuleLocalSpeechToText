<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    class CallDetailRecords
    {
        public string $recordingfile = '';
        public string $linkedid = '';
        public string $UNIQUEID = '';
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    class STTJob
    {
        public string $call_id = '';
        public string $file_path = '';
    }

    class STTResult
    {
        /** @var array<int, self> */
        public static array $rows = [];
        public int $id = 0;
        public int $job_id = 0;
        public string $created_at = '100';
        public ?string $duration = '1';
        public string $language = 'ru';
        public string $model_used = 'test';
        public ?STTJob $STTJob = null;
        public static mixed $connection = null;
        public static int $findCalls = 0;
        public static int $findFirstCalls = 0;

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

            return isset($params['limit']) ? array_slice($rows, 0, (int)$params['limit']) : $rows;
        }

        public static function findFirstById(int $id): ?self
        {
            self::$findFirstCalls++;
            foreach (self::$rows as $row) {
                if ($row->id === $id) {
                    return $row;
                }
            }

            return null;
        }

        public function getReadConnection(): mixed
        {
            return self::$connection;
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class CallRecordingService
    {
        public function cdrRecordForJob(mixed $job): \MikoPBX\Common\Models\CallDetailRecords
        {
            return new \MikoPBX\Common\Models\CallDetailRecords();
        }

        public function cdrRecordForResult(mixed $result): \MikoPBX\Common\Models\CallDetailRecords
        {
            return new \MikoPBX\Common\Models\CallDetailRecords();
        }

        public function callTimestamp(mixed $result, mixed $job, mixed $cdr): int
        {
            return 100;
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
    require_once dirname(__DIR__) . '/Lib/Services/TransactionRecoveryException.php';
    require_once dirname(__DIR__) . '/Lib/Services/ModuleTransaction.php';
    require_once dirname(__DIR__) . '/Lib/Services/TranscriptSearchText.php';
    require_once dirname(__DIR__) . '/Lib/Services/TranscriptSearchIndexService.php';
    require_once dirname(__DIR__) . '/Lib/Services/UsersUICallScope.php';
    require_once dirname(__DIR__) . '/Lib/Services/UsersUITranscriptAccessService.php';
    require_once dirname(__DIR__) . '/Lib/Services/CallDirectionResolver.php';
    require_once dirname(__DIR__) . '/Lib/Services/TranscriptListService.php';

    use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptListService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUICallScope;
    use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUITranscriptAccessService;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    $allowedJob = new STTJob();
    $allowedJob->call_id = 'allowed';
    $hiddenJob = new STTJob();
    $hiddenJob->call_id = 'hidden';

    $allowed = new STTResult();
    $allowed->id = 1;
    $allowed->job_id = 11;
    $allowed->STTJob = $allowedJob;
    $hidden = new STTResult();
    $hidden->id = 2;
    $hidden->job_id = 22;
    $hidden->STTJob = $hiddenJob;
    STTResult::$rows = [$hidden, $allowed];
    STTResult::$connection = new class {
        public string $searchSql = '';

        public function fetchAll(string $sql, int $mode, array $bind = []): array
        {
            if (str_contains($sql, 'm_STTTranscriptSearchState')) {
                return [['last_result_id' => 2, 'completed_at' => 100, 'available' => 1, 'error_code' => '']];
            }
            if (str_contains($sql, 'm_STTTranscriptSearch MATCH')) {
                if (($bind['search'] ?? '') !== '"hidden"*') {
                    return [];
                }
                $this->searchSql = $sql;

                return [['id' => 2, 'call_id' => 'hidden']];
            }
            if (str_contains($sql, 'AS call_id')) {
                // Bounded candidate-batch query for the plain (non-search) listing.
                $rows = [];
                foreach (STTResult::$rows as $result) {
                    $rows[] = [
                        'id' => $result->id,
                        'call_id' => $result->STTJob instanceof STTJob ? $result->STTJob->call_id : '',
                    ];
                }

                return $rows;
            }

            // Group-expansion query: `j.call_id IN (:call_id_0, ...)`.
            $wantedCallIds = array_values($bind);
            $rows = [];
            foreach (STTResult::$rows as $result) {
                $callId = $result->STTJob instanceof STTJob ? $result->STTJob->call_id : '';
                if (in_array($callId, $wantedCallIds, true)) {
                    $rows[] = ['id' => $result->id];
                }
            }

            return $rows;
        }
    };

    $translation = new class {
        public function _(string $key): string { return $key; }
    };
    $request = new class {
        public function getQuery(string $name, string $filter, string $default): string { return $default; }
    };
    $access = new UsersUITranscriptAccessService(
        static fn(string $callId): array => $callId === 'allowed'
            ? [['source' => '201', 'destination' => '74950000000']]
            : [['source' => '202', 'destination' => '74950000000']]
    );
    $service = new TranscriptListService(
        $translation,
        $request,
        new CallRecordingService(),
        UsersUICallScope::selected(['201']),
        $access,
    );
    $rows = $service->transcriptRows(1, ['isActive' => false]);

    if (count($rows) !== 1 || (int)$rows[0]['result']->id !== 1) {
        fwrite(STDERR, "Transcript list leaked a call outside the ModuleUsersUI CDR scope\n");
        exit(1);
    }

    $searchedRows = $service->transcriptRows(100, ['isActive' => false], 'hidden');
    if ($searchedRows !== []) {
        fwrite(STDERR, "Transcript full-text search did not preserve the ModuleUsersUI CDR scope\n");
        exit(1);
    }
    if (!str_contains(STTResult::$connection->searchSql, 'LIMIT 100')) {
        fwrite(STDERR, "Transcript full-text search did not read bounded batches\n");
        exit(1);
    }
    if (STTResult::$findFirstCalls !== 0 || STTResult::$findCalls !== 2) {
        fwrite(STDERR, "Transcript full-text search used per-result ORM queries\n");
        exit(1);
    }

    echo "ModuleUsersUI STT transcript list filtering checks passed\n";
}
