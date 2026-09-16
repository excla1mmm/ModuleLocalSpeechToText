<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    class CallDetailRecords
    {
        public int $id = 0;
        public string $src_num = '';
        public string $dst_num = '';
        public string $did = '';
        public string $from_account = '';
        public string $to_account = '';
        public string $rec_src_channel = '0';
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    class STTResult
    {
        public int $id = 0;
        public int $job_id = 0;
        public string $transcript = '';
        public ?STTJob $STTJob = null;
    }

    class STTJob
    {
        public const string STATUS_DONE = 'done';

        /** @var array<int, self> */
        public static array $rows = [];
        public int $id = 0;
        public string $call_id = '';
        public string $status = self::STATUS_DONE;
        public int $cdr_id = 0;
        public string $cdr_start_ms = '0';
        public ?STTResult $STTResult = null;

        public static function find(array $params): array
        {
            $callId = (string)($params['bind']['call_id'] ?? '');

            return array_values(array_filter(
                self::$rows,
                static fn (self $job): bool => $job->call_id === $callId && $job->status === self::STATUS_DONE
            ));
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class CallRecordingService
    {
        /** @var array<int, \MikoPBX\Common\Models\CallDetailRecords> */
        public array $cdrById = [];

        public function cdrRecordById(int $cdrId): ?\MikoPBX\Common\Models\CallDetailRecords
        {
            return $this->cdrById[$cdrId] ?? null;
        }

        public function cdrRecordForJob(mixed $job): ?\MikoPBX\Common\Models\CallDetailRecords
        {
            return $this->cdrRecordById((int)$job->cdr_id);
        }

        public function cdrRecordForResult(mixed $result): ?\MikoPBX\Common\Models\CallDetailRecords
        {
            return null;
        }

        public function recordingCard(mixed $result, mixed $job, mixed $cdr): array
        {
            return ['hasPlayback' => true, 'playbackUrl' => '/recording?id=' . $result->id, 'rows' => []];
        }

        // Mirrors production precedence: a known extension outranks the CDR
        // display name, which only labels everyone the extension list misses.
        public function numberWithEmployeeName(string $number, string $cdrDisplayName = ''): string
        {
            return match ($number) {
                '208' => '208 (Портнов Алексей)',
                '206' => '206 (Васильева Анна)',
                default => trim($cdrDisplayName) === '' || trim($cdrDisplayName) === $number
                    ? $number
                    : sprintf('%s (%s)', $number, trim($cdrDisplayName)),
            };
        }
    }

    class ConversationSpeakerLabelResolver
    {
        public function resolve(mixed $cdr): array
        {
            return ['left' => 'left', 'right' => 'right'];
        }
    }
}

namespace {
    require_once __DIR__ . '/../Lib/TranscriptTimeNormalizer.php';
    require_once __DIR__ . '/../Lib/Services/TranscriptConversationService.php';
    require_once __DIR__ . '/../Lib/Services/CallDirectionResolver.php';
    require_once __DIR__ . '/../Lib/Services/LogicalCallReviewService.php';

    use MikoPBX\Common\Models\CallDetailRecords;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallRecordingService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ConversationSpeakerLabelResolver;
    use Modules\ModuleLocalSpeechToText\Lib\Services\LogicalCallReviewService;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    function expectLogicalCallReview(bool $condition, string $message): void
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
    $recordings = new CallRecordingService();
    $speakerLabels = new ConversationSpeakerLabelResolver();

    $makePart = static function (
        int $id,
        string $callId,
        int $cdrId,
        int $startMs,
        string $source,
        string $destination,
        bool $external
    ) use ($recordings): STTResult {
        $cdr = new CallDetailRecords();
        $cdr->id = $cdrId;
        $cdr->src_num = $source;
        $cdr->dst_num = $destination;
        $cdr->from_account = $external ? 'SIP-TRUNK' : '';
        $recordings->cdrById[$cdrId] = $cdr;

        $result = new STTResult();
        $result->id = $id;
        $result->job_id = $id;
        $result->transcript = json_encode([[
                'start' => 0.0,
                'end' => 1.0,
                'text' => 'part-' . $id,
                'channel' => 'left',
        ]], JSON_THROW_ON_ERROR);

        $job = new STTJob();
        $job->id = $id;
        $job->call_id = $callId;
        $job->cdr_id = $cdrId;
        $job->cdr_start_ms = (string)$startMs;
        $job->STTResult = $result;
        $result->STTJob = $job;
        STTJob::$rows[] = $job;

        return $result;
    };

    $customer = $makePart(10, 'mixed', 501, 100_000, '79001234567', '208', true);
    $makePart(11, 'mixed', 502, 130_000, '208', '206', false);
    $single = $makePart(12, 'single', 503, 200_000, '79007654321', '208', true);

    $service = new LogicalCallReviewService(
        $translation,
        $recordings,
        $speakerLabels,
        ['208' => true, '206' => true]
    );
    $review = $service->review('mixed', $customer);

    expectLogicalCallReview($review['showTabs'] === true, 'mixed customer/internal call must show scope tabs');
    expectLogicalCallReview(count($review['stages']) === 2, 'each recording part must remain an independent stage');
    expectLogicalCallReview(count($review['groups']['customer']) === 1, 'customer group must contain the external part');
    expectLogicalCallReview(count($review['groups']['internal']) === 1, 'internal group must contain the consultation');
    expectLogicalCallReview(
        $review['employees'] === ['208 (Портнов Алексей)', '206 (Васильева Анна)'],
        'review must expose every participating employee once'
    );
    expectLogicalCallReview(
        $review['groups']['internal'][0]['recording']['playbackUrl'] === '/recording?id=11',
        'each stage must use its own recording'
    );
    expectLogicalCallReview(
        $review['groups']['internal'][0]['conversation']['timeline'][0]['text'] === 'part-11',
        'each stage must use its own transcript'
    );

    $singleReview = $service->review('single', $single);
    expectLogicalCallReview($singleReview['showTabs'] === false, 'ordinary calls must keep the current presentation');
    expectLogicalCallReview(count($singleReview['stages']) === 1, 'ordinary call must keep one stage');

    echo "Logical call review checks passed\n";
}
