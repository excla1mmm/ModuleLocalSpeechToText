<?php

declare(strict_types=1);

function expectTrueTranscriptUserJsonDisplay(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/App/Controllers/TranscriptActionsController.php');
$baseController = file_get_contents($root . '/App/Controllers/LocalSpeechToTextBaseController.php');
$template = file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/transcript.volt');
$css = file_get_contents($root . '/public/assets/css/module-local-speech-to-text.css');

expectTrueTranscriptUserJsonDisplay($controller !== false, 'Cannot read transcript controller');
expectTrueTranscriptUserJsonDisplay($baseController !== false, 'Cannot read base transcript controller');
expectTrueTranscriptUserJsonDisplay($template !== false, 'Cannot read transcript template');
expectTrueTranscriptUserJsonDisplay($css !== false, 'Cannot read transcript stylesheet');
expectTrueTranscriptUserJsonDisplay(
    str_contains($baseController, 'TranscriptUserJsonService'),
    'Transcript controller must use the presentation-only JSON formatter'
);
expectTrueTranscriptUserJsonDisplay(
    str_contains($controller, '->transcriptJson($this->callMetadata('),
    'Transcript export must use the presentation-only JSON formatter'
);
expectTrueTranscriptUserJsonDisplay(
    !str_contains($controller, '$this->view->technicalInfo'),
    'Transcript controller must not expose technical diagnostics to the card'
);
expectTrueTranscriptUserJsonDisplay(
    !str_contains($template, 'stt-transcript-json'),
    'Transcript page must download JSON from the action menu instead of rendering a technical block'
);
expectTrueTranscriptUserJsonDisplay(
    !str_contains($template, "technicalInfo['groups']")
        && !str_contains($template, 'module_local_speech_to_text_TechnicalInformation'),
    'Transcript template must not render the technical diagnostics grid'
);
expectTrueTranscriptUserJsonDisplay(!str_contains($css, '.stt-transcript-json'), 'Obsolete JSON block styles must be removed');

echo "Transcript user JSON display contract OK\n";
