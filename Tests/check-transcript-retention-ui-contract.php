<?php

declare(strict_types=1);

function expectRetentionUi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$form = (string)file_get_contents($root . '/App/Forms/ModuleLocalSpeechToTextForm.php');
$view = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');
$sourceJs = (string)file_get_contents($root . '/public/assets/js/src/module-local-speech-to-text.js');
$bundledJs = (string)file_get_contents($root . '/public/assets/js/module-local-speech-to-text.js');
$css = (string)file_get_contents($root . '/public/assets/css/module-local-speech-to-text.css');

expectRetentionUi(
    str_contains($form, "new Hidden('transcript_retention_days'")
        && str_contains($form, "?? '365'"),
    'retention hidden form field must default to one year'
);
$historySliderPosition = strpos($view, 'stt-cdr-history-days-slider');
$retentionSliderPosition = strpos($view, 'stt-transcript-retention-days-slider');
$advancedPosition = strpos($view, 'stt-advanced-settings-dropdown');
expectRetentionUi(
    $historySliderPosition !== false
        && $retentionSliderPosition !== false
        && $advancedPosition !== false
        && $historySliderPosition < $retentionSliderPosition
        && $retentionSliderPosition < $advancedPosition,
    'retention slider must be directly below processing-window settings'
);
expectRetentionUi(
    str_contains($view, 'stt-retention-conflict-message')
        && str_contains($view, 'module_local_speech_to_text_TranscriptRetentionDaysHint'),
    'retention explanation and visible conflict message are missing'
);
expectRetentionUi(
    str_contains($sourceJs, "var values = ['1', '7', '30', '90', '180', '365', '0'];")
        && !str_contains($sourceJs, "'730'")
        && str_contains($sourceJs, "var values = ['30', '90', '180', '365', '0'];"),
    'slider presets must remove two years and add retention choices'
);
foreach ([
    'initializeTranscriptRetentionSlider',
    'retentionAllowsProcessing',
    'updateRetentionConflict',
    'sttRetentionCompatibility',
] as $needle) {
    expectRetentionUi(
        str_contains($sourceJs, $needle) && str_contains($bundledJs, $needle),
        "retention JavaScript assets are missing {$needle}"
    );
}
expectRetentionUi(
    str_contains($sourceJs, 'module_local_speech_to_text_TranscriptRetentionConflict')
        && str_contains($bundledJs, 'module_local_speech_to_text_TranscriptRetentionConflict'),
    'browser validation must use the localized conflict message'
);
expectRetentionUi(
    str_contains($css, '.stt-retention-conflict-message'),
    'retention conflict presentation CSS is missing'
);

echo "Transcript retention UI contract OK\n";
