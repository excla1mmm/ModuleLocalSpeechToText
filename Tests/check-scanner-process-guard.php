<?php

declare(strict_types=1);

namespace MikoPBX\Core\System {
    final class Directories
    {
        public const string CORE_TEMP_DIR = 'core_temp';
        public static string $coreTempDirectory = '';

        public static function getDir(string $directory): string
        {
            return self::$coreTempDirectory;
        }
    }

    final class Processes
    {
        public static string $lockFileDirectory = '';

        public static function getLockFileDir(): string
        {
            return self::$lockFileDirectory;
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    final class ScannerGuardFilesystemProbe
    {
        public static ?int $flockWouldBlock = null;
        public static ?string $fopenFailureNeedle = null;
        public static ?string $pathCheckFailureNeedle = null;
        public static ?string $forcedChmodNeedle = null;
        public static ?int $forcedChmodMode = null;
        public static ?string $forcedRenameNeedle = null;
        public static ?int $forcedRenameMode = null;
        public static string $filesystemWarning = '';
    }

    function flock(mixed $stream, int $operation, ?int &$wouldBlock = null): bool
    {
        if (ScannerGuardFilesystemProbe::$flockWouldBlock !== null && ($operation & LOCK_EX) === LOCK_EX) {
            $wouldBlock = ScannerGuardFilesystemProbe::$flockWouldBlock;
            return false;
        }

        return \flock($stream, $operation, $wouldBlock);
    }

    function fopen(
        string $filename,
        string $mode,
        bool $useIncludePath = false,
        mixed $context = null
    ): mixed {
        $needle = ScannerGuardFilesystemProbe::$fopenFailureNeedle;
        if ($needle !== null && str_contains($filename, $needle)) {
            trigger_error(ScannerGuardFilesystemProbe::$filesystemWarning, E_USER_WARNING);
            return false;
        }

        return $context === null
            ? \fopen($filename, $mode, $useIncludePath)
            : \fopen($filename, $mode, $useIncludePath, $context);
    }

    function file_exists(string $filename): bool
    {
        $needle = ScannerGuardFilesystemProbe::$pathCheckFailureNeedle;
        if ($needle !== null && str_contains($filename, $needle)) {
            trigger_error(ScannerGuardFilesystemProbe::$filesystemWarning, E_USER_WARNING);
            return false;
        }
        return \file_exists($filename);
    }

    function is_link(string $filename): bool
    {
        $needle = ScannerGuardFilesystemProbe::$pathCheckFailureNeedle;
        if ($needle !== null && str_contains($filename, $needle)) {
            trigger_error(ScannerGuardFilesystemProbe::$filesystemWarning, E_USER_WARNING);
            return false;
        }
        return \is_link($filename);
    }

    function chmod(string $filename, int $permissions): bool
    {
        if (!\chmod($filename, $permissions)) {
            return false;
        }
        $needle = ScannerGuardFilesystemProbe::$forcedChmodNeedle;
        $forcedMode = ScannerGuardFilesystemProbe::$forcedChmodMode;
        if ($needle !== null && $forcedMode !== null && str_contains($filename, $needle)) {
            return \chmod($filename, $forcedMode);
        }

        return true;
    }

    function rename(string $from, string $to, mixed $context = null): bool
    {
        $renamed = $context === null ? \rename($from, $to) : \rename($from, $to, $context);
        if (!$renamed) {
            return false;
        }
        $needle = ScannerGuardFilesystemProbe::$forcedRenameNeedle;
        $forcedMode = ScannerGuardFilesystemProbe::$forcedRenameMode;
        if ($needle !== null && $forcedMode !== null && str_contains($to, $needle)) {
            return \chmod($to, $forcedMode);
        }

        return true;
    }
}

namespace {
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\Processes;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerGuardFilesystemProbe;
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

function scannerGuardFullMode(string $path): int
{
    clearstatcache(true, $path);
    $permissions = fileperms($path);
    if ($permissions === false) {
        throw new RuntimeException('unable to read full scanner guard permissions');
    }

    return $permissions & 07777;
}

function scannerGuardOperationThrows(callable $operation): bool
{
    try {
        $operation();
    } catch (RuntimeException) {
        return true;
    }

    return false;
}

$temporaryRoot = sys_get_temp_dir() . '/local-stt-scanner-guard-' . bin2hex(random_bytes(8));
$runtimeDirectory = $temporaryRoot . '/runtime';
register_shutdown_function(
    static function () use ($temporaryRoot): void {
        removeScannerGuardTestDirectory($temporaryRoot);
    }
);
Directories::$coreTempDirectory = $temporaryRoot . '/unsafe-core-temp';
Processes::$lockFileDirectory = $temporaryRoot . '/stable-worker-locks';

$now = 1_785_819_600;
$clock = static function () use (&$now): int {
    return $now;
};
$lockPath = $runtimeDirectory . '/.scanner-process.lock';
$statePath = $runtimeDirectory . '/.scanner-recovery-state.json';

$defaultRuntimeDirectory = Processes::$lockFileDirectory . '/ModuleLocalSpeechToText/scanner';
$defaultGuard = new ScannerProcessGuard();
expectScannerGuard($defaultGuard->acquire(), 'default scanner guard could not acquire singleton');
expectScannerGuard(
    is_file($defaultRuntimeDirectory . '/.scanner-process.lock'),
    'default scanner guard must use the stable Core-managed worker lock directory'
);
expectScannerGuard(
    !file_exists(Directories::$coreTempDirectory . '/ModuleLocalSpeechToText/scanner/.scanner-process.lock'),
    'default scanner guard must not use the clearable Core temp directory'
);
$defaultGuard->release();

$flockFailureRuntime = $temporaryRoot . '/flock-failure-runtime';
ScannerGuardFilesystemProbe::$flockWouldBlock = 0;
expectScannerGuardRuntimeException(
    static fn (): bool => (new ScannerProcessGuard($flockFailureRuntime))->acquire(),
    'non-contention flock failure must throw a stable guard exception'
);
ScannerGuardFilesystemProbe::$flockWouldBlock = 1;
expectScannerGuard(
    !(new ScannerProcessGuard($flockFailureRuntime))->acquire(),
    'actual flock contention must remain a duplicate rejection'
);
ScannerGuardFilesystemProbe::$flockWouldBlock = null;

$warningRuntime = $temporaryRoot . '/warning-runtime-private-customer-path';
$injectedFilesystemWarning = 'injected filesystem failure at /private/customer/secret.lock';
ScannerGuardFilesystemProbe::$fopenFailureNeedle = 'warning-runtime-private-customer-path';
ScannerGuardFilesystemProbe::$filesystemWarning = $injectedFilesystemWarning;
$escapedWarnings = [];
$warningException = null;
set_error_handler(
    static function (int $severity, string $message) use (&$escapedWarnings): bool {
        $escapedWarnings[] = [$severity, $message];
        return true;
    }
);
try {
    try {
        (new ScannerProcessGuard($warningRuntime))->acquire();
    } catch (Throwable $exception) {
        $warningException = $exception;
    }
} finally {
    restore_error_handler();
    ScannerGuardFilesystemProbe::$fopenFailureNeedle = null;
    ScannerGuardFilesystemProbe::$filesystemWarning = '';
}
expectScannerGuard($warningException instanceof RuntimeException, 'filesystem warning must become a guard exception');
expectScannerGuard($escapedWarnings === [], 'guard filesystem warnings must not escape its sanitized boundary');
expectScannerGuard(
    !str_contains((string)$warningException?->getMessage(), $injectedFilesystemWarning)
        && !str_contains((string)$warningException?->getMessage(), $warningRuntime),
    'guard filesystem exception must not expose injected text or a runtime path'
);

$pathCheckRuntime = $temporaryRoot . '/path-check-runtime-private-customer-path';
$injectedPathCheckWarning = 'open_basedir restriction at /private/customer/secret-runtime';
ScannerGuardFilesystemProbe::$pathCheckFailureNeedle = 'path-check-runtime-private-customer-path';
ScannerGuardFilesystemProbe::$filesystemWarning = $injectedPathCheckWarning;
$escapedWarnings = [];
$pathCheckException = null;
set_error_handler(
    static function (int $severity, string $message) use (&$escapedWarnings): bool {
        $escapedWarnings[] = [$severity, $message];
        return true;
    }
);
try {
    try {
        (new ScannerProcessGuard($pathCheckRuntime))->acquire();
    } catch (Throwable $exception) {
        $pathCheckException = $exception;
    }
} finally {
    restore_error_handler();
    ScannerGuardFilesystemProbe::$pathCheckFailureNeedle = null;
    ScannerGuardFilesystemProbe::$filesystemWarning = '';
}
expectScannerGuard($pathCheckException instanceof RuntimeException, 'path-check warning must become a guard exception');
expectScannerGuard($escapedWarnings === [], 'guard pathname-check warnings must not escape its sanitized boundary');
expectScannerGuard(
    !str_contains((string)$pathCheckException?->getMessage(), $injectedPathCheckWarning)
        && !str_contains((string)$pathCheckException?->getMessage(), $pathCheckRuntime),
    'guard pathname-check exception must not expose injected text or a runtime path'
);

$first = new ScannerProcessGuard($runtimeDirectory, $clock);
$second = new ScannerProcessGuard($runtimeDirectory, $clock);
expectScannerGuard($first->acquire(), 'first scanner must acquire the singleton');
expectScannerGuard($first->acquire(), 'owning scanner acquisition must be idempotent');
$first->assertOwnership();
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

$permissiveRuntimeDirectory = $temporaryRoot . '/permissive-runtime';
$permissiveLockPath = $permissiveRuntimeDirectory . '/.scanner-process.lock';
$permissiveStatePath = $permissiveRuntimeDirectory . '/.scanner-recovery-state.json';
mkdir($permissiveRuntimeDirectory, 0777);
chmod($permissiveRuntimeDirectory, 0777);
file_put_contents($permissiveLockPath, '');
chmod($permissiveLockPath, 0666);
file_put_contents(
    $permissiveStatePath,
    json_encode(['failure_count' => 1, 'last_failure_at' => $now], JSON_THROW_ON_ERROR)
);
chmod($permissiveStatePath, 0666);
$permissiveGuard = new ScannerProcessGuard($permissiveRuntimeDirectory, $clock);
expectScannerGuard($permissiveGuard->acquire(), 'permissive pre-existing guard could not acquire singleton');
expectScannerGuard(
    scannerGuardMode($permissiveRuntimeDirectory) === 0750,
    'pre-existing scanner runtime directory mode must be normalized to 0750'
);
expectScannerGuard(
    scannerGuardMode($permissiveLockPath) === 0640,
    'pre-existing scanner lock mode must be normalized to 0640'
);
expectScannerGuard(
    $permissiveGuard->startupBackoffSeconds() === 5,
    'valid pre-existing scanner state must remain readable after normalization'
);
expectScannerGuard(
    scannerGuardMode($permissiveStatePath) === 0640,
    'pre-existing scanner state mode must be normalized to 0640'
);
$permissiveGuard->release();

expectScannerGuardRuntimeException(
    static function () use ($permissiveRuntimeDirectory): void {
        $invalidClock = new ScannerProcessGuard(
            $permissiveRuntimeDirectory,
            static fn (): string => 'not-an-integer'
        );
        expectScannerGuard($invalidClock->acquire(), 'invalid-clock guard could not acquire singleton');
        $invalidClock->startupBackoffSeconds();
    },
    'non-integer scanner clock results must be rejected'
);
$afterInvalidClock = new ScannerProcessGuard($permissiveRuntimeDirectory, $clock);
expectScannerGuard($afterInvalidClock->acquire(), 'invalid clock failure must release the singleton on unwind');
$afterInvalidClock->release();

$replacedRuntimeDirectory = $temporaryRoot . '/replaced-runtime';
$retainedRuntimeDirectory = $temporaryRoot . '/retained-runtime';
$replacedRuntimeGuard = new ScannerProcessGuard($replacedRuntimeDirectory, $clock);
expectScannerGuard($replacedRuntimeGuard->acquire(), 'runtime replacement guard could not acquire singleton');
rename($replacedRuntimeDirectory, $retainedRuntimeDirectory);
mkdir($replacedRuntimeDirectory, 0750);
expectScannerGuardRuntimeException(
    static fn (): int => $replacedRuntimeGuard->startupBackoffSeconds(),
    'replaced scanner runtime directory inode must be rejected while the lock is owned'
);
$replacedRuntimeGuard->release();

$modeRuntimeDirectory = $temporaryRoot . '/mode-runtime';
$modeGuard = new ScannerProcessGuard($modeRuntimeDirectory, $clock);
expectScannerGuard($modeGuard->acquire(), 'mode validation guard could not acquire singleton');
chmod($modeRuntimeDirectory, 0700);
expectScannerGuardRuntimeException(
    static fn (): null => $modeGuard->assertOwnership(),
    'ownership assertion must reject a changed runtime directory mode'
);
chmod($modeRuntimeDirectory, 0750);
$modeLockPath = $modeRuntimeDirectory . '/.scanner-process.lock';
chmod($modeLockPath, 0600);
expectScannerGuardRuntimeException(
    static fn (): null => $modeGuard->assertOwnership(),
    'ownership assertion must reject a changed lock file mode'
);
chmod($modeLockPath, 0640);

foreach ([02750, 01750] as $specialRuntimeMode) {
    chmod($modeRuntimeDirectory, $specialRuntimeMode);
    if (scannerGuardFullMode($modeRuntimeDirectory) === $specialRuntimeMode) {
        expectScannerGuard(
            scannerGuardOperationThrows(static fn (): null => $modeGuard->assertOwnership()),
            sprintf('ownership assertion accepted special-bit runtime mode %04o', $specialRuntimeMode)
        );
    }
    chmod($modeRuntimeDirectory, 0750);
}
foreach ([04640, 01640] as $specialLockMode) {
    chmod($modeLockPath, $specialLockMode);
    if (scannerGuardFullMode($modeLockPath) === $specialLockMode) {
        expectScannerGuard(
            scannerGuardOperationThrows(static fn (): null => $modeGuard->assertOwnership()),
            sprintf('ownership assertion accepted special-bit lock mode %04o', $specialLockMode)
        );
    }
    chmod($modeLockPath, 0640);
}
$modeGuard->release();

$specialStateRuntime = $temporaryRoot . '/special-state-runtime';
$specialStatePath = $specialStateRuntime . '/.scanner-recovery-state.json';
mkdir($specialStateRuntime, 0750);
file_put_contents(
    $specialStatePath,
    json_encode(['failure_count' => 1, 'last_failure_at' => $now], JSON_THROW_ON_ERROR)
);
foreach ([04640, 01640] as $specialStateMode) {
    chmod($specialStatePath, $specialStateMode);
    if (scannerGuardFullMode($specialStatePath) !== $specialStateMode) {
        continue;
    }
    $specialStateGuard = new ScannerProcessGuard($specialStateRuntime, $clock);
    expectScannerGuard($specialStateGuard->acquire(), 'special-mode state guard could not acquire singleton');
    expectScannerGuard($specialStateGuard->startupBackoffSeconds() === 5, 'special-mode state could not be read');
    expectScannerGuard(
        scannerGuardFullMode($specialStatePath) === 0640,
        sprintf('state mode %04o was not normalized to exact 0640', $specialStateMode)
    );
    $specialStateGuard->release();
}

$specialModeProbe = $temporaryRoot . '/special-mode-probe';
file_put_contents($specialModeProbe, 'probe');
$supportedPublicationMode = null;
foreach ([04640, 01640] as $candidateMode) {
    chmod($specialModeProbe, $candidateMode);
    if (scannerGuardFullMode($specialModeProbe) === $candidateMode) {
        $supportedPublicationMode = $candidateMode;
        break;
    }
}
chmod($specialModeProbe, 0640);
if ($supportedPublicationMode !== null) {
    $specialPublicationRuntime = $temporaryRoot . '/special-publication-runtime';
    $specialPublicationGuard = new ScannerProcessGuard($specialPublicationRuntime, $clock);
    expectScannerGuard($specialPublicationGuard->acquire(), 'special publication guard could not acquire singleton');
    ScannerGuardFilesystemProbe::$forcedChmodNeedle = '.scanner-recovery-state.json.tmp-';
    ScannerGuardFilesystemProbe::$forcedChmodMode = $supportedPublicationMode;
    try {
        $specialPublicationRejected = scannerGuardOperationThrows(
            static fn (): null => $specialPublicationGuard->recordRecycle()
        );
    } finally {
        ScannerGuardFilesystemProbe::$forcedChmodNeedle = null;
        ScannerGuardFilesystemProbe::$forcedChmodMode = null;
        $specialPublicationGuard->release();
    }
    expectScannerGuard(
        $specialPublicationRejected,
        sprintf('temporary path accepted special-bit mode %04o', $supportedPublicationMode)
    );

    $specialPublishedRuntime = $temporaryRoot . '/special-published-runtime';
    $specialPublishedGuard = new ScannerProcessGuard($specialPublishedRuntime, $clock);
    expectScannerGuard($specialPublishedGuard->acquire(), 'special published-state guard could not acquire singleton');
    ScannerGuardFilesystemProbe::$forcedRenameNeedle = '.scanner-recovery-state.json';
    ScannerGuardFilesystemProbe::$forcedRenameMode = $supportedPublicationMode;
    try {
        $specialPublishedRejected = scannerGuardOperationThrows(
            static fn (): null => $specialPublishedGuard->recordRecycle()
        );
    } finally {
        ScannerGuardFilesystemProbe::$forcedRenameNeedle = null;
        ScannerGuardFilesystemProbe::$forcedRenameMode = null;
        $specialPublishedGuard->release();
    }
    expectScannerGuard(
        $specialPublishedRejected,
        sprintf('published state accepted special-bit mode %04o', $supportedPublicationMode)
    );
}

$replacedLockRuntime = $temporaryRoot . '/replaced-lock-runtime';
$replacedLockPath = $replacedLockRuntime . '/.scanner-process.lock';
$retainedLockPath = $replacedLockRuntime . '/.scanner-process.lock.retained';
$replacedLockGuard = new ScannerProcessGuard($replacedLockRuntime, $clock);
expectScannerGuard($replacedLockGuard->acquire(), 'lock replacement guard could not acquire singleton');
rename($replacedLockPath, $retainedLockPath);
file_put_contents($replacedLockPath, 'replacement');
chmod($replacedLockPath, 0640);
expectScannerGuardRuntimeException(
    static fn (): null => $replacedLockGuard->assertOwnership(),
    'ownership assertion must reject replacement of the retained lock pathname'
);
$replacedLockGuard->release();

$unreadableStateRuntime = $temporaryRoot . '/unreadable-state-runtime';
$unreadableStateGuard = new ScannerProcessGuard($unreadableStateRuntime, $clock);
expectScannerGuard($unreadableStateGuard->acquire(), 'unreadable-state setup guard could not acquire singleton');
$unreadableStateGuard->recordRecycle();
$unreadableStateGuard->release();
$unreadableStatePath = $unreadableStateRuntime . '/.scanner-recovery-state.json';
chmod($unreadableStatePath, 0000);
clearstatcache(true, $unreadableStatePath);
if (!is_readable($unreadableStatePath)) {
    $filesystemWarningRaised = false;
    set_error_handler(
        static function () use (&$filesystemWarningRaised): bool {
            $filesystemWarningRaised = true;
            return true;
        }
    );
    try {
        $unreadableStateReader = new ScannerProcessGuard($unreadableStateRuntime, $clock);
        expectScannerGuard($unreadableStateReader->acquire(), 'unreadable state reader could not acquire singleton');
        expectScannerGuard(
            $unreadableStateReader->startupBackoffSeconds() === 5,
            'unreadable regular state must use minimum delay'
        );
        expectScannerGuard(
            $unreadableStateReader->lastStateErrorCode() === 'scanner_state_invalid',
            'unreadable regular state must report invalid-state code'
        );
        $unreadableStateReader->release();
    } finally {
        restore_error_handler();
    }
    expectScannerGuard(!$filesystemWarningRaised, 'unreadable regular state must not emit filesystem warnings');
}
chmod($unreadableStatePath, 0640);

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

$canonicalParent = $temporaryRoot . '/canonical-parent';
$canonicalRuntime = $canonicalParent . '/runtime';
$retainedCanonicalParent = $temporaryRoot . '/canonical-parent-retained';
$canonicalGuard = new ScannerProcessGuard($canonicalRuntime, $clock);
expectScannerGuard($canonicalGuard->acquire(), 'canonical pathname guard could not acquire singleton');
rename($canonicalParent, $retainedCanonicalParent);
symlink($retainedCanonicalParent, $canonicalParent);
expectScannerGuardRuntimeException(
    static fn (): null => $canonicalGuard->assertOwnership(),
    'ownership assertion must reject a changed canonical runtime pathname'
);
$canonicalGuard->release();

echo "Scanner process guard behavior OK\n";
}
