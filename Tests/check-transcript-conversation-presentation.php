<?php

declare(strict_types=1);

require_once __DIR__ . '/../Lib/TranscriptTimeNormalizer.php';
require_once __DIR__ . '/../Lib/Services/TranscriptConversationService.php';

use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptConversationService;

function expectTranscriptPresentation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$translation = new class {
    public function _(string $key): string
    {
        return match ($key) {
            'module_local_speech_to_text_Speaker1' => 'Speaker 1',
            'module_local_speech_to_text_Speaker2' => 'Speaker 2',
            'module_local_speech_to_text_SilenceDuration' => 'silence %seconds% s',
            default => $key,
        };
    }
};

$segments = [
    ['speaker' => 'left', 'start_ms' => 0, 'end_ms' => 30_000, 'text' => 'First'],
    ['speaker' => 'right', 'start_ms' => 0, 'end_ms' => 20_000, 'text' => 'Second'],
    ['speaker' => 'left', 'start_ms' => 60_000, 'end_ms' => 70_000, 'text' => 'After silence'],
    ['speaker' => 'right', 'start_ms' => 68_000, 'end_ms' => 75_000, 'text' => 'Overlapping reply'],
];
$conversation = (new TranscriptConversationService($translation))->conversationFromTranscript(
    json_encode($segments, JSON_THROW_ON_ERROR)
);

expectTranscriptPresentation($conversation['durationSeconds'] === 75.0, 'conversation duration must reach the last turn');
expectTranscriptPresentation($conversation['durationLabel'] === '1:15', 'conversation duration label mismatch');
expectTranscriptPresentation($conversation['turnCount'] === 4, 'turn count mismatch');
$turnsByText = [];
foreach ($conversation['timeline'] as $turn) {
    $turnsByText[$turn['text']] = $turn;
}
expectTranscriptPresentation($turnsByText['First']['startLabel'] === '0:00', 'turn start label mismatch');
expectTranscriptPresentation($turnsByText['First']['durationLabel'] === '0:30', 'turn duration label mismatch');
expectTranscriptPresentation($turnsByText['First']['overlap'] === true, 'simultaneous speech must be marked');
expectTranscriptPresentation(
    $turnsByText['After silence']['gapBeforeLabel'] === 'silence 30 s',
    'long silence must use the localized compact duration label'
);
expectTranscriptPresentation($turnsByText['After silence']['startPercent'] === 80.0, 'timeline start percentage mismatch');
expectTranscriptPresentation(
    $conversation['speakers'][0]['segments'][1]['text'] === 'After silence',
    'speaker lanes must contain enriched turns'
);

echo "Transcript conversation presentation OK\n";
