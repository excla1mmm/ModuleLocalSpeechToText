<?php

declare(strict_types=1);

require_once __DIR__ . '/../Lib/Services/CallTranscriptAssembler.php';

use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptAssembler;

function expectAssembler(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

/**
 * @param array<int, array<string, mixed>> $segments
 * @return array<string, mixed>
 */
function assemblerPart(
    int $resultId,
    int $cdrStartMs,
    string $source,
    array $segments,
    string $status = 'complete'
): array {
    return [
        'result_id' => $resultId,
        'job_id' => $resultId + 1000,
        'cdr_id' => $resultId + 2000,
        'uniqueid' => 'unique-' . $resultId,
        'cdr_start_ms' => $cdrStartMs,
        'cdr_end_ms' => $cdrStartMs + 60_000,
        'recording_fingerprint' => hash('sha256', 'recording-' . $resultId),
        'content_hash' => hash('sha256', json_encode($segments, JSON_UNESCAPED_UNICODE) ?: '[]'),
        'source_label' => $source,
        'resolution_status' => $status,
        'segments' => $segments,
    ];
}

$assembler = new CallTranscriptAssembler();
$parts = [
    assemblerPart(101, 1_000_000, 'incoming', [
        ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'incoming-only'],
        ['start_ms' => 1200, 'end_ms' => 2200, 'text' => 'shared phrase'],
    ]),
    assemblerPart(102, 1_001_200, 'consult', [
        ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'shared phrase'],
        ['start_ms' => 1200, 'end_ms' => 2200, 'text' => 'consult-only'],
    ]),
    assemblerPart(108, 1_003_500, 'continuation', [
        ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'continuation-only'],
    ]),
];

$assembled = $assembler->assemble('call-1', $parts);
expectAssembler(count($assembled['part_manifest']) === 3, 'all three parts must remain in the manifest');
expectAssembler(substr_count((string)$assembled['transcript']['text'], 'shared phrase') === 1, 'proven overlap must be emitted once');
foreach (['incoming-only', 'consult-only', 'continuation-only'] as $material) {
    expectAssembler(str_contains((string)$assembled['transcript']['text'], $material), "missing material: {$material}");
}

$replayed = $assembler->assemble('call-1', array_reverse($parts));
expectAssembler($assembled['content_hash'] === $replayed['content_hash'], 'part delivery order must not affect content hash');
expectAssembler($assembled['parts_hash'] === $replayed['parts_hash'], 'part delivery order must not affect parts hash');

$sameContentDifferentPartHash = $parts;
$sameContentDifferentPartHash[0]['recording_fingerprint'] = hash('sha256', 'replacement-file');
$sameContentDifferentPartHash[0]['content_hash'] = hash('sha256', 'replacement-recognition');
$sameContent = $assembler->assemble('call-1', $sameContentDifferentPartHash);
expectAssembler($assembled['content_hash'] === $sameContent['content_hash'], 'same assembled text must keep content hash');
expectAssembler($assembled['parts_hash'] !== $sameContent['parts_hash'], 'changed composition must change parts hash');

$changedParts = $parts;
$changedParts[2]['segments'][0]['text'] = 'changed-continuation';
$changedParts[2]['content_hash'] = hash('sha256', 'changed-continuation');
$changed = $assembler->assemble('call-1', $changedParts);
expectAssembler($assembled['content_hash'] !== $changed['content_hash'], 'changed material must change content hash');

$parallel = $assembler->assemble('call-parallel', [
    assemblerPart(201, 2_000_000, 'leg-a', [['start_ms' => 0, 'end_ms' => 2000, 'text' => 'left-leg']]),
    assemblerPart(202, 2_000_000, 'leg-b', [['start_ms' => 0, 'end_ms' => 2000, 'text' => 'right-leg']]),
]);
expectAssembler(str_contains((string)$parallel['transcript']['text'], '[leg-a] left-leg'), 'first parallel leg must be source-labelled');
expectAssembler(str_contains((string)$parallel['transcript']['text'], '[leg-b] right-leg'), 'second parallel leg must be source-labelled');

$unresolved = $assembler->assemble('call-legacy', [
    assemblerPart(302, 0, 'legacy:302', [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'second']], 'legacy_unresolved'),
    assemblerPart(301, 0, 'legacy:301', [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'first']], 'legacy_unresolved'),
]);
expectAssembler($unresolved['completeness_status'] === 'legacy_unresolved', 'unresolved legacy status must be explicit');
expectAssembler(
    strpos((string)$unresolved['transcript']['text'], 'first') < strpos((string)$unresolved['transcript']['text'], 'second'),
    'unresolved legacy parts must have deterministic result-id order'
);

$otherCall = $assembler->assemble('call-2', [$parts[0]]);
expectAssembler($assembled['content_hash'] !== $otherCall['content_hash'], 'different calls must never share canonical identity');

$coalesced = $assembler->assemble('call-coalesced', [
    assemblerPart(401, 3_000_000, 'cdr:401', [
        ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'first phrase', 'channel' => 'left', 'speaker' => 'left'],
        ['start_ms' => 1500, 'end_ms' => 2500, 'text' => 'second phrase', 'channel' => 'left', 'speaker' => 'left'],
        ['start_ms' => 2600, 'end_ms' => 3400, 'text' => 'customer reply', 'channel' => 'right', 'speaker' => 'right'],
    ]),
]);
expectAssembler(count($coalesced['transcript']['segments']) === 3, 'coalescing must not remove raw segments');
expectAssembler(count($coalesced['transcript']['turns']) === 2, 'adjacent same-speaker segments must form one turn');
expectAssembler(
    ($coalesced['transcript']['turns'][0]['text'] ?? '') === 'first phrase second phrase',
    'coalesced turn text mismatch'
);
expectAssembler(
    ($coalesced['transcript']['turns'][0]['source_segment_ids'] ?? []) === [
        'call:' . substr(hash('sha256', 'call-coalesced'), 0, 16) . ':r401:s0',
        'call:' . substr(hash('sha256', 'call-coalesced'), 0, 16) . ':r401:s1',
    ],
    'coalesced turn must retain source evidence ids'
);

echo "Call transcript assembler contract OK\n";
