<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptTechnicalInfoService;

require_once __DIR__ . '/../Lib/TranscriptTimeNormalizer.php';
require_once __DIR__ . '/../Lib/Services/TranscriptTechnicalInfoService.php';

final class FakeTechnicalInfoTranslation
{
    public function _(string $key): string
    {
        return match ($key) {
            'module_local_speech_to_text_SecondsShort' => 's',
            'module_local_speech_to_text_Yes' => 'yes',
            'module_local_speech_to_text_No' => 'no',
            default => $key,
        };
    }
}

function expectSameTechnicalInfoService(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function expectFloatTechnicalInfoService(float $expected, mixed $actual, string $message): void
{
    if (!is_numeric($actual) || abs($expected - (float)$actual) > 0.000001) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function expectTrueTechnicalInfoService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function technicalInfoMetric(array $data, string $path): mixed
{
    $value = $data;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }

    return $value;
}

$service = new TranscriptTechnicalInfoService(new FakeTechnicalInfoTranslation());

$empty = $service->technicalInfoCard('{not-json');
expectSameTechnicalInfoService(false, $empty['hasRows'], 'Malformed technical info must not render rows');
expectSameTechnicalInfoService([], $empty['groups'], 'Malformed technical info must not render groups');
expectSameTechnicalInfoService('', $empty['rawJson'], 'Malformed technical info must not echo raw input');

$payload = [
    'audio' => [
        'duration_ms' => 30000,
        'channels' => 2,
        'stereo_mode' => 'split',
        'split_channels' => true,
        'normalization' => false,
    ],
    'model' => [
        'resolved' => 'argmaxinc/whisperkit-coreml/large-v3',
        'language_hint' => 'en',
    ],
    'vad' => [
        'segments' => 3,
        'speech_ms' => 12000,
        'channels' => [
            'left' => [
                'detected_segments' => 1,
                'duration_ms' => 30000,
                'speech_ms' => 6000,
                'speech_ratio' => 0.2,
                'speech_windows' => [[0, 2], [1, 4]],
            ],
            'right' => [
                'detected_segments' => 2,
                'duration_ms' => 30000,
                'speech_ms' => 3000,
                'speech_ratio' => 0.1,
                'speech_windows' => [[5, 6]],
            ],
        ],
    ],
    'timing' => [
        'elapsed_ms' => 15000,
        'stt_command_ms' => 12000,
    ],
    'warnings' => ['low_volume', 'fallback_used'],
    'language' => [
        'dominant' => 'en',
        'votes_seconds' => [
            'en' => 12.5,
            'ru' => 2.0,
        ],
    ],
    'quality' => [
        'rejected_segments' => 1,
        'reasons' => [
            'empty_text' => 2,
        ],
    ],
];

$card = $service->technicalInfoCard(json_encode($payload, JSON_THROW_ON_ERROR));
expectSameTechnicalInfoService(true, $card['hasRows'], 'Normalized technical info must render rows');
expectTrueTechnicalInfoService($card['groups'] !== [], 'Normalized technical info must render non-empty groups');

$normalized = json_decode($card['rawJson'], true, 512, JSON_THROW_ON_ERROR);
expectFloatTechnicalInfoService(30.0, technicalInfoMetric($normalized, 'input.duration_seconds'), 'audio.duration_ms must map to input.duration_seconds');
expectSameTechnicalInfoService(2, technicalInfoMetric($normalized, 'input.channels'), 'audio.channels must map to input.channels');
expectSameTechnicalInfoService('split', technicalInfoMetric($normalized, 'pipeline.stereo_mode'), 'audio.stereo_mode must map to pipeline.stereo_mode');
expectSameTechnicalInfoService(true, technicalInfoMetric($normalized, 'pipeline.split_channels'), 'audio.split_channels must map to pipeline.split_channels');
expectSameTechnicalInfoService(false, technicalInfoMetric($normalized, 'pipeline.audio_normalize'), 'audio.normalization must map to pipeline.audio_normalize');
expectSameTechnicalInfoService(3, technicalInfoMetric($normalized, 'vad.detected_segments'), 'vad.segments must map to vad.detected_segments');
expectFloatTechnicalInfoService(12.0, technicalInfoMetric($normalized, 'vad.speech_seconds'), 'vad.speech_ms must be converted to seconds');
expectFloatTechnicalInfoService(0.2, technicalInfoMetric($normalized, 'vad.speech_ratio'), 'vad.speech_ratio must be recomputed against duration and channel count');
expectFloatTechnicalInfoService(5.0, technicalInfoMetric($normalized, 'vad.timeline_speech_seconds'), 'Overlapping channel windows must merge into timeline speech seconds');
expectFloatTechnicalInfoService(5.0 / 30.0, technicalInfoMetric($normalized, 'vad.timeline_speech_ratio'), 'Timeline speech ratio must use merged channel speech windows');
expectFloatTechnicalInfoService(15.0, technicalInfoMetric($normalized, 'timing.elapsed_seconds'), 'timing.elapsed_ms must be converted to seconds');
expectFloatTechnicalInfoService(0.5, technicalInfoMetric($normalized, 'timing.real_time_factor'), 'Real-time factor must be derived from elapsed and input duration');
expectFloatTechnicalInfoService(12.0, technicalInfoMetric($normalized, 'timing.stt_command_seconds'), 'timing.stt_command_ms must be converted to seconds');
expectSameTechnicalInfoService(2, technicalInfoMetric($normalized, 'transcription.warnings'), 'Warnings array must be exposed as a count');
expectFloatTechnicalInfoService(30.0, technicalInfoMetric($normalized, 'vad.channels.left.duration_seconds'), 'Channel duration_ms must be converted to seconds');
expectFloatTechnicalInfoService(6.0, technicalInfoMetric($normalized, 'vad.channels.left.speech_seconds'), 'Channel speech_ms must be converted to seconds');
expectFloatTechnicalInfoService(0.2, technicalInfoMetric($normalized, 'vad.channels.left.speech_ratio'), 'Channel speech ratio must be recomputed from seconds');

$channelGroups = array_values(array_filter(
    $card['groups'],
    static fn (array $group): bool => ($group['title'] ?? '') === 'module_local_speech_to_text_TechnicalGroupChannels'
));
expectSameTechnicalInfoService(1, count($channelGroups), 'Channel stats must render a dedicated technical group');
expectSameTechnicalInfoService(['left', 'right'], array_column($channelGroups[0]['rows'], 'label'), 'Channel rows must preserve channel labels');
expectTrueTechnicalInfoService(
    str_contains((string)$channelGroups[0]['rows'][0]['value'], '6 s'),
    'Channel row must include normalized speech seconds'
);

echo "Technical info service contract OK\n";
