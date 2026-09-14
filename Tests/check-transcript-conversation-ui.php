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
$template = (file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/transcript.volt') ?: '')
    . (file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/_transcript_stage.volt') ?: '');
$assets = file_get_contents($root . '/App/Providers/AssetProvider.php') ?: '';
$cdrJs = file_get_contents($root . '/public/assets/js/src/module-local-speech-to-text-cdr.js') ?: '';
$moduleJs = file_get_contents($root . '/public/assets/js/src/module-local-speech-to-text.js') ?: '';
$viewJsPath = $root . '/public/assets/js/src/module-local-speech-to-text-conversation.js';
$viewJs = is_file($viewJsPath) ? (file_get_contents($viewJsPath) ?: '') : '';
$css = file_get_contents($root . '/public/assets/css/module-local-speech-to-text.css') ?: '';

foreach (['stt-transcript-overview', 'stt-transcript-toolbar', 'stt-transcript-list', 'stt-transcript-turn'] as $class) {
    expectTranscriptUi(str_contains($template, $class), "module transcript view missing {$class}");
    expectTranscriptUi(str_contains($css, '.' . $class), "shared stylesheet missing {$class}");
}
expectTranscriptUi(str_contains($template, 'data-speaker='), 'module transcript rows must expose their speaker');
expectTranscriptUi(str_contains($template, "turn['gapBeforeLabel']"), 'module transcript must render silence markers');
expectTranscriptUi(!str_contains($template, 'stt-turn-overlap'), 'module transcript must not render overlap badges');
expectTranscriptUi(!str_contains($template, 'stt-turn-duration'), 'module transcript must not render per-turn durations');
expectTranscriptUi(str_contains($assets, 'module-local-speech-to-text-conversation.js'), 'shared transcript interaction asset missing');
expectTranscriptUi(str_contains($viewJs, 'window.SttTranscriptView'), 'shared transcript view API missing');
expectTranscriptUi(str_contains($viewJs, 'function initialize'), 'shared transcript filter initializer missing');
expectTranscriptUi(str_contains($viewJs, 'stt-transcript-search'), 'shared transcript search behavior missing');
expectTranscriptUi(!str_contains($viewJs, 'stt-turn-overlap'), 'CDR transcript must not render overlap badges');
expectTranscriptUi(!str_contains($viewJs, 'stt-turn-duration'), 'CDR transcript must not render per-turn durations');
expectTranscriptUi(str_contains($template, 'stt-transcript-range'), 'speech timeline must contain the player range control');
expectTranscriptUi(str_contains($template, 'stt-transcript-playhead'), 'speech timeline must render the live playhead');
expectTranscriptUi(
    str_contains($template, 'data-start="{{ turn[\'start\']|e }}"')
        && str_contains($template, 'data-end="{{ turn[\'end\']|e }}"'),
    'server-rendered speech lanes must expose segment timing for duration synchronization'
);
expectTranscriptUi(!str_contains($template, 'stt-player-range-wrap'), 'separate recording slider must be removed');
expectTranscriptUi(str_contains($viewJs, 'function initializePlayer'), 'shared interactive timeline player missing');
expectTranscriptUi(
    str_contains($template, 'aria-label="{{ t._(\'module_local_speech_to_text_DownloadRecording\')|e }}"')
        && str_contains($template, 'title="{{ t._(\'module_local_speech_to_text_DownloadRecording\')|e }}"'),
    'server-rendered download action must have an accessible name'
);
expectTranscriptUi(
    str_contains($viewJs, "'aria-label': labels.download")
        && str_contains($viewJs, 'title: labels.download'),
    'dynamic download action must have an accessible name'
);
expectTranscriptUi(
    str_contains($viewJs, 'function synchronizeTimeline(duration)')
        && str_contains($viewJs, "find('.stt-transcript-scale span').last()")
        && str_contains($viewJs, "audio.addEventListener('loadedmetadata', synchronize)"),
    'audio metadata must synchronize the scale label and speech geometry'
);
expectTranscriptUi(str_contains($viewJs, "audio.currentTime = (duration * value) / 1000"), 'timeline range must seek the recording');
expectTranscriptUi(!str_contains($moduleJs, 'function initializeTranscriptPlayer'), 'legacy separate player implementation must be removed');
expectTranscriptUi(str_contains($cdrJs, 'SttTranscriptView.create'), 'CDR modal must use the shared transcript renderer');
expectTranscriptUi(str_contains($cdrJs, 'audioUrl:'), 'CDR modal must pass its recording to the shared timeline player');
expectTranscriptUi(!str_contains($cdrJs, 'function dialogueTurn'), 'legacy CDR dialogue renderer must be removed');
expectTranscriptUi(str_contains($css, '--stt-speaker-left'), 'calm transcript palette tokens missing');
expectTranscriptUi(str_contains($template, 'showCallLegTabs'), 'call-leg tabs must be conditional');
expectTranscriptUi(str_contains($template, 'data-stt-call-leg="customer"'), 'customer-conversation tab missing');
expectTranscriptUi(str_contains($template, 'data-stt-call-leg="internal"'), 'internal-consultation tab missing');
expectTranscriptUi(str_contains($template, 'data-stt-call-leg-panel='), 'call-leg tab panels missing');
expectTranscriptUi(str_contains($template, "stage['result'].id"), 'stage actions must target the stage result');
expectTranscriptUi(!str_contains($template, 'EntireCallTab'), 'a separate entire-call tab must not be rendered');
expectTranscriptUi(
    str_contains($moduleJs, 'function initializeTranscriptLegTabs()')
        && str_contains($moduleJs, "audio.pause()"),
    'tab switching must initialize and pause recordings hidden by another tab'
);
expectTranscriptUi(str_contains($css, '.stt-call-leg-tabs'), 'call-leg tabs must use module-scoped styling');

echo "Transcript conversation UI contract OK\n";
