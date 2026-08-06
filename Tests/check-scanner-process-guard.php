<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerProcessGuard;

require_once __DIR__ . '/../Lib/Services/ScannerProcessGuard.php';

function expectScannerGuard(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectScannerGuardRuntimeException(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

function removeScannerGuardTestDirectory(string $directory): void
{
    if (is_link($directory) || is_file($directory)) {
        unlink($directory);
        return;
    }
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeScannerGuardTestDirectory($directory . DIRECTORY_SEPARATOR . $entry);
    }
    rmdir($directory);
}

function scannerGuardMode(string $path): int
{
    clearstatcache(true, $path);
    $permissions = fileperms($path);
    if ($permissions === false) {
        throw new RuntimeException('unable to read scanner guard permissions');
    }

    return $permissions & 0777;
}

$temporaryRoot = sys_get_temp_dir() . '/local-stt-scanner-guard-' . bin2hex(random_bytes(8));
$runtimeDirectory = $temporaryRoot . '/runtime';
register_shutdown_function(
    static function () use ($temporaryRoot): void {
        removeScannerGuardTestDirectory($temporaryRoot);
    }
);

$now = 1_785_819_600;
$clock = static function () use (&$now): int {
    return $now;
};
$lockPath = $runtimeDirectory . '/.scanner-process.lock';
$statePath = $runtimeDirectory . '/.scanner-recovery-state.json';

$first = new ScannerProcessGuard($runtimeDirectory, $clock);
$second = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($first->acquire(), 'first scanner must acquire the singleton');
expectScannerGuard($first->acquire(), 'owning scanner acquisition must be idempotent');
expectScannerGuard(is_dir($runtimeDirectory), 'scanner runtime directory was not created');
expectScannerGuard(scannerGuardMode($runtimeDirectory) === 0750, 'scanner runtime directory mode must be 0750');
expectScannerGuard(is_file($lockPath), 'scanner lock file was not created');
expectScannerGuard(scannerGuardMode($lockPath) === 0640, 'scanner lock file mode must be 0640');
expectScannerGuard(!$second->acquire(), 'second scanner must be rejected');
$first->release();
expectScannerGuard($second->acquire(), 'lock must become available after release');
$second->release();

expectScannerGuardRuntimeException(
    static fn (): int => (new ScannerProcessGuard($runtimeDirectory, $clock))->startupBackoffSeconds(),
    'state reads must require singleton ownership'
);
expectScannerGuardRuntimeException(
    static fn () => (new ScannerProcessGuard($runtimeDirectory, $clock))->recordRecycle(),
    'state writes must require singleton ownership'
);

$expectedBackoffs = [5, 15, 30, 60, 60];
foreach ($expectedBackoffs as $expectedBackoff) {
    $writer = new ScannerProcessGuard($runtimeDirectory, $clock);
    expectScannerGuard($writer->acquire(), 'recycle writer could not acquire singleton');
    $writer->recordRecycle();
    $writer->release();

    $reader = new ScannerProcessGuard($runtimeDirectory, $clock);
    expectScannerGuard($reader->acquire(), 'backoff reader could not acquire singleton');
    expectScannerGuard(
        $reader->startupBackoffSeconds() === $expectedBackoff,
        "unexpected startup backoff; expected {$expectedBackoff} seconds"
    );
    $reader->release();
}
expectScannerGuard(scannerGuardMode($statePath) === 0640, 'scanner state file mode must be 0640');

$state = json_decode((string)file_get_contents($statePath), true, 8, JSON_THROW_ON_ERROR);
expectScannerGuard(
    $state === ['failure_count' => 5, 'last_failure_at' => $now],
    'recycle state must contain only the exact persisted fields'
);
expectScannerGuard(
    glob($statePath . '.tmp-*') === [],
    'atomic state writes must not leave sibling temporary files'
);

$healthy = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($healthy->acquire(), 'healthy writer could not acquire singleton');
$healthy->recordHealthy();
$healthy->release();
$healthyReader = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($healthyReader->acquire(), 'healthy reader could not acquire singleton');
expectScannerGuard($healthyReader->startupBackoffSeconds() === 0, 'healthy state must clear startup backoff');
$healthyReader->release();
expectScannerGuard(
    json_decode((string)file_get_contents($statePath), true, 8, JSON_THROW_ON_ERROR)
        === ['failure_count' => 0, 'last_failure_at' => 0],
    'healthy state must persist an exact zero state'
);

$freshRecycle = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($freshRecycle->acquire(), 'fresh recycle writer could not acquire singleton');
$freshRecycle->recordRecycle();
$freshRecycle->release();
$now += 301;
$staleReader = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($staleReader->acquire(), 'stale state reader could not acquire singleton');
expectScannerGuard($staleReader->startupBackoffSeconds() === 0, 'expired recycle series must not delay startup');
$staleReader->recordRecycle();
$staleReader->release();
$resetReader = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($resetReader->acquire(), 'reset series reader could not acquire singleton');
expectScannerGuard($resetReader->startupBackoffSeconds() === 5, 'expired recycle series must restart at count one');
$resetReader->release();
expectScannerGuard(
    json_decode((string)file_get_contents($statePath), true, 8, JSON_THROW_ON_ERROR)
        === ['failure_count' => 1, 'last_failure_at' => $now],
    'expired recycle series must persist a new count-one state'
);

file_put_contents($statePath, '{invalid json');
$malformed = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($malformed->acquire(), 'malformed state guard could not acquire singleton');
expectScannerGuard($malformed->startupBackoffSeconds() === 5, 'invalid state must use minimum delay');
expectScannerGuard(
    $malformed->lastStateErrorCode() === 'scanner_state_invalid',
    'invalid state code missing'
);
unset($malformed);
gc_collect_cycles();
$afterDestructor = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($afterDestructor->acquire(), 'destructor must release the singleton lock');
$afterDestructor->release();

$stateTarget = $temporaryRoot . '/state-target.json';
$stateTargetContents = json_encode(
    ['failure_count' => 3, 'last_failure_at' => $now],
    JSON_THROW_ON_ERROR
);
file_put_contents($stateTarget, $stateTargetContents);
unlink($statePath);
symlink($stateTarget, $statePath);
$symlinkedState = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($symlinkedState->acquire(), 'symlink state guard could not acquire singleton');
expectScannerGuard($symlinkedState->startupBackoffSeconds() === 5, 'symlinked state must fail closed');
expectScannerGuard(
    $symlinkedState->lastStateErrorCode() === 'scanner_state_invalid',
    'symlinked state must report a bounded invalid-state code'
);
expectScannerGuard(
    file_get_contents($stateTarget) === $stateTargetContents,
    'symlinked state target must not be followed or modified'
);
$symlinkedState->release();
unlink($statePath);

$lockTarget = $temporaryRoot . '/lock-target';
$lockTargetContents = 'do-not-open';
file_put_contents($lockTarget, $lockTargetContents);
unlink($lockPath);
symlink($lockTarget, $lockPath);
expectScannerGuardRuntimeException(
    static fn (): bool => (new ScannerProcessGuard($runtimeDirectory, $clock))->acquire(),
    'symlinked scanner lock must be rejected'
);
expectScannerGuard(
    file_get_contents($lockTarget) === $lockTargetContents,
    'symlinked lock target must not be followed or modified'
);

$runtimeTarget = $temporaryRoot . '/runtime-target';
mkdir($runtimeTarget, 0750);
$runtimeLink = $temporaryRoot . '/runtime-link';
symlink($runtimeTarget, $runtimeLink);
expectScannerGuardRuntimeException(
    static fn (): bool => (new ScannerProcessGuard($runtimeLink, $clock))->acquire(),
    'symlinked scanner runtime directory must be rejected'
);
expectScannerGuardRuntimeException(
    static fn (): bool => (new ScannerProcessGuard('', $clock))->acquire(),
    'empty scanner runtime directory must be rejected'
);

echo "Scanner process guard behavior OK\n";
