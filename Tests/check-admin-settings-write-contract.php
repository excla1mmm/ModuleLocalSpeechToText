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
    'retryErrorsAction' => 'generateWorkerApiKeyAction',
    'generateWorkerApiKeyAction' => 'deleteWorkerApiKeyAction',
    'deleteWorkerApiKeyAction' => 'transcriptsAction',
] as $action => $nextAction) {
    expectAdminSettingsWrite(
        str_contains(adminActionBody($controller, $action, $nextAction), 'withModuleWriteLease'),
        "{$action} must retain lifecycle protection because it is not a settings edit"
    );
}

echo "Administrative settings write contract OK\n";
