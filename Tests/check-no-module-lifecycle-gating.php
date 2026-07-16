<?php

declare(strict_types=1);

function expectNoLifecycleGating(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function lifecycleActionBody(string $controller, string $action, string $nextAction): string
{
    preg_match(
        '/public function ' . preg_quote($action, '/') . '\(\): void(?<body>.*?)public function '
            . preg_quote($nextAction, '/') . '\(\): void/s',
        $controller,
        $match
    );

    return (string)($match['body'] ?? '');
}

$root = dirname(__DIR__);
$guardPath = $root . '/Lib/Services/ModuleLifecycleGuard.php';
expectNoLifecycleGating(
    !file_exists($guardPath),
    'Custom ModuleLifecycleGuard must be deleted'
);

$runtimePaths = [
    'Lib/LocalSpeechToTextConf.php',
    'bin/WorkerLocalSpeechToTextJobs.php',
    'App/Controllers/LocalSpeechToTextBaseController.php',
    'App/Controllers/ModuleLocalSpeechToTextController.php',
    'Lib/RestAPI/Jobs/Processor.php',
    'Lib/RestAPI/Workers/Processor.php',
];
$forbiddenRuntimeSymbols = [
    'ModuleLifecycleGuard',
    'acquireWriteLease',
    'releaseWriteLease',
    'withModuleWriteLease',
    'Local speech-to-text module is changing state',
];

foreach ($runtimePaths as $relativePath) {
    $contents = file_get_contents($root . '/' . $relativePath);
    expectNoLifecycleGating($contents !== false, "Cannot read {$relativePath}");
    foreach ($forbiddenRuntimeSymbols as $symbol) {
        expectNoLifecycleGating(
            !str_contains((string)$contents, $symbol),
            "{$relativePath} must not contain lifecycle gating symbol {$symbol}"
        );
    }
}

$scanner = (string)file_get_contents($root . '/bin/WorkerLocalSpeechToTextJobs.php');
expectNoLifecycleGating(
    str_contains($scanner, '$this->createJobsFromCompletedCdr();'),
    'Scanner must retain completed CDR discovery'
);

$config = (string)file_get_contents($root . '/Lib/LocalSpeechToTextConf.php');
expectNoLifecycleGating(str_contains($config, 'onAfterModuleEnable'), 'Module config must retain after-enable refresh');
expectNoLifecycleGating(
    str_contains($config, 'PBXConfModulesProvider::recreateModulesProvider()')
        && str_contains($config, 'Processes::restartAllWorkers(true)'),
    'After-enable must refresh the module provider and Core workers'
);
foreach (['onBeforeModuleEnable', 'onBeforeModuleDisable', 'onAfterModuleDisable'] as $obsoleteHook) {
    expectNoLifecycleGating(
        !str_contains($config, $obsoleteHook),
        "Custom lifecycle hook {$obsoleteHook} must be removed"
    );
}

$controller = (string)file_get_contents($root . '/App/Controllers/ModuleLocalSpeechToTextController.php');
$generateAction = lifecycleActionBody($controller, 'generateWorkerApiKeyAction', 'deleteWorkerApiKeyAction');
$deleteAction = lifecycleActionBody($controller, 'deleteWorkerApiKeyAction', 'transcriptsAction');
expectNoLifecycleGating($generateAction !== '' && $deleteAction !== '', 'Cannot inspect worker API-key actions');
expectNoLifecycleGating(
    strpos($generateAction, 'createCoreWorkerApiKey') < strpos($generateAction, 'bindWorkerApiKey')
        && strpos($generateAction, 'bindWorkerApiKey') < strpos($generateAction, 'commitCoreApiKeyTransaction')
        && str_contains($generateAction, 'removeWorkerApiKeyBinding')
        && str_contains($generateAction, 'rollBackCoreApiKeyTransaction'),
    'Worker API-key creation must preserve bind, commit, compensation, and rollback ordering'
);
expectNoLifecycleGating(
    strpos($deleteAction, 'deleteCoreWorkerApiKey') < strpos($deleteAction, 'commitCoreApiKeyTransaction')
        && strpos($deleteAction, 'commitCoreApiKeyTransaction') < strpos($deleteAction, 'unbindWorkersFromApiKey')
        && strpos($deleteAction, 'unbindWorkersFromApiKey') < strpos($deleteAction, 'removeWorkerApiKeyBinding')
        && strpos($deleteAction, 'removeWorkerApiKeyBinding') < strpos($deleteAction, 'clearCoreApiKeyCache')
        && str_contains($deleteAction, 'rollBackCoreApiKeyTransaction'),
    'Worker API-key deletion must preserve commit, cleanup, cache refresh, and rollback ordering'
);

$setup = (string)file_get_contents($root . '/Setup/PbxExtensionSetup.php');
expectNoLifecycleGating(
    !str_contains($setup, 'ModuleLifecycleGuard'),
    'Setup must not bootstrap lifecycle coordination'
);
foreach ([
    '.module-lifecycle-state.json',
    '.module-lifecycle.lock',
    '.module-lifecycle-transition.lock',
] as $legacyFile) {
    expectNoLifecycleGating(
        str_contains($setup, $legacyFile),
        "Setup must clean obsolete file {$legacyFile}"
    );
}

$packageScript = (string)file_get_contents($root . '/scripts/package-module.sh');
$gitignore = (string)file_get_contents($root . '/.gitignore');
expectNoLifecycleGating(
    !str_contains($packageScript, '.module-lifecycle'),
    'Package script must not retain lifecycle-specific rules'
);
expectNoLifecycleGating(
    !str_contains($gitignore, '.module-lifecycle'),
    'Git ignore rules must not retain lifecycle-specific entries'
);

echo "No module lifecycle gating contract OK\n";
