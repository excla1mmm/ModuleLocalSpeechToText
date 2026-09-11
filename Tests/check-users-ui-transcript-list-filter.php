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

        public static function find(array $params): array
        {
            return isset($params['limit']) ? array_slice(self::$rows, 0, (int)$params['limit']) : self::$rows;
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

    echo "ModuleUsersUI STT transcript list filtering checks passed\n";
}
