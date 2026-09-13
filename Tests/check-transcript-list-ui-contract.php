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
        str_contains($template, 'name="search"')
            && str_contains($template, "transcriptSearch['query']")
            && str_contains($template, 'stt-transcripts-search-field')
            && str_contains($template, 'stt-transcripts-date-field'),
        "{$templateName} must place transcript search on the left and call dates on the right"
    );
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
    str_contains($css, '.stt-transcripts-filter-fields')
        && str_contains($css, '.stt-transcripts-date-field'),
    'responsive transcript search/date filter layout is missing'
);
expectTranscriptListUi(
    preg_match('/\.stt-transcripts-filter-fields\s*\{[^}]*align-items:\s*flex-start;[^}]*gap:\s*1em;[^}]*justify-content:\s*flex-start;/s', $css) === 1
        && preg_match('/\.stt-transcripts-search-field\s*\{[^}]*flex:\s*1 1 auto;[^}]*max-width:\s*none;/s', $css) === 1
        && preg_match('/\.stt-transcripts-date-field\s*\{[^}]*flex:\s*0 0 22em;[^}]*margin-left:\s*0;/s', $css) === 1,
    'desktop transcript filters must top-align a compact date range beside a fluid search'
);
expectTranscriptListUi(
    preg_match('/\.stt-direction-cell\s*\{[^}]*overflow:\s*visible\s*!important;[^}]*text-align:\s*center;/s', $css) === 1,
    'direction cell must not clip and must center its link'
);
foreach ($javascriptFiles as $kind => $javascriptFile) {
    $js = file_get_contents($javascriptFile) ?: '';
    expectTranscriptListUi(
        str_contains($js, 'initializeTranscriptRows();')
            && str_contains($js, "$('.module-local-speech-to-text .stt-transcript-row').on('click'")
            && str_contains($js, '$form.find(\'input[name="search"]\')'),
        "transcript rows must initialize click navigation in {$kind} JavaScript"
    );
    expectTranscriptListUi(
        preg_match('/\$search\.on\(\'input\', function \(\) \{\s*cancelSearchDebounce\(\);\s*searchDebounceTimer = window\.setTimeout\(function \(\) \{.*?\$form\.trigger\(\'submit\'\);.*?\}, 400\);/s', $js) === 1,
        "transcript search must submit after a 400 ms debounce in {$kind} JavaScript"
    );
    expectTranscriptListUi(
        preg_match('/\$search\.on\(\'keydown\', function \(event\) \{.*?event\.which !== 13.*?event\.preventDefault\(\);\s*submitTranscriptSearch\(\);/s', $js) === 1,
        "transcript search must submit immediately on Enter in {$kind} JavaScript"
    );
    expectTranscriptListUi(
        preg_match('/function submitTranscriptSearch\(\)\s*\{\s*cancelSearchDebounce\(\);\s*\$form\.trigger\(\'submit\'\);\s*\}/s', $js) === 1
            && preg_match('/\$form\.on\(\'submit\', function \(\) \{\s*cancelSearchDebounce\(\);/s', $js) === 1,
        "immediate transcript search submission must cancel pending debounce in {$kind} JavaScript"
    );
    expectTranscriptListUi(
        preg_match('/\$search\.on\(\'search\', function \(\) \{.*?trim\(\) === \'\'\).*?submitTranscriptSearch\(\);/s', $js) === 1,
        "clearing transcript search must still submit immediately in {$kind} JavaScript"
    );
    if ($kind === 'compiled') {
        expectTranscriptListUi(
            !str_contains($js, 'require('),
            'compiled transcript search JavaScript must run standalone'
        );
    }
}

echo "Transcript list UI contract checks passed\n";
