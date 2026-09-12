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

$formStart = strpos($view, '<form class="ui form large" id="module-local-speech-to-text-form"');
$advancedStart = strpos($view, 'stt-advanced-settings-dropdown');
expectFieldAlignment(
    $formStart !== false && $advancedStart !== false && $formStart < $advancedStart,
    'Cannot locate the primary settings section of the settings form'
);
$primarySection = substr($view, $formStart, $advancedStart - $formStart);

$primaryFields = [
    'default_language',
];
foreach ($primaryFields as $field) {
    expectFieldAlignment(
        str_contains($primarySection, "{{ form.render('" . $field . "') }}"),
        sprintf('Primary settings field %s must be rendered in the primary section', $field)
    );
}

expectFieldAlignment(
    preg_match('/class="[^"]*\bfields\b[^"]*"/', $primarySection) !== 1,
    'Primary settings fields must be stacked one per row without multi-column field groups'
);
expectFieldAlignment(
    !str_contains($view, 'stt-settings-fields'),
    'Primary settings rows must not use the retired stt-settings-fields opt-in'
);
expectFieldAlignment(
    !str_contains($css, 'stt-settings-fields'),
    'Retired stt-settings-fields grid alignment must stay out of the module stylesheet'
);

$removedFields = [
    'poll_interval_sec',
    'max_recording_duration_min',
    'cdr_scan_limit',
    'processing_timeout_sec',
];
foreach ($removedFields as $field) {
    expectFieldAlignment(
        !str_contains($view, "'" . $field . "'"),
        sprintf('Removed internal-policy field %s must no longer appear in the settings view', $field)
    );
}

echo "Settings field alignment contract OK\n";
