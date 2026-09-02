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
        'speaker' => 'left',
    ],
    'segments must expose milliseconds and speaker with channel fallback only'
);
expectTranscriptPublicProjection(
    !array_key_exists('start', $turn)
        && !array_key_exists('end', $turn)
        && !array_key_exists('channel', $turn)
        && ($turn['speaker'] ?? null) === 'operator',
    'turns must preserve an explicit speaker and omit duplicate public fields'
);
expectTranscriptPublicProjection(
    !isset($projected['segment_count'], $projected['turn_count']),
    'projection must omit derived transcript counts'
);

echo "Transcript public projection OK\n";
