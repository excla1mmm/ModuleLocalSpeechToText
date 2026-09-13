<?php

declare(strict_types=1);

function expectTranscriptPublicProjection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Transcript public projection failed: {$message}\n");
        exit(1);
    }
}

$serviceFile = __DIR__ . '/../Lib/Services/TranscriptPublicProjection.php';
expectTranscriptPublicProjection(is_file($serviceFile), 'public transcript projection service is missing');
require_once $serviceFile;

$projected = (new \Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptPublicProjection())->project([
    'segments' => [
        [
            'segment_id' => 'stt:3048:2',
            'index' => 2,
            'start_ms' => 5440,
            'end_ms' => 10080,
            'start' => 5.44,
            'end' => 10.08,
            'text' => 'Это Людмила.',
            'channel' => 'left',
            'speaker' => '',
        ],
    ],
    'turns' => [
        [
            'turn_id' => 'stt:3048:turn:2',
            'start_ms' => 5440,
            'end_ms' => 10080,
            'start' => 5.44,
            'end' => 10.08,
            'text' => 'Это Людмила.',
            'channel' => 'left',
            'speaker' => 'operator',
        ],
    ],
    'segment_count' => 1,
    'turn_count' => 1,
    'text' => 'operator: Это Людмила.',
]);

$segment = $projected['segments'][0] ?? [];
$turn = $projected['turns'][0] ?? [];

expectTranscriptPublicProjection(
    $segment === [
        'segment_id' => 'stt:3048:2',
        'index' => 2,
        'start_ms' => 5440,
        'end_ms' => 10080,
        'text' => 'Это Людмила.',
        'channel' => 'left',
    ],
    'segments must expose channel without a redundant speaker field'
);
expectTranscriptPublicProjection(
    !array_key_exists('start', $turn)
        && !array_key_exists('end', $turn)
        && !array_key_exists('speaker', $turn)
        && ($turn['channel'] ?? null) === 'left',
    'turns must expose physical channel only'
);
expectTranscriptPublicProjection(
    !isset($projected['segment_count'], $projected['turn_count']),
    'projection must omit derived transcript counts'
);
expectTranscriptPublicProjection(
    ($projected['text'] ?? '') === '[left] Это Людмила.',
    'public transcript text must use physical channels instead of worker speaker aliases'
);

$legProjected = (new \Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptPublicProjection())->project([
    'segments' => [
        [
            'segment_id' => 'call:abc:r10:s0',
            'index' => 0,
            'result_id' => 10,
            'start_ms' => 30_000,
            'end_ms' => 32_000,
            'recording_start_ms' => 0,
            'recording_end_ms' => 2_000,
            'absolute_start_ms' => 1_030_000,
            'absolute_end_ms' => 1_032_000,
            'text' => 'Реплика второй части.',
            'channel' => 'right',
        ],
    ],
    'turns' => [
        [
            'turn_id' => 'call:abc:r10:turn:0',
            'index' => 0,
            'source_result_ids' => [10],
            'start_ms' => 30_000,
            'end_ms' => 32_000,
            'recording_start_ms' => 0,
            'recording_end_ms' => 2_000,
            'absolute_start_ms' => 1_030_000,
            'absolute_end_ms' => 1_032_000,
            'text' => 'Реплика второй части.',
            'channel' => 'right',
        ],
    ],
    'timeline_reliable' => true,
]);

$legSegment = $legProjected['segments'][0] ?? [];
expectTranscriptPublicProjection(
    $legSegment === [
        'segment_id' => 'call:abc:r10:s0',
        'index' => 0,
        'result_id' => 10,
        'start_ms' => 30_000,
        'end_ms' => 32_000,
        'recording_start_ms' => 0,
        'recording_end_ms' => 2_000,
        'text' => 'Реплика второй части.',
        'channel' => 'right',
    ],
    'public segments must keep part binding and both time scales without absolute codes'
);
$legTurn = $legProjected['turns'][0] ?? [];
expectTranscriptPublicProjection(
    $legTurn === [
        'turn_id' => 'call:abc:r10:turn:0',
        'index' => 0,
        'source_result_ids' => [10],
        'start_ms' => 30_000,
        'end_ms' => 32_000,
        'recording_start_ms' => 0,
        'recording_end_ms' => 2_000,
        'text' => 'Реплика второй части.',
        'channel' => 'right',
    ],
    'public turns must keep source result ids and both time scales without absolute codes'
);
expectTranscriptPublicProjection(
    ($legProjected['timeline_reliable'] ?? null) === true,
    'public projection must keep the timeline reliability flag'
);

echo "Transcript public projection OK\n";
