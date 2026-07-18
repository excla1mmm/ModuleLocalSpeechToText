<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;

require_once __DIR__ . '/../Lib/Services/ModuleLifecycleGate.php';

function expectLifecycleGate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeLifecycleDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            removeLifecycleDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}

$temporaryDirectory = sys_get_temp_dir() . '/local-stt-lifecycle-' . bin2hex(random_bytes(8));
try {
    $gate = new ModuleLifecycleGate($temporaryDirectory, bootstrap: true);
    expectLifecycleGate($gate->currentState() === ModuleLifecycleGate::STATE_OPEN, 'new gate must be open');

    $writer = new ModuleLifecycleGate($temporaryDirectory);
    $lease = $writer->acquireWriteLease();
    expectLifecycleGate(is_resource($lease), 'open gate must grant a writer lease');

    $drain = new ModuleLifecycleGate($temporaryDirectory);
    $started = hrtime(true);
    expectLifecycleGate(!$drain->beginDrain(30), 'drain must time out while writer lease is held');
    $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;
    expectLifecycleGate($elapsedMilliseconds >= 20, 'drain timeout returned without waiting');
    expectLifecycleGate($drain->currentState() === ModuleLifecycleGate::STATE_OPEN, 'timed-out drain must reopen gate');

    $writer->releaseWriteLease($lease);
    expectLifecycleGate($drain->beginDrain(250), 'drain must succeed after writer completes');
    expectLifecycleGate($drain->beginDrain(250), 'duplicate Core drain hook must be idempotent');
    expectLifecycleGate(
        $drain->currentState() === ModuleLifecycleGate::STATE_DRAINING,
        'successful drain must publish draining state'
    );
    expectLifecycleGate($writer->acquireWriteLease() === null, 'draining gate must reject new writers');

    $drain->completeDisable();
    expectLifecycleGate(
        $drain->currentState() === ModuleLifecycleGate::STATE_DISABLED,
        'disable completion must persist disabled state'
    );
    expectLifecycleGate($writer->acquireWriteLease() === null, 'disabled gate must reject writers');

    $drain->completeEnable();
    expectLifecycleGate($drain->currentState() === ModuleLifecycleGate::STATE_OPEN, 'enable must reopen gate');
    $lease = $writer->acquireWriteLease();
    expectLifecycleGate(is_resource($lease), 'enabled gate must grant writer lease');
    $writer->releaseWriteLease($lease);

    $unavailable = new ModuleLifecycleGate($temporaryDirectory . '/missing');
    expectLifecycleGate($unavailable->acquireWriteLease() === null, 'uninitialized gate must fail closed');
} finally {
    removeLifecycleDirectory($temporaryDirectory);
}

$scanner = (string)file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
$jobsProcessor = (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/Jobs/Processor.php');
$workersProcessor = (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/Workers/Processor.php');
$config = (string)file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextConf.php');
$adminBase = (string)file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');

expectLifecycleGate(
    str_contains($scanner, 'ModuleLifecycleGate')
        && str_contains($scanner, 'Module lifecycle gate is closed; scanner iteration paused')
        && !str_contains($scanner, 'Worker stopped accepting database work because the module is changing state'),
    'scanner must pause without the legacy exit-loop behavior'
);
expectLifecycleGate(
    str_contains($jobsProcessor, 'httpCode = 503')
        && str_contains($workersProcessor, 'httpCode = 503')
        && str_contains($jobsProcessor, "['retry_after'] = 5")
        && str_contains($workersProcessor, "['retry_after'] = 5"),
    'worker REST processors must return a retryable maintenance response'
);
expectLifecycleGate(
    str_contains($config, 'onBeforeModuleDisable')
        && str_contains($config, 'onAfterModuleDisable')
        && str_contains($config, 'completeEnable'),
    'module lifecycle hooks are not connected'
);
expectLifecycleGate(
    str_contains($adminBase, 'acquireWriteLease') && str_contains($adminBase, 'releaseWriteLease'),
    'admin requests must hold a lifecycle writer lease'
);

echo "Module lifecycle gate behavior OK\n";
