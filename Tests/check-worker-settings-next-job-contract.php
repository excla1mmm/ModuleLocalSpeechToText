<?php

declare(strict_types=1);

function expectNextJobSettings(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$main = (string)file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php');
$restApiFiles = glob(__DIR__ . '/../Lib/RestAPI/**/*.php') ?: [];
$restApi = '';
foreach ($restApiFiles as $file) {
    $restApi .= (string)file_get_contents($file);
}

$methodStart = strpos($main, 'public static function getNextJob');
$methodEnd = $methodStart === false ? false : strpos($main, 'public static function downloadJobFile', $methodStart);
$getNext = $methodStart === false || $methodEnd === false ? '' : substr($main, $methodStart, $methodEnd - $methodStart);
expectNextJobSettings($getNext !== '', 'Cannot inspect getNextJob()');
expectNextJobSettings(
    substr_count($getNext, "'has_settings_changed' => \$hasSettingsChanged") === 2,
    'Both empty and claimed-job success responses must include has_settings_changed'
);
expectNextJobSettings(
    str_contains($getNext, '$worker->hasProcessingSettingsChanged()'),
    'getNextJob() must read the authenticated worker dirty flag'
);
expectNextJobSettings(
    !str_contains($getNext, 'has_settings_changed = 0'),
    'getNextJob() must never clear the processing-settings flag'
);

$forbidden = $main . $restApi;
expectNextJobSettings(!str_contains($forbidden, 'settings_revision'), 'settings_revision is forbidden');
expectNextJobSettings(!str_contains($forbidden, 'applied_settings_revision'), 'applied_settings_revision is forbidden');

echo "Worker settings next-job contract OK\n";
