<?php

declare(strict_types=1);

function expectAdminSettingsWrite(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function adminActionBody(string $controller, string $action, string $nextAction): string
{
    preg_match(
        '/public function ' . preg_quote($action, '/') . '\(\): void(?<body>.*?)public function '
            . preg_quote($nextAction, '/') . '\(\): void/s',
        $controller,
        $match
    );

    return (string)($match['body'] ?? '');
}

$controller = file_get_contents(__DIR__ . '/../App/Controllers/ModuleLocalSpeechToTextController.php');
expectAdminSettingsWrite($controller !== false, 'Cannot read settings controller');

$settingsActions = [
    'saveAction' => ['saveModelAction', 'saveSettingsFromPost'],
    'saveModelAction' => ['addCustomModelAction', 'STTSetting::setValue'],
    'addCustomModelAction' => ['deleteCustomModelAction', 'STTSetting::addCustomModel'],
    'deleteCustomModelAction' => ['retryErrorsAction', 'STTSetting::removeCustomModel'],
];

foreach ($settingsActions as $action => [$nextAction, $expectedWrite]) {
    $body = adminActionBody($controller, $action, $nextAction);
    expectAdminSettingsWrite($body !== '', "Cannot inspect {$action}");
    expectAdminSettingsWrite(
        !str_contains($body, 'withModuleWriteLease'),
        "{$action} must remain writable regardless of module lifecycle state"
    );
    expectAdminSettingsWrite(
        str_contains($body, $expectedWrite),
        "{$action} must retain its settings write"
    );
}

foreach ([
    'retryErrorsAction' => ['generateWorkerApiKeyAction', 'STTJob::requeueErrors'],
    'generateWorkerApiKeyAction' => ['deleteWorkerApiKeyAction', 'createCoreWorkerApiKey'],
    'deleteWorkerApiKeyAction' => ['transcriptsAction', 'deleteCoreWorkerApiKey'],
] as $action => [$nextAction, $expectedWrite]) {
    $body = adminActionBody($controller, $action, $nextAction);
    expectAdminSettingsWrite(
        !str_contains($body, 'withModuleWriteLease'),
        "{$action} must remain writable without lifecycle gating"
    );
    expectAdminSettingsWrite(
        str_contains($body, $expectedWrite),
        "{$action} must retain its administrative write"
    );
}

$baseController = file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');
expectAdminSettingsWrite($baseController !== false, 'Cannot read base settings controller');
$saveStart = strpos($baseController, 'protected function saveSettingsFromPost(): void');
$saveEnd = strpos($baseController, 'protected function countJobsByStatus', $saveStart ?: 0);
$saveBody = $saveStart === false || $saveEnd === false
    ? ''
    : substr($baseController, $saveStart, $saveEnd - $saveStart);
$profileBuild = strpos($saveBody, '$processingInput = [');
$profileValidation = strpos($saveBody, 'WorkerProcessingSettingsService::normalize($processingInput)');
$firstWrite = strpos($saveBody, 'STTSetting::setValue(');
expectAdminSettingsWrite(
    $profileBuild !== false
        && $profileValidation !== false
        && $firstWrite !== false
        && $profileBuild < $profileValidation
        && $profileValidation < $firstWrite,
    'The complete processing profile must be validated before any setting is written'
);

echo "Administrative settings write contract OK\n";
