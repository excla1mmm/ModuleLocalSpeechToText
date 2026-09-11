<?php

declare(strict_types=1);

function expectTranscriptUi(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$template = file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/transcript.volt') ?: '';
$assets = file_get_contents($root . '/App/Providers/AssetProvider.php') ?: '';
$cdrJs = file_get_contents($root . '/public/assets/js/src/module-local-speech-to-text-cdr.js') ?: '';
$viewJsPath = $root . '/public/assets/js/src/module-local-speech-to-text-conversation.js';
$viewJs = is_file($viewJsPath) ? (file_get_contents($viewJsPath) ?: '') : '';
$css = file_get_contents($root . '/public/assets/css/module-local-speech-to-text.css') ?: '';

foreach (['stt-transcript-overview', 'stt-transcript-toolbar', 'stt-transcript-list', 'stt-transcript-turn'] as $class) {
    expectTranscriptUi(str_contains($template, $class), "module transcript view missing {$class}");
    expectTranscriptUi(str_contains($css, '.' . $class), "shared stylesheet missing {$class}");
}
expectTranscriptUi(str_contains($template, 'data-speaker='), 'module transcript rows must expose their speaker');
expectTranscriptUi(str_contains($template, "turn['gapBeforeLabel']"), 'module transcript must render silence markers');
expectTranscriptUi(str_contains($template, "turn['overlap']"), 'module transcript must render overlap badges');
expectTranscriptUi(str_contains($assets, 'module-local-speech-to-text-conversation.js'), 'shared transcript interaction asset missing');
expectTranscriptUi(str_contains($viewJs, 'window.SttTranscriptView'), 'shared transcript view API missing');
expectTranscriptUi(str_contains($viewJs, 'function initialize'), 'shared transcript filter initializer missing');
expectTranscriptUi(str_contains($viewJs, 'stt-transcript-search'), 'shared transcript search behavior missing');
expectTranscriptUi(str_contains($cdrJs, 'SttTranscriptView.create'), 'CDR modal must use the shared transcript renderer');
expectTranscriptUi(!str_contains($cdrJs, 'function dialogueTurn'), 'legacy CDR dialogue renderer must be removed');
expectTranscriptUi(str_contains($css, '--stt-speaker-left'), 'calm transcript palette tokens missing');

echo "Transcript conversation UI contract OK\n";
