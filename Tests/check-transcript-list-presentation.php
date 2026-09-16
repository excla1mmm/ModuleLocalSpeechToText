<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    class CallDetailRecords
    {
        public string $recordingfile = '';
        public string $linkedid = '';
        public string $UNIQUEID = '';
        public string $src_num = '';
        public string $src_name = '';
        public string $dst_num = '';
        public string $dst_name = '';
        public string $did = '';
        public string $from_account = '';
        public string $to_account = '';
        public string $is_app = '';
        public string $duration = '';
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

    /**
     * A minimal stand-in for the real read connection: enough for
     * TranscriptListService's bounded candidate-batch and group-expansion
     * raw queries to return sensible data sourced from STTResult::$rows,
     * without needing a real (SQLite) database for this presentation-only
     * test. It ignores LIMIT/OFFSET/ordering since this test's row count
     * never approaches a batch, and this test never activates full-text
     * search, so FTS-shaped SQL is never sent here.
     */
    class TranscriptListReadConnection
    {
        public function fetchAll(string $sql, int $mode, array $bind = []): array
        {
            if (str_contains($sql, 'AS call_id')) {
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
    }

    class STTResult
    {
        /** @var array<int, self> */
        public static array $rows = [];
        public int $id = 0;
        public int $job_id = 0;
        public string $created_at = '0';
        public ?STTJob $STTJob = null;

        public static function find(array $params): array
        {
            return self::$rows;
        }

        public function getReadConnection(): TranscriptListReadConnection
        {
            return new TranscriptListReadConnection();
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class CallRecordingService
    {
        /** @var array<string, \MikoPBX\Common\Models\CallDetailRecords> */
        public array $cdrByCallId = [];
        /** @var array<string, \MikoPBX\Common\Models\CallDetailRecords> */
        public array $cdrByFilePath = [];
        /** @var array<string, string> */
        public array $employeeNamesByNumber = [];

        public function cdrRecordForJob(mixed $job): ?\MikoPBX\Common\Models\CallDetailRecords
        {
            return $this->cdrByFilePath[$job->file_path] ?? $this->cdrByCallId[$job->call_id] ?? null;
        }

        // Mirrors production precedence: a known extension outranks the CDR
        // display name, which only labels everyone the extension list misses.
        public function numberWithEmployeeName(string $number, string $cdrDisplayName = ''): string
        {
            $name = $this->employeeNamesByNumber[$number] ?? '';
            if ($name === '') {
                $name = trim($cdrDisplayName) === $number ? '' : trim($cdrDisplayName);
            }

            return $name === '' ? $number : sprintf('%s (%s)', $number, $name);
        }

        public function cdrRecordForResult(mixed $result): ?\MikoPBX\Common\Models\CallDetailRecords
        {
            return null;
        }

        public function callTimestamp(mixed $result, mixed $job, mixed $cdr): int
        {
            return 1_789_124_400;
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

    use MikoPBX\Common\Models\CallDetailRecords;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptListService;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    function expectTranscriptList(bool $condition, string $message): void
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
    $recordings->employeeNamesByNumber = [
        '208' => 'Портнов Алексей',
        '206' => 'Васильева Анна',
    ];

    $cases = [
        'outgoing' => ['208', '790099241808', '', '', 'SIP-TRUNK', '46'],
        'incoming' => ['790099241808', '208', '7495', 'SIP-TRUNK', '', '55'],
        'internal' => ['208', '206', '', '', '', '39'],
    ];
    foreach ($cases as $callId => [$source, $destination, $did, $fromAccount, $toAccount, $duration]) {
        $job = new STTJob();
        $job->call_id = $callId;
        $result = new STTResult();
        $result->id = count(STTResult::$rows) + 1;
        $result->job_id = $result->id;
        $result->created_at = '1789124460';
        $result->STTJob = $job;
        STTResult::$rows[] = $result;

        $cdr = new CallDetailRecords();
        $cdr->src_num = $source;
        $cdr->dst_num = $destination;
        $cdr->src_name = $source === '790099241808' ? 'ALL:SP INVEST' : '';
        $cdr->did = $did;
        $cdr->from_account = $fromAccount;
        $cdr->to_account = $toAccount;
        $cdr->duration = $duration;
        $recordings->cdrByCallId[$callId] = $cdr;
    }

    $mixedParts = [
        ['mixed-client', '79001234567', '208', '7495', 'SIP-TRUNK', '', '192', 1_789_312_185_705, 1_789_312_377_808],
        ['mixed-internal', '208', '206', '', '', '', '39', 1_789_312_288_311, 1_789_312_327_228],
        ['mixed-transferred', '79001234567', '206', '7495', 'SIP-TRUNK', '', '50', 1_789_312_327_229, 1_789_312_377_808],
    ];
    foreach ($mixedParts as [$filePath, $source, $destination, $did, $fromAccount, $toAccount, $duration, $startMs, $endMs]) {
        $job = new STTJob();
        $job->call_id = 'mixed';
        $job->file_path = $filePath;
        $job->cdr_start_ms = $startMs;
        $job->cdr_end_ms = $endMs;
        $result = new STTResult();
        $result->id = count(STTResult::$rows) + 1;
        $result->job_id = $result->id;
        $result->created_at = '1789312400';
        $result->STTJob = $job;
        STTResult::$rows[] = $result;

        $cdr = new CallDetailRecords();
        $cdr->src_num = $source;
        $cdr->dst_num = $destination;
        $cdr->did = $did;
        $cdr->from_account = $fromAccount;
        $cdr->to_account = $toAccount;
        $cdr->duration = $duration;
        $recordings->cdrByFilePath[$filePath] = $cdr;
    }

    $consultationOnlyParts = [
        ['consultation-client-before', '79007654321', '208', '7495', 'SIP-TRUNK', '', '60', 1_789_400_000_000, 1_789_400_060_000],
        ['consultation-internal', '208', '206', '', '', '', '20', 1_789_400_020_000, 1_789_400_040_000],
        ['consultation-client-after', '79007654321', '208', '7495', 'SIP-TRUNK', '', '30', 1_789_400_060_001, 1_789_400_090_000],
    ];
    foreach ($consultationOnlyParts as [$filePath, $source, $destination, $did, $fromAccount, $toAccount, $duration, $startMs, $endMs]) {
        $job = new STTJob();
        $job->call_id = 'consultation-only';
        $job->file_path = $filePath;
        $job->cdr_start_ms = $startMs;
        $job->cdr_end_ms = $endMs;
        $result = new STTResult();
        $result->id = count(STTResult::$rows) + 1;
        $result->job_id = $result->id;
        $result->created_at = '1789400090';
        $result->STTJob = $job;
        STTResult::$rows[] = $result;

        $cdr = new CallDetailRecords();
        $cdr->src_num = $source;
        $cdr->dst_num = $destination;
        $cdr->did = $did;
        $cdr->from_account = $fromAccount;
        $cdr->to_account = $toAccount;
        $cdr->duration = $duration;
        $recordings->cdrByFilePath[$filePath] = $cdr;
    }

    $rows = (new TranscriptListService(
        $translation,
        $request,
        $recordings,
        null,
        null,
        ['208' => true, '206' => true],
    ))->transcriptRows();
    $byDirection = [];
    foreach ($rows as $row) {
        $byDirection[$row['direction']] = $row;
    }

    expectTranscriptList($byDirection['outgoing']['directionIcon'] === '↗', 'outgoing direction icon mismatch');
    expectTranscriptList($byDirection['outgoing']['clientLabel'] === '790099241808', 'outgoing client mismatch');
    expectTranscriptList(
        $byDirection['outgoing']['employeesLabel'] === '208 (Портнов Алексей)',
        'outgoing employee must include the resolved employee name'
    );
    expectTranscriptList($byDirection['outgoing']['callDurationLabel'] === '00:46', 'duration must come from CDR');
    expectTranscriptList($byDirection['incoming']['directionIcon'] === '↙', 'incoming direction icon mismatch');
    expectTranscriptList(
        $byDirection['incoming']['clientLabel'] === '790099241808 (ALL:SP INVEST)',
        'incoming client must carry the company name the CDR row recorded'
    );
    expectTranscriptList(
        $byDirection['incoming']['employeesLabel'] === '208 (Портнов Алексей)',
        'incoming employee must include the resolved employee name'
    );
    expectTranscriptList($byDirection['internal']['directionIcon'] === '↔', 'internal direction icon mismatch');
    expectTranscriptList($byDirection['internal']['clientLabel'] === '—', 'internal client must be empty marker');
    expectTranscriptList(
        $byDirection['internal']['employeesLabel'] === '208 (Портнов Алексей) → 206 (Васильева Анна)',
        'internal route must resolve names per side'
    );

    $mixedRows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['callId'] === 'mixed'
    ));
    expectTranscriptList(count($mixedRows) === 1, 'one logical call must produce one transcript-list row');
    $mixed = $mixedRows[0];
    expectTranscriptList($mixed['direction'] === 'incoming', 'logical call must use its customer leg direction');
    expectTranscriptList($mixed['clientLabel'] === '79001234567', 'logical call must use its customer number');
    expectTranscriptList(
        $mixed['employeesLabel'] === '208 (Портнов Алексей) · 206 (Васильева Анна)',
        'logical call must list every participating employee once'
    );
    expectTranscriptList($mixed['partCount'] === 3, 'logical call must expose its part count');
    expectTranscriptList($mixed['hasInternalConsultation'] === true, 'logical call must identify internal consultation');
    expectTranscriptList($mixed['hasTransfer'] === true, 'employee change between customer legs must identify a transfer');
    expectTranscriptList(
        $mixed['transferEmployeeLabels'] === ['208 (Портнов Алексей)', '206 (Васильева Анна)'],
        'transfer route must follow customer-facing employees in call order'
    );
    expectTranscriptList($mixed['callDurationLabel'] === '03:12', 'logical duration must span all call parts');

    $consultationOnlyRows = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['callId'] === 'consultation-only'
    ));
    expectTranscriptList(count($consultationOnlyRows) === 1, 'consultation-only parts must stay in one logical row');
    $consultationOnly = $consultationOnlyRows[0];
    expectTranscriptList(
        $consultationOnly['hasTransfer'] === false,
        'an internal consultation followed by the same customer-facing employee is not a transfer'
    );
    expectTranscriptList(
        $consultationOnly['transferEmployeeLabels'] === [],
        'consultation-only calls must not expose a transfer route'
    );

    echo "Transcript list CDR presentation checks passed\n";
}
