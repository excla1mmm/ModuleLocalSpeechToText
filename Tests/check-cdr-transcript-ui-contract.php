<?php

declare(strict_types=1);

function expectCdrUi(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$conf = file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextConf.php') ?: '';
$assets = file_get_contents(__DIR__ . '/../App/Providers/AssetProvider.php') ?: '';
$modalPath = __DIR__ . '/../App/Views/ModuleLocalSpeechToText/cdrTranscriptModal.volt';
$modal = is_file($modalPath) ? (file_get_contents($modalPath) ?: '') : '';

expectCdrUi(str_contains($conf, 'onAfterAssetsPrepared'), 'CDR asset hook missing');
expectCdrUi(str_contains($conf, 'onVoltBlockCompile'), 'CDR Footer hook missing');
expectCdrUi(str_contains($assets, 'module-local-speech-to-text-cdr.css'), 'CDR CSS missing');
expectCdrUi(str_contains($assets, 'module-local-speech-to-text-cdr.js'), 'CDR JS missing');
expectCdrUi(str_contains($modal, 'id="stt-cdr-transcript-modal"'), 'modal shell missing');
expectCdrUi(str_contains($modal, 'module-local-speech-to-text'), 'existing transcript styles must be reusable');

$keys = [
    'module_local_speech_to_text_CdrTranscriptButton',
    'module_local_speech_to_text_CdrTranscriptModalTitle',
    'module_local_speech_to_text_CdrTranscriptLoading',
    'module_local_speech_to_text_CdrTranscriptLoadError',
    'module_local_speech_to_text_CdrTranscriptNotFound',
];
foreach (glob(__DIR__ . '/../Messages/*.php') ?: [] as $messageFile) {
    $messages = require $messageFile;
    foreach ($keys as $key) {
        expectCdrUi(
            isset($messages[$key]) && trim((string)$messages[$key]) !== '',
            basename($messageFile) . " must define {$key}"
        );
    }
}

$sourcePath = __DIR__ . '/../public/assets/js/src/module-local-speech-to-text-cdr.js';
$compiledPath = __DIR__ . '/../public/assets/js/module-local-speech-to-text-cdr.js';
$sourceJs = is_file($sourcePath) ? (file_get_contents($sourcePath) ?: '') : '';
$compiledJs = is_file($compiledPath) ? (file_get_contents($compiledPath) ?: '') : '';
expectCdrUi(
    str_contains($sourceJs, 'function ensureModal()')
    && str_contains($sourceJs, "\$('body').append(\$modal)"),
    'CDR JavaScript must create the modal when the Core template has no Volt hook'
);
foreach ([
    'init.dt',
    'draw.dt',
    '#cdr-table tbody tr[id]',
    ':scope > td',
    'actionsCell',
    "method: 'POST'",
    "contentType: 'application/json; charset=utf-8'",
    'JSON.stringify({ call_ids: batch })',
    'cdr-transcript/lookup',
    'cdr-transcript/detail',
    'stt-cdr-transcript-button',
    '.text(',
] as $needle) {
    expectCdrUi(str_contains($sourceJs, $needle), "CDR source JS missing {$needle}");
}
expectCdrUi(str_contains($sourceJs, 'MAX_LOOKUP_BATCH = 100'), 'lookup batch cap missing');
expectCdrUi(
    preg_match('/\.html\([^)]*(turn|text|transcript)/i', $sourceJs) !== 1,
    'transcript text must not enter html()'
);
expectCdrUi(is_file($compiledPath) && filesize($compiledPath) > 0, 'compiled CDR JavaScript missing');
expectCdrUi(!str_contains($sourceJs, '/^mikopbx-'), 'legacy call IDs must not be rejected by a prefix-only regex');
expectCdrUi(str_contains($sourceJs, "callId === '' || callId.length > 255"), 'call ID validation must match the backend contract');
expectCdrUi(str_contains($sourceJs, 'delete availability[callId]'), 'negative lookup results must not be cached');
expectCdrUi(str_contains($sourceJs, 'function stopModalPlayback()'), 'modal playback cleanup is missing');
expectCdrUi(str_contains($sourceJs, 'playbackCdrId'), 'CDR recording selection must use the server presentation model');
expectCdrUi(!str_contains($sourceJs, 'recordings[0]'), 'aggregate transcript must not be bound to the first recording');
expectCdrUi(!str_contains($compiledJs, '/^mikopbx-'), 'compiled JavaScript must accept legacy call IDs');
expectCdrUi(str_contains($compiledJs, 'playbackCdrId'), 'compiled JavaScript must select the server-authorized recording');
expectCdrUi(str_contains($compiledJs, 'function stopModalPlayback()'), 'compiled JavaScript must stop modal playback');
expectCdrUi(!str_contains($compiledJs, 'require('), 'compiled CDR JavaScript must run standalone');
expectCdrUi(
    str_contains($modal, 'role="alert"') && str_contains($modal, 'aria-live="assertive"'),
    'modal errors must be announced to assistive technology'
);

echo "CDR transcript UI contract OK\n";
