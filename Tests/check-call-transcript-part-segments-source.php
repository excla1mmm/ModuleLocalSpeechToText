<?php

declare(strict_types=1);

/**
 * Compact parts do not duplicate their segments: a new part stores the
 * SEGMENTS_IN_RESULT marker and every later assembly reads the segments of
 * earlier legs from their m_STTResult row, while legacy parts that still carry
 * a copy keep using it. The staged payload is written in the compact format.
 */

namespace Modules\ModuleLocalSpeechToText\Models {
    abstract class PartSourceMemoryModel
    {
        /** @var array<string, array<int, static>> */
        public static array $rows = [];
        public int $id = 0;

        public function save(): bool
        {
            if ($this->id === 0) {
                $this->id = count(self::$rows[static::class] ?? []) + 1;
            }
            self::$rows[static::class][$this->id] = $this;

            return true;
        }

        public function getMessages(): array
        {
            return [];
        }

        /** @return list<static> */
        protected static function all(): array
        {
            return array_values(self::$rows[static::class] ?? []);
        }
    }

    final class STTSetting
    {
        public const string CALL_TRANSCRIPT_STABILITY_SEC = 'call_transcript_stability_sec';

        public static function getValue(string $key, string $default = ''): string
        {
            return $default;
        }
    }

    final class STTJob
    {
        public int $id = 0;
        public string $call_id = '';
        public string $recording_fingerprint = '';
        public int $cdr_id = 0;
        public string $cdr_uniqueid = '';
        public int $cdr_start_ms = 0;
        public int $cdr_end_ms = 0;
    }

    final class STTResult extends PartSourceMemoryModel
    {
        public string $transcript = '[]';
        public static int $lookups = 0;

        public static function findFirst(array $query): ?self
        {
            self::$lookups++;

            return self::$rows[self::class][(int)$query['bind']['id']] ?? null;
        }
    }

    final class STTCallTranscript extends PartSourceMemoryModel
    {
        public string $call_id = '';
        public string $current_revision = '0';
        public string $current_content_hash = '';
        public string $current_parts_hash = '';
        public string $pending_content_hash = '';
        public string $pending_parts_hash = '';
        public string $pending_payload = '';
        public string $completeness_status = 'pending';
        public string $stable_after = '0';
        public string $published_at = '0';
        public string $created_at = '0';
        public string $updated_at = '0';

        public static function findFirst(array $query): ?self
        {
            foreach (self::all() as $row) {
                if ($row->call_id === $query['bind']['call_id']) {
                    return $row;
                }
            }

            return null;
        }
    }

    final class STTCallTranscriptPart extends PartSourceMemoryModel
    {
        public int $call_transcript_id = 0;
        public int $result_id = 0;
        public ?int $job_id = null;
        public ?int $cdr_id = null;
        public string $cdr_uniqueid = '';
        public ?string $cdr_start_ms = null;
        public ?string $cdr_end_ms = null;
        public string $recording_fingerprint = '';
        public string $content_hash = '';
        public string $source_label = '';
        public string $resolution_status = 'complete';
        public string $segments_json = '[]';
        public string $is_current = '1';
        public ?int $superseded_by_part_id = null;
        public string $created_at = '0';

        public static function findFirst(array $query): ?self
        {
            foreach (self::all() as $row) {
                if ($row->call_transcript_id === (int)$query['bind']['call_id']
                    && $row->result_id === (int)$query['bind']['result_id']
                    && $row->is_current === '1'
                ) {
                    return $row;
                }
            }

            return null;
        }

        /** @return list<self> */
        public static function find(array $query): array
        {
            return array_values(array_filter(
                self::all(),
                static fn (self $row): bool => $row->call_transcript_id === (int)$query['bind']['call_id']
                    && $row->is_current === '1'
            ));
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptPayloadCodec;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionPolicy;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
    use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
    use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptPart;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTResult;

    function expectPartSource(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    $root = dirname(__DIR__);
    require_once $root . '/Lib/Exceptions/ResultPersistenceValidationException.php';
    require_once $root . '/Lib/Services/CallTranscriptAssembler.php';
    require_once $root . '/Lib/Services/CallTranscriptRevisionPolicy.php';
    require_once $root . '/Lib/Services/CallTranscriptRevisionService.php';

    $job = static function (int $id, int $cdrId, int $startMs): STTJob {
        $job = new STTJob();
        $job->id = $id;
        $job->call_id = 'call-multi-leg';
        $job->recording_fingerprint = str_repeat((string)$id, 64);
        $job->cdr_id = $cdrId;
        $job->cdr_uniqueid = 'cdr-' . $cdrId;
        $job->cdr_start_ms = $startMs;
        $job->cdr_end_ms = $startMs + 10_000;

        return $job;
    };
    $result = static function (array $segments): STTResult {
        $result = new STTResult();
        $result->transcript = json_encode($segments, JSON_UNESCAPED_UNICODE);
        $result->save();

        return $result;
    };

    $codec = new CallTranscriptPayloadCodec();
    $service = new CallTranscriptRevisionService(null, new CallTranscriptRevisionPolicy(0), $codec);

    // First leg is stored compactly and reads its segments from the result.
    $firstResult = $result([['start_ms' => 0, 'end_ms' => 900, 'text' => 'first leg', 'channel' => 'left']]);
    $service->recordCompletedPart($firstResult, $job(1, 501, 1_700_000_000_000));
    $firstPart = STTCallTranscriptPart::find(['bind' => ['call_id' => 1]])[0];
    expectPartSource($firstPart->segments_json === CallTranscriptRevisionService::SEGMENTS_IN_RESULT, 'new part must not duplicate the result segments');
    expectPartSource(STTResult::$lookups === 0, 'the part being recorded must use the result already in memory');

    // Second leg: the first leg's segments must be loaded from its result row.
    $secondResult = $result([['start_ms' => 0, 'end_ms' => 800, 'text' => 'second leg', 'channel' => 'right']]);
    $service->recordCompletedPart($secondResult, $job(2, 502, 1_700_000_020_000));
    expectPartSource(STTResult::$lookups === 1, 'earlier compact legs must be read from m_STTResult');
    $call = STTCallTranscript::findFirst(['bind' => ['call_id' => 'call-multi-leg']]);
    expectPartSource($call instanceof STTCallTranscript && $codec->isCompact($call->pending_payload), 'pending payload must be stored in the compact format');
    $pending = $codec->decode($call->pending_payload);
    $texts = array_column($pending['transcript']['segments'], 'text');
    expectPartSource($texts === ['first leg', 'second leg'], 'assembled transcript must contain both legs: ' . json_encode($texts));
    expectPartSource(str_contains((string)$pending['transcript']['text'], 'first leg') && str_contains((string)$pending['transcript']['text'], 'second leg'), 'decoded text must be rebuilt from both legs');

    // A legacy part that still carries its own copy keeps using it.
    $firstPart->segments_json = json_encode([['start_ms' => 0, 'end_ms' => 900, 'text' => 'legacy copy', 'channel' => 'left']]);
    $thirdResult = $result([['start_ms' => 0, 'end_ms' => 700, 'text' => 'third leg', 'channel' => 'left']]);
    $lookupsBefore = STTResult::$lookups;
    $service->recordCompletedPart($thirdResult, $job(3, 503, 1_700_000_040_000));
    $pending = $codec->decode($call->pending_payload);
    $texts = array_column($pending['transcript']['segments'], 'text');
    expectPartSource(in_array('legacy copy', $texts, true) && !in_array('first leg', $texts, true), 'legacy part copy must take precedence over the result row');
    expectPartSource(STTResult::$lookups === $lookupsBefore + 1, 'only the compact second leg must be looked up');

    echo "Call transcript part segment source OK\n";
}
