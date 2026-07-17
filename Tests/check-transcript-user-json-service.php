<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptUserJsonService;

require_once __DIR__ . '/../Lib/Services/TranscriptUserJsonService.php';

final class FakeTranscriptUserJsonTranslation
{
    public function _(string $key): string
    {
        return match ($key) {
            'module_local_speech_to_text_Speaker1' => 'Участник 1',
            'module_local_speech_to_text_Speaker2' => 'Участник 2',
            'module_local_speech_to_text_Speaker' => 'Участник',
            default => $key,
        };
    }
}

function expectSameTranscriptUserJson(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

$service = new TranscriptUserJsonService(new FakeTranscriptUserJsonTranslation());
$call = [
    'id' => ' call-42 ',
    'started_at' => '2026-07-17 12:00:00',
    'ended_at' => '2026-07-17 12:02:02',
    'duration_seconds' => '122.088',
    'source_number' => ' 201 ',
    'destination_number' => '74950000000',
    'language' => ' ru ',
    'model' => ' large-v3-v20240930_turbo ',
    'technical_info' => ['must_not' => 'leak'],
];
$transcript = json_encode([
    [
        'speaker' => 'left',
        'channel' => 'left',
        'start_ms' => 100,
        'end_ms' => 1200,
        'text' => ' Первая фраза ',
        'prompt_tokens' => 10,
    ],
    [
        'speaker' => 'right',
        'start_ms' => 1300,
        'end_ms' => 2200,
        'text' => 'Вторая фраза',
    ],
    [
        'speaker' => 'agent',
        'text' => 'Без времени',
    ],
    [
        'start_ms' => 2300,
        'end_ms' => 2600,
        'text' => 'Без канала',
    ],
    ['speaker' => 'left', 'start_ms' => 2700, 'end_ms' => 2800, 'text' => '   '],
    'invalid segment',
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$json = $service->transcriptJson($call, $transcript);
$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

expectSameTranscriptUserJson([
    'call' => [
        'id' => 'call-42',
        'started_at' => '2026-07-17 12:00:00',
        'ended_at' => '2026-07-17 12:02:02',
        'duration_seconds' => 122.088,
        'source_number' => '201',
        'destination_number' => '74950000000',
        'language' => 'ru',
        'model' => 'large-v3-v20240930_turbo',
    ],
    'phrases' => [
        ['speaker' => 'Участник 1', 'start_ms' => 100, 'end_ms' => 1200, 'text' => 'Первая фраза'],
        ['speaker' => 'Участник 2', 'start_ms' => 1300, 'end_ms' => 2200, 'text' => 'Вторая фраза'],
        ['speaker' => 'agent', 'start_ms' => null, 'end_ms' => null, 'text' => 'Без времени'],
        ['speaker' => 'Участник', 'start_ms' => 2300, 'end_ms' => 2600, 'text' => 'Без канала'],
    ],
], $decoded, 'User JSON must contain only normalized call metadata and every non-empty phrase in source order');

if (!str_contains($json, 'Участник 1') || str_contains($json, '\\u')) {
    throw new RuntimeException('User JSON must keep localized Cyrillic readable');
}
if (str_contains($json, 'technical_info') || str_contains($json, 'prompt_tokens')) {
    throw new RuntimeException('User JSON must not expose technical diagnostics');
}

$malformed = json_decode($service->transcriptJson([
    'id' => '',
    'duration_seconds' => null,
], '{not-json'), true, 512, JSON_THROW_ON_ERROR);
expectSameTranscriptUserJson([
    'id' => null,
    'started_at' => null,
    'ended_at' => null,
    'duration_seconds' => null,
    'source_number' => null,
    'destination_number' => null,
    'language' => null,
    'model' => null,
], $malformed['call'] ?? null, 'Unavailable call metadata must use stable null-valued keys');
expectSameTranscriptUserJson([], $malformed['phrases'] ?? null, 'Malformed transcript JSON must produce an empty phrase list');

echo "Transcript user JSON service contract OK\n";
