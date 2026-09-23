<?php

declare(strict_types=1);

/**
 * Compact storage format of call transcript revision payloads: derived fields
 * are dropped and the JSON is deflated on write; legacy plain JSON stays
 * readable; readers get the same projected transcript from both forms.
 */

use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptAssembler;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptPayloadCodec;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptTimelineProjector;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptTurnProjector;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptPublicProjection;

function expectPayloadCodec(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
require_once $root . '/Lib/Services/CallTranscriptAssembler.php';
require_once $root . '/Lib/Services/CallTranscriptPayloadCodec.php';
require_once $root . '/Lib/Services/CallTranscriptTimelineProjector.php';
require_once $root . '/Lib/Services/TranscriptPublicProjection.php';

$assembled = (new CallTranscriptAssembler())->assemble('call-codec-001', [
    [
        'result_id' => 11,
        'job_id' => 21,
        'cdr_id' => 31,
        'uniqueid' => 'cdr-a',
        'cdr_start_ms' => 1_700_000_000_000,
        'cdr_end_ms' => 1_700_000_060_000,
        'recording_fingerprint' => str_repeat('f', 64),
        'content_hash' => str_repeat('c', 64),
        'source_label' => 'cdr:31',
        'resolution_status' => 'complete',
        'segments' => [
            ['start_ms' => 0, 'end_ms' => 1500, 'text' => 'Алло, здравствуйте.', 'channel' => 'left', 'speaker' => 'left'],
            ['start_ms' => 1600, 'end_ms' => 3000, 'text' => 'Добрый день, это МЧД.', 'channel' => 'left', 'speaker' => 'left'],
            ['start_ms' => 3500, 'end_ms' => 5000, 'text' => 'Да, слушаю.', 'channel' => 'right', 'speaker' => 'right'],
        ],
    ],
]);
$legacy = json_encode($assembled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$codec = new CallTranscriptPayloadCodec();

$project = static function (array $payload): array {
    $transcript = (new CallTranscriptTurnProjector())->project($payload['transcript']);
    $transcript = (new CallTranscriptTimelineProjector())->project($transcript, $payload['part_manifest']);

    return (new TranscriptPublicProjection())->project($transcript);
};

// Legacy plain JSON stays readable as-is.
$fromLegacy = $codec->decode($legacy);
expectPayloadCodec($fromLegacy === json_decode($legacy, true), 'legacy payload must decode unchanged');

// Compact encoding drops derived fields and deflates.
$compact = $codec->encode($assembled);
expectPayloadCodec($codec->isCompact($compact), 'encoded payload must carry the compressed prefix');
expectPayloadCodec(strlen($compact) < strlen($legacy), 'compact payload must be smaller than legacy JSON');
$stored = $codec->compact($assembled);
expectPayloadCodec(!isset($stored['transcript']['turns'], $stored['transcript']['text'], $stored['transcript']['turn_count']), 'turns/text are rebuilt by readers and must not be stored');
foreach ($stored['transcript']['segments'] as $segment) {
    foreach (['normalized_text', 'recording_fingerprint', 'resolution_status', 'source_index'] as $field) {
        expectPayloadCodec(!array_key_exists($field, $segment), "segment field {$field} must not be stored");
    }
    foreach (['segment_id', 'result_id', 'part_order', 'index', 'start_ms', 'end_ms', 'absolute_start_ms', 'absolute_end_ms', 'source_label', 'channel', 'speaker', 'text'] as $field) {
        expectPayloadCodec(array_key_exists($field, $segment), "segment field {$field} must be kept");
    }
}
expectPayloadCodec(($stored['content_hash'] ?? null) === $assembled['content_hash'] && ($stored['parts_hash'] ?? null) === $assembled['parts_hash'], 'hashes must be preserved');
expectPayloadCodec(($stored['part_manifest'] ?? null) === $assembled['part_manifest'], 'part manifest must be preserved');

// Decoding restores presentation fields; public projection is identical.
$fromCompact = $codec->decode($compact);
expectPayloadCodec(($fromCompact['transcript']['turns'] ?? null) === $assembled['transcript']['turns'], 'decoded turns must match the assembled turns');
expectPayloadCodec(($fromCompact['transcript']['text'] ?? null) === $assembled['transcript']['text'], 'decoded text must match the assembled text');
expectPayloadCodec($project($fromCompact) === $project($fromLegacy), 'public projection must not change after compaction');

// Recode is idempotent and leaves unreadable values alone.
$recoded = $codec->recode($legacy);
expectPayloadCodec(is_string($recoded) && $codec->isCompact($recoded), 'legacy payload must be recoded');
expectPayloadCodec($codec->recode($recoded) === null, 'recoding a compact payload must be a no-op');
expectPayloadCodec($codec->recode('') === null && $codec->recode('{broken-json') === null, 'empty or broken payloads must be left untouched');

// Empty transcripts keep a stable shape.
$empty = $codec->decode($codec->encode(['call_id' => 'x', 'part_manifest' => [], 'transcript' => ['segments' => [], 'segment_count' => 0, 'turns' => [], 'turn_count' => 0, 'text' => '']]));
expectPayloadCodec(($empty['transcript']['turns'] ?? null) === [] && ($empty['transcript']['text'] ?? null) === '' && ($empty['transcript']['turn_count'] ?? null) === 0, 'empty transcript must decode with empty turns and text');

// Broken input is reported, not silently accepted.
$threw = false;
try {
    $codec->decode('{broken-json');
} catch (JsonException) {
    $threw = true;
}
expectPayloadCodec($threw, 'broken legacy JSON must raise JsonException');
$threw = false;
try {
    $codec->decode(CallTranscriptPayloadCodec::COMPRESSED_PREFIX . 'not-base64!!');
} catch (RuntimeException) {
    $threw = true;
}
expectPayloadCodec($threw, 'corrupted compressed payload must raise RuntimeException');
expectPayloadCodec($codec->decodeOrEmpty('{broken-json') === [], 'decodeOrEmpty must swallow invalid payloads');

echo "Call transcript payload codec OK\n";
