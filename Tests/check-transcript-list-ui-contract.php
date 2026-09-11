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
}
expectTranscriptListUi(str_contains($css, '.stt-direction-link'), 'direction link styling missing');
expectTranscriptListUi(str_contains($css, '.stt-transcript-primary'), 'primary row text styling missing');

echo "Transcript list UI contract checks passed\n";
