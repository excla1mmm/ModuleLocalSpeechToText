<?php

declare(strict_types=1);

function expectStructuredLogUi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$view = file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');
$sourceJs = file_get_contents($root . '/public/assets/js/src/module-local-speech-to-text.js');
$deployedJs = file_get_contents($root . '/public/assets/js/module-local-speech-to-text.js');
$css = file_get_contents($root . '/public/assets/css/module-local-speech-to-text.css');
$controller = file_get_contents($root . '/App/Controllers/ModuleLocalSpeechToTextController.php');

expectStructuredLogUi($view !== false && $sourceJs !== false && $deployedJs !== false && $css !== false && $controller !== false, 'Cannot read log UI sources');

foreach ([
    'module_local_speech_to_text_LogsTitle',
    'module_local_speech_to_text_LogsDescription',
    'stt-log-level-filter',
    'stt-log-component-filter',
    'stt-log-search',
    'stt-log-results',
    'stt-log-state',
    'stt-log-updated-at',
    'data-period="0"',
] as $needle) {
    expectStructuredLogUi(str_contains($view, $needle), 'structured log view control/state is missing: ' . $needle);
}
foreach (['source' => $sourceJs, 'deployed' => $deployedJs] as $kind => $javascript) {
    foreach (['entry.severity', 'entry.pid'] as $syslogField) {
        expectStructuredLogUi(
            substr_count($javascript, $syslogField) >= 2,
            "{$kind} syslog field must participate in search and technical details: {$syslogField}"
        );
    }
}
foreach (['data-period="3600"', 'data-period="10800"', 'data-period="43200"', 'data-period="86400"'] as $period) {
    expectStructuredLogUi(str_contains($view, $period), 'supported period button is missing: ' . $period);
}
foreach (['604800', '1209600', 'time-slider-container', '<pre id="stt-module-log-content"', 'fullscreen-toggle-btn'] as $removed) {
    expectStructuredLogUi(!str_contains($view, $removed), 'legacy raw-view UI remains: ' . $removed);
}

foreach (['source' => $sourceJs, 'deployed' => $deployedJs] as $kind => $javascript) {
    foreach ([
        'requestGeneration',
        'requestId !== requestGeneration',
        'filterEntries',
        'renderEntries',
        'renderState',
        'sourceStatus',
        'malformedCount',
        'truncated',
        '.text(',
    ] as $needle) {
        expectStructuredLogUi(str_contains($javascript, $needle), "{$kind} structured log client behavior is missing: {$needle}");
    }
    expectStructuredLogUi(!str_contains($javascript, '$content.html('), "{$kind} log payload must never be inserted as HTML");
}
expectStructuredLogUi(str_contains($css, '.stt-log-entry'), 'structured event card styles are missing');
expectStructuredLogUi(str_contains($css, '.stt-log-state'), 'explicit state styles are missing');

expectStructuredLogUi(
    str_contains($controller, '(new ModuleLogReader())->recentForPeriod($period)'),
    'logs action must use the structured reader period contract'
);
expectStructuredLogUi(
    str_contains($controller, '[0, 3600, 10800, 43200, 86400]'),
    'logs action must accept only all/1h/3h/12h/1d periods'
);
expectStructuredLogUi(!str_contains($controller, 'Module logs requested:'), 'successful log retrieval must remain silent');
foreach (['entries', 'logText', 'lineCount', 'fileCount', 'generatedAt', 'period', 'availableFrom', 'truncated', 'sourceStatus', 'malformedCount'] as $field) {
    expectStructuredLogUi(str_contains($controller, "['{$field}']"), 'logs response field is missing: ' . $field);
}

echo "Structured log UI contract OK\n";
