<?php

declare(strict_types=1);

function expectTranscriptListUi(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$css = file_get_contents(__DIR__ . '/../public/assets/css/module-local-speech-to-text.css') ?: '';
$javascriptFiles = [
    'source' => __DIR__ . '/../public/assets/js/src/module-local-speech-to-text.js',
    'compiled' => __DIR__ . '/../public/assets/js/module-local-speech-to-text.js',
];

foreach (['transcripts.volt', 'index.volt'] as $templateName) {
    $template = file_get_contents(
        __DIR__ . '/../App/Views/ModuleLocalSpeechToText/' . $templateName
    ) ?: '';
    foreach ([
        "row['directionIcon']",
        "row['clientLabel']",
        "row['employeesLabel']",
        "row['callDurationLabel']",
        "row['createdDateLabel']",
    ] as $needle) {
        expectTranscriptListUi(
            str_contains($template, $needle),
            "{$templateName} transcript list is missing {$needle}"
        );
    }
    expectTranscriptListUi(!str_contains($template, 'result.duration'), "{$templateName} must not display audio duration");
    expectTranscriptListUi(!str_contains($template, 'result.model_used'), "{$templateName} must not display the technical model");
    expectTranscriptListUi(!str_contains($template, "row['recordingName']"), "{$templateName} must not display the recording filename");
    expectTranscriptListUi(
        preg_match(
            '/\{% for row in transcriptRows %\}.*?\{% set result = row\[\'result\'\] %\}.*?<tr class="stt-transcript-row" data-transcript-url=/s',
            $template
        ) === 1,
        "{$templateName} rows must expose their transcript destination"
    );
}
expectTranscriptListUi(str_contains($css, '.stt-direction-link'), 'direction link styling missing');
expectTranscriptListUi(str_contains($css, '.stt-transcript-primary'), 'primary row text styling missing');
expectTranscriptListUi(
    preg_match('/\.stt-direction-cell\s*\{[^}]*overflow:\s*visible\s*!important;[^}]*text-align:\s*center;/s', $css) === 1,
    'direction cell must not clip and must center its link'
);
foreach ($javascriptFiles as $kind => $javascriptFile) {
    $js = file_get_contents($javascriptFile) ?: '';
    expectTranscriptListUi(
        str_contains($js, 'initializeTranscriptRows();')
            && str_contains($js, "$('.module-local-speech-to-text .stt-transcript-row').on('click'"),
        "transcript rows must initialize click navigation in {$kind} JavaScript"
    );
}

echo "Transcript list UI contract checks passed\n";
