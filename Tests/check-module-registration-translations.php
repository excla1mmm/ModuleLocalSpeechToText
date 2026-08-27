<?php

declare(strict_types=1);

$messagesDir = dirname(__DIR__) . '/Messages';
$catalogFiles = glob($messagesDir . '/*.php') ?: [];
sort($catalogFiles, SORT_STRING);
$registrationKeys = [
    'AdditionalMenuItemModuleLocalSpeechToText',
    'BreadcrumbModuleLocalSpeechToText',
    'SubHeaderModuleLocalSpeechToText',
];
$failures = [];

if (count($catalogFiles) !== 29) {
    $failures[] = 'Expected 29 message catalogs, found ' . count($catalogFiles);
}

foreach ($catalogFiles as $catalogFile) {
    $messages = require $catalogFile;
    foreach ($registrationKeys as $key) {
        if (trim((string)($messages[$key] ?? '')) === '') {
            $failures[] = basename($catalogFile) . ' has no module registration translation for ' . $key;
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo count($catalogFiles) . " module registration catalogs verified\n";
