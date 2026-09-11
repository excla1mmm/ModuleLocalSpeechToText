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
expectCdrUi(str_contains($conf, "'CallDetailRecords'"), 'CDR controller gate missing');
expectCdrUi(str_contains($conf, "'Footer'"), 'Footer block gate missing');
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
expectCdrUi(
    str_contains($sourceJs, 'function ensureModal()')
    && str_contains($sourceJs, "\$('body').append(\$modal)"),
    'CDR JavaScript must create the modal when the Core template has no Volt hook'
);
foreach ([
    'init.dt',
    'draw.dt',
    'DT_RowId',
    "rows({ page: 'current' })",
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

echo "CDR transcript UI contract OK\n";
