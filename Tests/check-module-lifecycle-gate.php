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
$drainProcessId = null;
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

    if (function_exists('pcntl_fork') && function_exists('posix_kill')) {
        $goPath = $temporaryDirectory . '/start-child-drain';
        $acquiredPath = $temporaryDirectory . '/child-drain-acquired';
        $drainProcessId = pcntl_fork();
        expectLifecycleGate($drainProcessId >= 0, 'unable to fork lifecycle drain test');
        if ($drainProcessId === 0) {
            while (!file_exists($goPath)) {
                usleep(10_000);
            }
            $childDrain = new ModuleLifecycleGate($temporaryDirectory);
            if (!$childDrain->beginDrain(5000, 1000)) {
                exit(2);
            }
            file_put_contents($acquiredPath, 'ready');
            sleep(30);
            exit(0);
        }

        file_put_contents($goPath, 'go');
        $drainingDeadline = microtime(true) + 2;
        while ($writer->currentState() !== ModuleLifecycleGate::STATE_DRAINING && microtime(true) < $drainingDeadline) {
            usleep(10_000);
        }
        expectLifecycleGate(
            $writer->currentState() === ModuleLifecycleGate::STATE_DRAINING,
            'child must publish draining state before waiting for writers'
        );
        expectLifecycleGate(
            $writer->acquireWriteLease() === null,
            'drain must reject new queue claims'
        );
        $completionLease = $writer->acquireWriteLease(allowDuringDrain: true);
        expectLifecycleGate(
            is_resource($completionLease),
            'drain grace period must accept completion traffic for an existing lease'
        );
        $writer->releaseWriteLease($completionLease);

        $acquiredDeadline = microtime(true) + 2;
        while (!file_exists($acquiredPath) && microtime(true) < $acquiredDeadline) {
            usleep(10_000);
        }
        expectLifecycleGate(file_exists($acquiredPath), 'child drain did not acquire its exclusive lease');
        posix_kill($drainProcessId, SIGKILL);
        pcntl_waitpid($drainProcessId, $childStatus);
        $drainProcessId = null;
        sleep(2);
        expectLifecycleGate(
            (new ModuleLifecycleGate($temporaryDirectory))->currentState() === ModuleLifecycleGate::STATE_OPEN,
            'dead drain owner must be recovered after its exclusive lock disappears'
        );

        file_put_contents(
            $temporaryDirectory . '/.module-lifecycle-state-v2.json',
            json_encode([
                'state' => ModuleLifecycleGate::STATE_DRAINING,
                'owner_pid' => getmypid(),
                'owner_token' => bin2hex(random_bytes(16)),
                'updated_at' => time() - 600,
            ], JSON_THROW_ON_ERROR)
        );
        expectLifecycleGate(
            (new ModuleLifecycleGate($temporaryDirectory))->currentState() === ModuleLifecycleGate::STATE_OPEN,
            'an old unlocked drain must recover even when its PID has been reused'
        );
    }

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
    if (is_int($drainProcessId) && $drainProcessId > 0 && function_exists('posix_kill')) {
        posix_kill($drainProcessId, SIGKILL);
        pcntl_waitpid($drainProcessId, $childStatus);
    }
    removeLifecycleDirectory($temporaryDirectory);
}

$scanner = (string)file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
$gatedProcessors = [
    'Jobs' => (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/Jobs/Processor.php'),
    'Workers' => (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/Workers/Processor.php'),
    'WorkerProcessingSettings' => (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/WorkerProcessingSettings/Processor.php'),
    'JobLeases' => (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/JobLeases/Processor.php'),
    'JobRecordings' => (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/JobRecordings/Processor.php'),
    'JobResults' => (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/JobResults/Processor.php'),
    'JobFailures' => (string)file_get_contents(__DIR__ . '/../Lib/RestAPI/JobFailures/Processor.php'),
];
$config = (string)file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextConf.php');
$adminBase = (string)file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');

expectLifecycleGate(
    str_contains($scanner, 'ModuleLifecycleGate')
        && preg_match(
            '/if \(\$writeLease === null\) \{\s*sleep\(self::SCAN_INTERVAL_SECONDS\);\s*continue;\s*\}/',
            $scanner
        ) === 1
        && !str_contains($scanner, 'Module lifecycle gate is closed; scanner iteration paused')
        && !str_contains($scanner, 'Worker stopped accepting database work because the module is changing state'),
    'scanner must pause silently without the legacy exit-loop behavior'
);
foreach ($gatedProcessors as $resource => $processor) {
    if ($resource === 'JobFailures') {
        expectLifecycleGate(
            str_contains($processor, 'ResultProcessor::dispatch'),
            'JobFailures must delegate its lifecycle-gated maintenance behavior to JobResults'
        );
        continue;
    }
    expectLifecycleGate(
        str_contains($processor, 'ModuleLifecycleGate')
            && str_contains($processor, 'acquireWriteLease')
            && str_contains($processor, '503')
            && str_contains($processor, 'retry_after'),
        "{$resource} must return a retryable maintenance response after acquiring its lifecycle gate"
    );
}
expectLifecycleGate(
    str_contains($gatedProcessors['JobLeases'], 'allowDuringDrain: $action !== JobLeaseAction::CREATE'),
    'new lease claims must stop during drain while existing lease completion actions retain a bounded grace period'
);
expectLifecycleGate(
    str_contains($config, 'onBeforeModuleDisable')
        && str_contains($config, 'onAfterModuleDisable')
        && str_contains($config, 'completeEnable'),
    'module lifecycle hooks are not connected'
);
expectLifecycleGate(
    !str_contains($adminBase, 'ModuleLifecycleGate')
        && !str_contains($adminBase, 'acquireWriteLease')
        && !str_contains($adminBase, 'releaseWriteLease'),
    'admin requests must remain available while the module lifecycle gate is closed'
);

echo "Module lifecycle gate behavior OK\n";
