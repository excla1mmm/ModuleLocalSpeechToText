<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models {
    final class StoredTurnConnection
    {
        public function fetchOne(string $sql, int $mode, array $bind = []): array
        {
            return ['value' => '0123456789abcdef0123456789abcdef'];
        }
    }

    final class STTSetting
    {
        public const string SOURCE_INSTANCE_ID = 'source_instance_id';

        public function getReadConnection(): StoredTurnConnection
        {
            return new StoredTurnConnection();
        }
    }

    final class STTCallTranscript
    {
        public string $call_id = 'mikopbx-regression-call';
        public int $current_revision = 1;

        public static function findFirstById(int $aggregateId): ?self
        {
            return $aggregateId === 4 ? new self() : null;
        }
    }

    final class STTCallTranscriptRevision
    {
        public static string $fixturePayload = '';
        public int $revision = 1;
        public string $content_hash = 'content-hash-before-projection';
        public string $parts_hash = 'parts-hash-before-projection';
        public string $completeness_status = 'complete';
        public int $created_at = 1_787_043_813;

        /** @param array<string, mixed> $query */
        public static function findFirst(array $query): ?self
        {
            $aggregateId = (int)($query['bind']['aggregate_id'] ?? 0);
            $revision = (int)($query['bind']['revision'] ?? 0);

            return $aggregateId === 4 && $revision === 1 ? new self() : null;
        }

        public function __construct()
        {
            $this->payload = self::$fixturePayload;
        }

        public string $payload;
    }
}

namespace {
    if (!class_exists('Phalcon\\Db\\Enum')) {
        eval('namespace Phalcon\\Db; class Enum { public const int FETCH_ASSOC = 2; }');
    }
    require_once __DIR__ . '/../Lib/Services/SttSourceInstance.php';
    require_once __DIR__ . '/../Lib/Services/CallTranscriptIntegrationService.php';

    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptIntegrationService;
    use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;

    function expectStoredTurnProjection(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    /** @return array<string, mixed> */
    function storedSegment(int $index, int $startMs, int $endMs, string $channel, string $text): array
    {
        return [
            'segment_id' => "call:regression:r4:s{$index}",
            'result_id' => 4,
            'source_index' => $index,
            'part_order' => 0,
            'index' => $index,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'absolute_start_ms' => 1_778_843_661_000 + $startMs,
            'absolute_end_ms' => 1_778_843_661_000 + $endMs,
            'text' => $text,
            'normalized_text' => mb_strtolower($text, 'UTF-8'),
            'source_label' => 'cdr:752027',
            'recording_fingerprint' => 'recording-fingerprint',
            'resolution_status' => 'complete',
            'channel' => $channel,
            'speaker' => $channel === 'left' ? 'agent' : 'customer',
        ];
    }

    $segments = [
        storedSegment(8, 24_500, 26_840, 'left', 'Почему-то снова, да, разъединила нас с вами.'),
        storedSegment(9, 27_800, 29_550, 'left', 'Так, я вижу, что модуль на телефон'),
        storedSegment(10, 29_550, 35_520, 'left', 'станции соединился вот здесь остался там все хорошо'),
        storedSegment(11, 35_520, 44_250, 'left', 'я здесь сейчас реконект идет может уйти'),
        storedSegment(12, 44_250, 50_220, 'left', 'ладно сейчас переподключить'),
        storedSegment(13, 50_220, 58_950, 'left', 'достаточное количество рабочих мест если сотрудники'),
        storedSegment(14, 58_950, 60_620, 'left', 'заняли много лицензий.'),
        storedSegment(15, 58_950, 73_650, 'right', 'Да, это надо посмотреть, если они не работают.'),
        storedSegment(16, 62_240, 63_760, 'left', 'Сейчас посмотрим, проверим.'),
    ];
    $legacyTurns = array_map(
        static fn (array $segment): array => [
            'turn_id' => $segment['segment_id'],
            'index' => $segment['index'],
            'start_ms' => $segment['start_ms'],
            'end_ms' => $segment['end_ms'],
            'absolute_start_ms' => $segment['absolute_start_ms'],
            'absolute_end_ms' => $segment['absolute_end_ms'],
            'text' => $segment['text'],
            'speaker' => $segment['source_label'],
            'channel' => $segment['channel'],
            'source_segment_ids' => [$segment['segment_id']],
            'source_result_ids' => [$segment['result_id']],
        ],
        $segments
    );
    STTCallTranscriptRevision::$fixturePayload = json_encode([
        'part_manifest' => [],
        'transcript' => [
            'segments' => $segments,
            'segment_count' => count($segments),
            'turns' => $legacyTurns,
            'turn_count' => count($legacyTurns),
            'text' => implode("\n", array_column($legacyTurns, 'text')),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $detail = (new CallTranscriptIntegrationService())->callTranscriptDetail(4, 1);
    $transcript = $detail['transcript'] ?? [];
    $turns = is_array($transcript['turns'] ?? null) ? $transcript['turns'] : [];

    expectStoredTurnProjection(!isset($transcript['segment_count'], $transcript['turn_count']), 'derived transcript counts must not be public');
    expectStoredTurnProjection(count($turns) === 3, 'stored one-to-one turns must be coalesced on read');
    expectStoredTurnProjection(
        !array_key_exists('_identity', $turns[0] ?? []),
        'internal grouping identity must not leak into the API payload'
    );
    expectStoredTurnProjection(!isset($turns[0]['source_segment_ids'], $turns[0]['source_result_ids']), 'turn internals must not leak into the API payload');
    expectStoredTurnProjection(
        ($turns[0]['start_ms'] ?? null) === 24_500 && ($turns[0]['end_ms'] ?? null) === 60_620,
        'merged turn range must cover all source segments'
    );
    expectStoredTurnProjection(
        !array_key_exists('speaker', $turns[0] ?? [])
            && !array_key_exists('speaker', $turns[1] ?? [])
            && !array_key_exists('speaker', $turns[2] ?? []),
        'public turns must not expose a redundant speaker field'
    );
    expectStoredTurnProjection(
        array_column($turns, 'channel') === ['left', 'right', 'left'],
        'physical channels must remain available to downstream role mapping'
    );
    expectStoredTurnProjection(
        str_contains((string)($transcript['text'] ?? ''), '[left]')
            && str_contains((string)($transcript['text'] ?? ''), '[right]')
            && !str_contains((string)($transcript['text'] ?? ''), '[agent]'),
        'public transcript text must identify physical channels, not worker speaker aliases'
    );
    expectStoredTurnProjection(
        ($transcript['segments'][0]['channel'] ?? '') === 'left'
            && !array_key_exists('speaker', $transcript['segments'][0] ?? []),
        'public segments must expose channel without speaker duplication'
    );
    expectStoredTurnProjection(
        ($detail['content_hash'] ?? '') === 'content-hash-before-projection',
        'presentation projection must not change revision content identity'
    );
    expectStoredTurnProjection(
        array_column($transcript['segments'] ?? [], 'segment_id') === array_column($segments, 'segment_id'),
        'presentation projection must preserve raw segment ids and order'
    );

    echo "Stored call transcript turn projection OK\n";
}
