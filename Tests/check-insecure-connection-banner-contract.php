<?php

declare(strict_types=1);

function expectInsecureBanner(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');
$service = (string)file_get_contents($root . '/Lib/Services/IndexViewService.php');

expectInsecureBanner(
    str_contains($service, '$view->isSecureConnection = $this->request->isSecure();'),
    'IndexViewService must expose the real connection security to the view'
);

$workersTabPosition = strpos($view, 'tab segment{% if activeTab === \'workers\' %}');
$bannerPosition = strpos($view, 'stt-insecure-connection-warning');
$downloadSegmentPosition = strpos($view, 'stt-worker-download');
expectInsecureBanner(
    $workersTabPosition !== false && $bannerPosition !== false && $downloadSegmentPosition !== false
        && $workersTabPosition < $bannerPosition && $bannerPosition < $downloadSegmentPosition,
    'insecure-connection banner must be the first thing shown in the Workers tab'
);

$workersTabEnd = strpos($view, 'data-tab="logs"', $workersTabPosition);
$workersTabEnd = $workersTabEnd === false ? strlen($view) : $workersTabEnd;
$workersTabMarkup = substr($view, $workersTabPosition, $workersTabEnd - $workersTabPosition);

expectInsecureBanner(
    str_contains($workersTabMarkup, '{% if not isSecureConnection %}')
        && str_contains($workersTabMarkup, 'module_local_speech_to_text_InsecureConnectionTitle')
        && str_contains($workersTabMarkup, 'module_local_speech_to_text_InsecureConnectionWarning'),
    'banner must be gated on isSecureConnection and use both translation keys'
);

$messageFiles = glob($root . '/Messages/*.php') ?: [];
expectInsecureBanner($messageFiles !== [], 'No translation catalogs found');
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    expectInsecureBanner(
        isset($messages['module_local_speech_to_text_InsecureConnectionTitle'])
            && trim((string)$messages['module_local_speech_to_text_InsecureConnectionTitle']) !== '',
        basename($messageFile) . ' is missing a non-empty InsecureConnectionTitle'
    );
    expectInsecureBanner(
        isset($messages['module_local_speech_to_text_InsecureConnectionWarning'])
            && trim((string)$messages['module_local_speech_to_text_InsecureConnectionWarning']) !== '',
        basename($messageFile) . ' is missing a non-empty InsecureConnectionWarning'
    );
}

echo "Insecure connection banner contract OK\n";
