<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGuard;

require_once __DIR__ . '/../Lib/Services/ModuleLifecycleGuard.php';

function expectLifecycleGuard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testOwnerPid = getmypid();
$runtimeDirectory = sys_get_temp_dir() . '/module-local-stt-lifecycle-' . $testOwnerPid . '-' . bin2hex(random_bytes(4));
if (!mkdir($runtimeDirectory, 0700, true) && !is_dir($runtimeDirectory)) {
    throw new RuntimeException('Cannot create lifecycle test directory');
}

register_shutdown_function(static function () use ($runtimeDirectory, $testOwnerPid): void {
    if (getmypid() !== $testOwnerPid) {
        return;
    }
    if (is_dir($runtimeDirectory)) {
        foreach (new DirectoryIterator($runtimeDirectory) as $file) {
            if (!$file->isDot() && $file->isFile()) {
                unlink($file->getPathname());
            }
        }
        rmdir($runtimeDirectory);
    }
});

$bootId = 'lifecycle-test-boot';
$bootstrap = new ModuleLifecycleGuard($runtimeDirectory, $bootId, bootstrap: true);
foreach ([
    ModuleLifecycleGuard::LOCK_FILE_NAME,
    ModuleLifecycleGuard::MUTEX_FILE_NAME,
    ModuleLifecycleGuard::STATE_FILE_NAME,
] as $coordinationFile) {
    $coordinationPath = $runtimeDirectory . '/' . $coordinationFile;
    clearstatcache(true, $coordinationPath);
    $stat = lstat($coordinationPath);
    expectLifecycleGuard($stat !== false, "Cannot stat {$coordinationFile}");
    expectLifecycleGuard(!is_link($coordinationPath), "{$coordinationFile} must not be a symlink");
    expectLifecycleGuard(
        (($stat['mode'] ?? 0) & 0170000) === 0100000,
        "{$coordinationFile} must be a regular file"
    );
    expectLifecycleGuard(
        (($stat['mode'] ?? 0) & 0777) === 0644,
        "{$coordinationFile} must use the Core-compatible minimal 0644 mode"
    );
}

$symlinkVictim = $runtimeDirectory . '/symlink-victim';
file_put_contents($symlinkVictim, 'unchanged', LOCK_EX);
foreach ([
    ModuleLifecycleGuard::LOCK_FILE_NAME,
    ModuleLifecycleGuard::MUTEX_FILE_NAME,
    ModuleLifecycleGuard::STATE_FILE_NAME,
] as $unsafeName) {
    $unsafeDirectory = $runtimeDirectory . '/unsafe-' . str_replace('.', '-', $unsafeName);
    mkdir($unsafeDirectory, 0700);
    if ($unsafeName === ModuleLifecycleGuard::STATE_FILE_NAME) {
        file_put_contents($unsafeDirectory . '/' . ModuleLifecycleGuard::LOCK_FILE_NAME, '', LOCK_EX);
        file_put_contents($unsafeDirectory . '/' . ModuleLifecycleGuard::MUTEX_FILE_NAME, '', LOCK_EX);
    }
    symlink($symlinkVictim, $unsafeDirectory . '/' . $unsafeName);
    $rejectedUnsafePath = false;
    try {
        new ModuleLifecycleGuard($unsafeDirectory, $bootId, bootstrap: true);
    } catch (RuntimeException) {
        $rejectedUnsafePath = true;
    }
    expectLifecycleGuard($rejectedUnsafePath, "Lifecycle bootstrap must reject symlinked {$unsafeName}");
    expectLifecycleGuard(file_get_contents($symlinkVictim) === 'unchanged', 'Symlink victim must not be modified');
    foreach (new DirectoryIterator($unsafeDirectory) as $unsafeFile) {
        if (!$unsafeFile->isDot()) {
            unlink($unsafeFile->getPathname());
        }
    }
    rmdir($unsafeDirectory);
}
expectLifecycleGuard(
    $bootstrap->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
    'Lifecycle bootstrap must remain blocked through an install or update replacement window'
);
expectLifecycleGuard($bootstrap->requestEnable(), 'Test runtime must be explicitly enabled after bootstrap');
expectLifecycleGuard($bootstrap->awaitQuiescence(500), 'Bootstrap enable must drain');
$bootstrap->completeEnable();
$writer = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
$writerLease = $writer->acquireWriteLease();
expectLifecycleGuard(is_resource($writerLease), 'An enabled module must grant a shared write lease');

$lifecycle = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
expectLifecycleGuard($lifecycle->requestDisable(), 'Disable transition must be created');
expectLifecycleGuard(
    !$lifecycle->awaitQuiescence(75),
    'Exclusive lifecycle lease must time out while an in-flight writer is active'
);
$lifecycle->abortOwnedTransition();
expectLifecycleGuard(
    $lifecycle->currentState() === ModuleLifecycleGuard::STATE_OPEN,
    'A failed owned transition must restore the enabled state'
);

$writer->releaseWriteLease($writerLease);
expectLifecycleGuard($lifecycle->requestDisable(), 'Disable transition must be retryable');
expectLifecycleGuard($lifecycle->awaitQuiescence(500), 'Disable must drain after the writer releases its lease');
expectLifecycleGuard($lifecycle->requestDisable(), 'Repeated before-disable calls must be idempotent');
expectLifecycleGuard($lifecycle->awaitQuiescence(50), 'Repeated drain calls must keep the exclusive lease');

$blockedWriter = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
expectLifecycleGuard(
    $blockedWriter->acquireWriteLease() === null,
    'New writers must be rejected after a disable transition starts'
);

$lifecycle->completeDisable();
expectLifecycleGuard(
    $blockedWriter->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
    'Successful disable must persist state across guard instances'
);
expectLifecycleGuard(
    $blockedWriter->acquireWriteLease() === null,
    'A persistent disabled marker must reject stale API writers'
);

$enable = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
expectLifecycleGuard($enable->requestEnable(), 'Enable transition must replace persistent disabled state');
expectLifecycleGuard($enable->awaitQuiescence(500), 'Enable transition must obtain an exclusive lease');
$competingEnable = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
expectLifecycleGuard(
    !$competingEnable->requestEnable(),
    'A separate lifecycle request must not overwrite the active transition'
);
$competingEnable->abortOwnedTransition();
expectLifecycleGuard(
    $enable->currentState() === ModuleLifecycleGuard::STATE_TRANSITION,
    'A rejected lifecycle request must not abort another guard owned by the same process'
);
$enable->abortOwnedTransition();
expectLifecycleGuard(
    $enable->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
    'A failed enable must restore the previous persistent disabled state'
);
expectLifecycleGuard($enable->requestEnable(), 'Enable transition must be retryable after abort');
expectLifecycleGuard($enable->awaitQuiescence(500), 'Retried enable must obtain an exclusive lease');
expectLifecycleGuard($enable->requestEnable(), 'Repeated before-enable calls must be idempotent');
$enable->completeEnable();
expectLifecycleGuard(
    $enable->currentState() === ModuleLifecycleGuard::STATE_OPEN,
    'Successful enable must clear lifecycle state'
);
$enabledLease = $enable->acquireWriteLease();
expectLifecycleGuard(is_resource($enabledLease), 'Writers must resume after successful enable');
$enable->releaseWriteLease($enabledLease);

// Core invokes the enable hooks only while its authoritative module state is
// disabled.  A missing marker (first boot or an upgraded installation) must
// therefore still roll back a failed enable to the disabled state.
$firstEnable = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
expectLifecycleGuard($firstEnable->requestEnable(), 'First-boot enable transition must start without a marker');
expectLifecycleGuard($firstEnable->awaitQuiescence(500), 'First-boot enable transition must drain');
$firstEnable->abortOwnedTransition();
expectLifecycleGuard(
    $firstEnable->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
    'A failed first-boot enable must remain disabled even when no marker existed before the request'
);
expectLifecycleGuard($firstEnable->requestEnable(), 'First-boot enable must remain retryable after abort');
expectLifecycleGuard($firstEnable->awaitQuiescence(500), 'Retried first-boot enable must drain');
$firstEnable->completeEnable();

$statePath = $runtimeDirectory . '/' . ModuleLifecycleGuard::STATE_FILE_NAME;
$staleState = json_encode([
    'state' => ModuleLifecycleGuard::STATE_TRANSITION,
    'target' => ModuleLifecycleGuard::STATE_DISABLED,
    'owner_pid' => 999999,
    'owner_token' => 'dead-owner-token',
    'started_at' => time() - 3600,
    'previous_state' => ModuleLifecycleGuard::STATE_OPEN,
    'boot_id' => $bootId,
], JSON_THROW_ON_ERROR);
file_put_contents($statePath, $staleState, LOCK_EX);
expectLifecycleGuard(
    (new ModuleLifecycleGuard(
        $runtimeDirectory,
        $bootId,
        static fn (): string => ModuleLifecycleGuard::STATE_OPEN
    ))->currentState() === ModuleLifecycleGuard::STATE_OPEN,
    'A transition whose owner has died must not block the module forever'
);
expectLifecycleGuard(!is_file($statePath), 'A stale transition marker must be removed');

$postCommitDisable = json_encode([
    'state' => ModuleLifecycleGuard::STATE_TRANSITION,
    'target' => ModuleLifecycleGuard::STATE_DISABLED,
    'owner_pid' => 999999,
    'owner_token' => 'dead-disable-owner',
    'started_at' => time() - 3600,
    'previous_state' => ModuleLifecycleGuard::STATE_OPEN,
    'boot_id' => $bootId,
], JSON_THROW_ON_ERROR);
file_put_contents($statePath, $postCommitDisable, LOCK_EX);
expectLifecycleGuard(
    (new ModuleLifecycleGuard(
        $runtimeDirectory,
        $bootId,
        static fn (): string => ModuleLifecycleGuard::STATE_DISABLED
    ))->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
    'An orphaned disable after the Core commit must reconcile to disabled'
);

$postCommitEnable = json_encode([
    'state' => ModuleLifecycleGuard::STATE_TRANSITION,
    'target' => 'enabled',
    'owner_pid' => 999999,
    'owner_token' => 'dead-enable-owner',
    'started_at' => time() - 3600,
    'previous_state' => ModuleLifecycleGuard::STATE_DISABLED,
    'boot_id' => $bootId,
], JSON_THROW_ON_ERROR);
file_put_contents($statePath, $postCommitEnable, LOCK_EX);
expectLifecycleGuard(
    (new ModuleLifecycleGuard(
        $runtimeDirectory,
        $bootId,
        static fn (): string => ModuleLifecycleGuard::STATE_OPEN
    ))->currentState() === ModuleLifecycleGuard::STATE_OPEN,
    'An orphaned enable after the Core commit must reconcile to open'
);
expectLifecycleGuard(!is_file($statePath), 'Post-commit enable reconciliation must clear the marker');

foreach (['{corrupt lifecycle state', 'null', '{}'] as $invalidMarker) {
    file_put_contents($statePath, $invalidMarker, LOCK_EX);
    $invalidState = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
    expectLifecycleGuard(
        $invalidState->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
        'An unreadable or schema-invalid lifecycle marker must fail closed'
    );
    expectLifecycleGuard(
        $invalidState->acquireWriteLease() === null,
        'An invalid lifecycle marker must reject stale runtime writers'
    );
    expectLifecycleGuard($invalidState->requestEnable(), 'An explicit enable must recover an invalid marker');
    expectLifecycleGuard($invalidState->awaitQuiescence(500), 'Invalid-marker recovery must drain writers');
    $invalidState->completeEnable();
    expectLifecycleGuard(
        $invalidState->currentState() === ModuleLifecycleGuard::STATE_OPEN,
        'A successful explicit enable must clear an invalid marker'
    );
}

$foreignBootState = json_encode([
    'state' => ModuleLifecycleGuard::STATE_DISABLED,
    'target' => ModuleLifecycleGuard::STATE_DISABLED,
    'previous_state' => ModuleLifecycleGuard::STATE_DISABLED,
    'owner_pid' => 0,
    'owner_token' => '',
    'started_at' => time(),
    'boot_id' => 'previous-boot',
], JSON_THROW_ON_ERROR);
file_put_contents($statePath, $foreignBootState, LOCK_EX);
expectLifecycleGuard(
    (new ModuleLifecycleGuard($runtimeDirectory, $bootId))->currentState() === ModuleLifecycleGuard::STATE_OPEN,
    'A marker restored from another system boot must not block current runtime writers'
);

$removedRuntimeDirectory = $runtimeDirectory . '/removed-module-db';
mkdir($removedRuntimeDirectory, 0700, true);
new ModuleLifecycleGuard($removedRuntimeDirectory, $bootId, bootstrap: true);
unlink($removedRuntimeDirectory . '/' . ModuleLifecycleGuard::STATE_FILE_NAME);
unlink($removedRuntimeDirectory . '/' . ModuleLifecycleGuard::LOCK_FILE_NAME);
unlink($removedRuntimeDirectory . '/' . ModuleLifecycleGuard::MUTEX_FILE_NAME);
rmdir($removedRuntimeDirectory);
$staleRouteGuard = new ModuleLifecycleGuard($removedRuntimeDirectory, $bootId);
expectLifecycleGuard(
    $staleRouteGuard->currentState() === ModuleLifecycleGuard::STATE_DISABLED,
    'A stale route must fail closed after the module runtime directory is removed'
);
expectLifecycleGuard(
    $staleRouteGuard->acquireWriteLease() === null,
    'A stale route must not acquire a lease after uninstall'
);
expectLifecycleGuard(
    !is_dir($removedRuntimeDirectory),
    'A stale route must never recreate a removed module runtime directory'
);

if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid') && extension_loaded('pdo_sqlite')) {
    $readyPath = $runtimeDirectory . '/writer-ready';
    $releasePath = $runtimeDirectory . '/release-writer';
    $sqlitePath = $runtimeDirectory . '/lifecycle.sqlite';
    $setupConnection = new PDO('sqlite:' . $sqlitePath);
    $setupConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $setupConnection->exec('CREATE TABLE lifecycle_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $setupConnection->exec("INSERT INTO lifecycle_probe (id, value) VALUES (1, 'initial')");
    $setupConnection = null;

    $childPid = pcntl_fork();
    if ($childPid === -1) {
        throw new RuntimeException('Cannot fork lifecycle guard concurrency test');
    }
    if ($childPid === 0) {
        $childGuard = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
        $childLease = $childGuard->acquireWriteLease();
        if (!is_resource($childLease)) {
            exit(2);
        }
        try {
            $writerConnection = new PDO('sqlite:' . $sqlitePath);
            $writerConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $writerConnection->setAttribute(PDO::ATTR_TIMEOUT, 0);
            $writerConnection->beginTransaction();
            $writerConnection->exec("UPDATE lifecycle_probe SET value = 'writer' WHERE id = 1");
            file_put_contents($readyPath, 'ready', LOCK_EX);
            $releaseDeadline = hrtime(true) + 2_000_000_000;
            while (!is_file($releasePath) && hrtime(true) < $releaseDeadline) {
                usleep(10_000);
            }
            if (!is_file($releasePath)) {
                throw new RuntimeException('Parent did not release forked lifecycle writer');
            }
            $writerConnection->commit();
            $childGuard->releaseWriteLease($childLease);
            exit(0);
        } catch (Throwable) {
            if (isset($writerConnection) && $writerConnection->inTransaction()) {
                $writerConnection->rollBack();
            }
            $childGuard->releaseWriteLease($childLease);
            exit(3);
        }
    }

    $readyDeadline = hrtime(true) + 1_000_000_000;
    while (!is_file($readyPath) && hrtime(true) < $readyDeadline) {
        usleep(10_000);
    }
    expectLifecycleGuard(is_file($readyPath), 'Forked writer did not acquire its shared lease');

    // Core begins a deferred module transaction before invoking the before-hook.
    // It is safe as long as no module SELECT/write happens until the lifecycle
    // guard has drained the active writer.
    $probeConnection = new PDO('sqlite:' . $sqlitePath);
    $probeConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $probeConnection->setAttribute(PDO::ATTR_TIMEOUT, 0);
    $probeConnection->beginTransaction();

    $crossProcess = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
    expectLifecycleGuard($crossProcess->requestDisable(), 'Cross-process disable transition must start');
    expectLifecycleGuard(
        !$crossProcess->awaitQuiescence(0),
        'Exclusive lifecycle lease must remain blocked while the forked SQLite writer is active'
    );
    file_put_contents($releasePath, 'release', LOCK_EX);
    expectLifecycleGuard($crossProcess->awaitQuiescence(2000), 'Lifecycle must wait for a writer in another process');
    expectLifecycleGuard(
        $probeConnection->query('SELECT value FROM lifecycle_probe WHERE id = 1')->fetchColumn() === 'writer',
        'Lifecycle probe must observe the committed writer after quiescence'
    );
    $probeConnection->exec("UPDATE lifecycle_probe SET value = 'probe' WHERE id = 1");
    $probeConnection->rollBack();
    $crossProcess->abortOwnedTransition();

    pcntl_waitpid($childPid, $childStatus);
    expectLifecycleGuard(pcntl_wexitstatus($childStatus) === 0, 'Forked lifecycle writer failed');

    // Reproduce the two-database wait graph used by API-key administration.
    // The request must obtain/mutate the Core database before taking the
    // module lease. Therefore a Core lifecycle transaction can never wait for
    // a lease held by a request that is itself waiting for the Core database.
    $mainSqlitePath = $runtimeDirectory . '/main.sqlite';
    $mainSetup = new PDO('sqlite:' . $mainSqlitePath);
    $mainSetup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mainSetup->exec('CREATE TABLE api_keys (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $mainSetup->exec("INSERT INTO api_keys (id, value) VALUES (1, 'initial')");
    $mainSetup = null;

    $mainLockReadyPath = $runtimeDirectory . '/main-lock-ready';
    $mainWriterAttemptPath = $runtimeDirectory . '/main-writer-attempt';
    $orderedLeaseResultPath = $runtimeDirectory . '/ordered-lease-result';
    $orderedChildPid = pcntl_fork();
    if ($orderedChildPid === -1) {
        throw new RuntimeException('Cannot fork two-database ordering test');
    }
    if ($orderedChildPid === 0) {
        try {
            $startDeadline = hrtime(true) + 2_000_000_000;
            while (!is_file($mainLockReadyPath) && hrtime(true) < $startDeadline) {
                usleep(10_000);
            }
            if (!is_file($mainLockReadyPath)) {
                exit(4);
            }

            $mainWriter = new PDO('sqlite:' . $mainSqlitePath);
            $mainWriter->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $mainWriter->setAttribute(PDO::ATTR_TIMEOUT, 3);
            file_put_contents($mainWriterAttemptPath, 'attempt', LOCK_EX);
            $mainWriter->exec("UPDATE api_keys SET value = 'admin' WHERE id = 1");

            // This happens only after the Core database write has completed.
            $orderedGuard = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
            $orderedLease = $orderedGuard->acquireWriteLease();
            file_put_contents(
                $orderedLeaseResultPath,
                is_resource($orderedLease) ? 'acquired' : 'blocked',
                LOCK_EX
            );
            $orderedGuard->releaseWriteLease($orderedLease);
            exit(0);
        } catch (Throwable) {
            exit(5);
        }
    }

    $coreMainWriter = new PDO('sqlite:' . $mainSqlitePath);
    $coreMainWriter->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $coreMainWriter->beginTransaction();
    $coreMainWriter->exec("UPDATE api_keys SET value = 'core' WHERE id = 1");
    file_put_contents($mainLockReadyPath, 'ready', LOCK_EX);

    $attemptDeadline = hrtime(true) + 1_000_000_000;
    while (!is_file($mainWriterAttemptPath) && hrtime(true) < $attemptDeadline) {
        usleep(10_000);
    }
    expectLifecycleGuard(is_file($mainWriterAttemptPath), 'API-key writer did not reach the Core database wait');

    $orderedLifecycle = new ModuleLifecycleGuard($runtimeDirectory, $bootId);
    expectLifecycleGuard($orderedLifecycle->requestDisable(), 'Ordered two-database lifecycle transition must start');
    expectLifecycleGuard(
        $orderedLifecycle->awaitQuiescence(250),
        'Lifecycle must acquire its exclusive lease while the API request waits for the Core database'
    );
    $coreMainWriter->rollBack();

    pcntl_waitpid($orderedChildPid, $orderedChildStatus);
    expectLifecycleGuard(pcntl_wexitstatus($orderedChildStatus) === 0, 'Two-database ordering child failed');
    expectLifecycleGuard(
        file_get_contents($orderedLeaseResultPath) === 'blocked',
        'API request must observe the transition after its Core database wait, without creating a lock cycle'
    );
    $orderedLifecycle->abortOwnedTransition();
}

echo "Module lifecycle guard OK\n";
