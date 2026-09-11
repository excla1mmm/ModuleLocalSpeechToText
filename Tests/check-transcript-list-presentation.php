<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    class CallDetailRecords
    {
        public string $recordingfile = '';
        public string $linkedid = '';
        public string $UNIQUEID = '';
        public string $src_num = '';
        public string $dst_num = '';
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
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class CallRecordingService
    {
        /** @var array<string, \MikoPBX\Common\Models\CallDetailRecords> */
        public array $cdrByCallId = [];
        /** @var array<string, string> */
        public array $employeeNamesByNumber = [];

        public function cdrRecordForJob(mixed $job): ?\MikoPBX\Common\Models\CallDetailRecords
        {
            return $this->cdrByCallId[$job->call_id] ?? null;
        }

        public function numberWithEmployeeName(string $number): string
        {
            $name = $this->employeeNamesByNumber[$number] ?? '';

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
    $recordings->employeeNamesByNumber = ['208' => 'Портнов Алексей'];

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
        $cdr->did = $did;
        $cdr->from_account = $fromAccount;
        $cdr->to_account = $toAccount;
        $cdr->duration = $duration;
        $recordings->cdrByCallId[$callId] = $cdr;
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
    expectTranscriptList($byDirection['incoming']['clientLabel'] === '790099241808', 'incoming client mismatch');
    expectTranscriptList(
        $byDirection['incoming']['employeesLabel'] === '208 (Портнов Алексей)',
        'incoming employee must include the resolved employee name'
    );
    expectTranscriptList($byDirection['internal']['directionIcon'] === '↔', 'internal direction icon mismatch');
    expectTranscriptList($byDirection['internal']['clientLabel'] === '—', 'internal client must be empty marker');
    expectTranscriptList(
        $byDirection['internal']['employeesLabel'] === '208 (Портнов Алексей) → 206',
        'internal route must resolve names per side and leave unmatched extensions as plain numbers'
    );

    echo "Transcript list CDR presentation checks passed\n";
}
