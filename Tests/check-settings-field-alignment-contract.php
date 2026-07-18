<?php

declare(strict_types=1);

function expectFieldAlignment(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$view = file_get_contents(__DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt');
$css = file_get_contents(__DIR__ . '/../public/assets/css/module-local-speech-to-text.css');

expectFieldAlignment($view !== false, 'Cannot read settings view');
expectFieldAlignment($css !== false, 'Cannot read module stylesheet');
expectFieldAlignment(
    substr_count($view, 'stt-settings-fields') === 2,
    'Primary settings rows must opt in to field-based alignment'
);
expectFieldAlignment(
    str_contains($css, '.stt-settings-fields > .field')
        && str_contains($css, 'grid-template-rows: minmax(0, 1fr) auto'),
    'Primary settings fields must pin controls below their labels'
);

echo "Settings field alignment contract OK\n";
