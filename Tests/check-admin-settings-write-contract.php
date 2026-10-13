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
    'saveModelAction' => ['retryErrorsAction', 'AdministrativeSettingsWriter'],
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

$saveActionBody = adminActionBody($controller, 'saveAction', 'saveModelAction');
expectAdminSettingsWrite(
    str_contains($saveActionBody, "logControllerFailure('save_settings', \$exception)")
        && str_contains($saveActionBody, 'settingsSaveErrorMessage($exception)'),
    'The full settings save path must log failures and hide internal exception details'
);

$saveModelBody = adminActionBody($controller, 'saveModelAction', 'retryErrorsAction');
expectAdminSettingsWrite(
    str_contains($saveModelBody, 'new AdministrativeSettingsWriter()')
        && str_contains($saveModelBody, "null,\n                \$values !== []"),
    'Saving a recognition model must notify every worker to refresh PBX-managed state'
);

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
$firstWrite = strpos($saveBody, 'new AdministrativeSettingsWriter()');
expectAdminSettingsWrite(
    $profileBuild !== false
        && $profileValidation !== false
        && $firstWrite !== false
        && $profileBuild < $profileValidation
        && $profileValidation < $firstWrite,
    'The complete processing profile must be validated before any setting is written'
);
expectAdminSettingsWrite(
    str_contains(
        $saveBody,
        'STTSetting::AUDIO_NORMALIZATION => $this->request->getPost(STTSetting::AUDIO_NORMALIZATION, null, false)'
    )
        && str_contains(
            $saveBody,
            'STTSetting::VOICE_ACTIVITY_DETECTION => $this->request->getPost(STTSetting::VOICE_ACTIVITY_DETECTION, null, false)'
        )
        && !str_contains($saveBody, 'hasPost(STTSetting::AUDIO_NORMALIZATION)')
        && !str_contains($saveBody, 'hasPost(STTSetting::VOICE_ACTIVITY_DETECTION)'),
    'Processing checkboxes must preserve their posted false values instead of using field presence'
);
expectAdminSettingsWrite(
    !str_contains($saveBody, 'STTSetting::SELECTED_MODEL')
        && str_contains($saveBody, 'foreach (STTSetting::configuredModelIds() as $modelId)')
        && str_contains($saveBody, 'new AdministrativeSettingsWriter()'),
    'The full settings save path must leave models to the marketplace and check the language against every chosen model'
);

echo "Administrative settings write contract OK\n";
