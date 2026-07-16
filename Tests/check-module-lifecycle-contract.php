<?php

declare(strict_types=1);

function expectLifecycleContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$conf = file_get_contents($root . '/Lib/LocalSpeechToTextConf.php');
$resultModel = file_get_contents($root . '/Models/STTResult.php');
$jobsProcessor = file_get_contents($root . '/Lib/RestAPI/Jobs/Processor.php');
$workersProcessor = file_get_contents($root . '/Lib/RestAPI/Workers/Processor.php');
$scanner = file_get_contents($root . '/bin/WorkerLocalSpeechToTextJobs.php');
$baseController = file_get_contents($root . '/App/Controllers/LocalSpeechToTextBaseController.php');
$controller = file_get_contents($root . '/App/Controllers/ModuleLocalSpeechToTextController.php');
$workerApiKeys = file_get_contents($root . '/Lib/Services/WorkerApiKeyService.php');
$guard = file_get_contents($root . '/Lib/Services/ModuleLifecycleGuard.php');
$packageScript = file_get_contents($root . '/scripts/package-module.sh');
$gitignore = file_get_contents($root . '/.gitignore');
$setup = file_get_contents($root . '/Setup/PbxExtensionSetup.php');

foreach ([
    'LocalSpeechToTextConf.php' => $conf,
    'STTResult.php' => $resultModel,
    'Jobs/Processor.php' => $jobsProcessor,
    'Workers/Processor.php' => $workersProcessor,
    'WorkerLocalSpeechToTextJobs.php' => $scanner,
    'LocalSpeechToTextBaseController.php' => $baseController,
    'ModuleLocalSpeechToTextController.php' => $controller,
    'WorkerApiKeyService.php' => $workerApiKeys,
    'ModuleLifecycleGuard.php' => $guard,
    'scripts/package-module.sh' => $packageScript,
    '.gitignore' => $gitignore,
    'Setup/PbxExtensionSetup.php' => $setup,
] as $name => $contents) {
    expectLifecycleContract($contents !== false, "Cannot read {$name}");
}

expectLifecycleContract(
    str_contains((string)$resultModel, "'action' => Relation::NO_ACTION")
        && !str_contains((string)$resultModel, 'Relation::ACTION_CASCADE'),
    'Deleting an STTResult probe must never cascade from the child result to its parent job'
);
expectLifecycleContract(
    str_contains((string)$packageScript, '.module-lifecycle')
        && str_contains((string)$packageScript, 'module.db-*')
        && str_contains((string)$gitignore, 'db/.module-lifecycle'),
    'Runtime SQLite data, lifecycle markers, and locks must be ignored by git and excluded from module packages'
);
expectLifecycleContract(
    str_contains((string)$guard, 'MUTEX_FILE_NAME')
        && str_contains((string)$guard, "'previous_state'")
        && str_contains((string)$guard, "'owner_token'")
        && str_contains((string)$guard, "'boot_id'")
        && str_contains((string)$guard, "openRegularFile(\$this->lockPath")
        && str_contains((string)$guard, 'lstat($path)')
        && str_contains((string)$guard, "'/proc/self/fd/0'")
        && str_contains((string)$guard, "systemCommand('chmod')")
        && !str_contains((string)$guard, '0666')
        && str_contains((string)$guard, 'authoritativeState')
        && str_contains((string)$guard, 'bootstrap'),
    'Lifecycle guard must serialize transitions, use unique ownership, restore failed enable state, scope markers to a boot, and support read-only lock access'
);
expectLifecycleContract(
    str_contains((string)$conf, 'reconcileOwnedTransition')
        && strpos((string)$setup, 'new ModuleLifecycleGuard') < strpos((string)$setup, 'parent::installDB()'),
    'Interrupted hooks must reconcile with Core state, and setup must fail-close before database migrations'
);

foreach ([
    'onBeforeModuleEnable',
    'onAfterModuleEnable',
    'onBeforeModuleDisable',
    'onAfterModuleDisable',
] as $hook) {
    expectLifecycleContract(str_contains((string)$conf, "function {$hook}"), "Module config must implement {$hook}");
}
expectLifecycleContract(
    str_contains((string)$conf, 'requestDisable')
        && str_contains((string)$conf, 'requestEnable')
        && str_contains((string)$conf, 'awaitQuiescence')
        && str_contains((string)$conf, 'abortOwnedTransition'),
    'Lifecycle hooks must gate, drain, and safely abort incomplete transitions'
);
expectLifecycleContract(
    substr_count((string)$conf, '$this->refreshCoreRuntime(') >= 2
        && str_contains((string)$conf, 'PBXConfModulesProvider::recreateModulesProvider()')
        && str_contains((string)$conf, 'Processes::restartAllWorkers(true)'),
    'Both enable and disable completion must refresh providers and worker routing'
);

foreach ([$jobsProcessor, $workersProcessor] as $processor) {
    expectLifecycleContract(
        str_contains((string)$processor, 'acquireWriteLease')
            && str_contains((string)$processor, 'releaseWriteLease')
            && str_contains((string)$processor, 'module_unavailable')
            && str_contains((string)$processor, '503'),
        'Every mutating worker REST processor must hold a lifecycle lease and reject writes with HTTP 503'
    );
}

expectLifecycleContract(
    str_contains((string)$scanner, 'acquireWriteLease')
        && str_contains((string)$scanner, 'releaseWriteLease')
        && str_contains((string)$scanner, 'finally'),
    'The PBX scanner must hold and always release one lifecycle lease per scan iteration'
);
expectLifecycleContract(
    str_contains((string)$baseController, 'withModuleWriteLease')
        && substr_count((string)$controller, 'withModuleWriteLease') === 3,
    'Queue retry and worker credential writes must keep the lifecycle lease while settings edits remain independent'
);
expectLifecycleContract(
    !str_contains((string)$controller, 'STTWorker::markStaleOffline();')
        && !str_contains((string)$workerApiKeys, 'STTSetting::setWorkerApiKeyIds($existingIds);'),
    'The read-only dashboard must not perform opportunistic module-database cleanup'
);
expectLifecycleContract(
    str_contains((string)$workerApiKeys, 'createCoreWorkerApiKey')
        && str_contains((string)$workerApiKeys, 'bindWorkerApiKey')
        && str_contains((string)$workerApiKeys, 'deleteCoreWorkerApiKey')
        && str_contains((string)$workerApiKeys, 'beginCoreApiKeyTransaction')
        && str_contains((string)$workerApiKeys, 'commitCoreApiKeyTransaction')
        && str_contains((string)$workerApiKeys, 'rollBackCoreApiKeyTransaction'),
    'Core API-key writes and module API-key bindings must be separate operations'
);

preg_match(
    '/public function generateWorkerApiKeyAction\(\): void(?<body>.*?)public function deleteWorkerApiKeyAction/s',
    (string)$controller,
    $generateAction
);
preg_match(
    '/public function deleteWorkerApiKeyAction\(\): void(?<body>.*?)public function transcriptsAction/s',
    (string)$controller,
    $deleteAction
);
expectLifecycleContract(isset($generateAction['body'], $deleteAction['body']), 'Cannot inspect worker API-key actions');
expectLifecycleContract(
    strpos($generateAction['body'], 'createCoreWorkerApiKey') < strpos($generateAction['body'], 'withModuleWriteLease')
        && strpos($generateAction['body'], 'bindWorkerApiKey') > strpos($generateAction['body'], 'withModuleWriteLease')
        && strpos($deleteAction['body'], 'deleteCoreWorkerApiKey') < strpos($deleteAction['body'], 'withModuleWriteLease')
        && strpos($deleteAction['body'], 'unbindWorkersFromApiKey') < strpos($deleteAction['body'], 'removeWorkerApiKeyBinding')
        && strpos($deleteAction['body'], 'removeWorkerApiKeyBinding') < strpos($deleteAction['body'], 'clearCoreApiKeyCache'),
    'No lifecycle lease may be held while waiting for a Core database API-key write'
);

echo "Module lifecycle contract OK\n";
