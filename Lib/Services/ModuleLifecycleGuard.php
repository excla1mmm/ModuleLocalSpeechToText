<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\PbxExtensionModules;
use RuntimeException;
use Throwable;

/**
 * Coordinates module database writers with enable/disable lifecycle probes.
 *
 * The state marker intentionally lives outside SQLite. A transition mutex
 * serializes status requests on older Core versions. Runtime writers hold a
 * shared advisory lock for one complete unit of work; lifecycle hooks publish
 * a marker and then wait for the exclusive writer lock before Core scans
 * module models.
 */
final class ModuleLifecycleGuard
{
    public const string STATE_OPEN = 'open';
    public const string STATE_TRANSITION = 'transition';
    public const string STATE_DISABLED = 'disabled';
    public const string STATE_FILE_NAME = '.module-lifecycle-state.json';
    public const string LOCK_FILE_NAME = '.module-lifecycle.lock';
    public const string MUTEX_FILE_NAME = '.module-lifecycle-transition.lock';

    private const string TARGET_ENABLED = 'enabled';
    private const int LOCK_RETRY_MICROSECONDS = 50_000;

    private string $statePath;
    private string $lockPath;
    private string $mutexPath;
    private string $bootId;
    private string $ownerToken;
    private bool $available = false;
    private mixed $authoritativeStateResolver;
    private mixed $exclusiveLease = null;
    private mixed $transitionMutex = null;

    /**
     * @param callable(): (self::STATE_OPEN|self::STATE_DISABLED|null)|null $authoritativeStateResolver
     */
    public function __construct(
        ?string $runtimeDirectory = null,
        ?string $bootId = null,
        ?callable $authoritativeStateResolver = null,
        bool $bootstrap = false
    ) {
        $runtimeDirectory ??= dirname(__DIR__, 2) . '/db';
        $runtimeDirectory = rtrim($runtimeDirectory, DIRECTORY_SEPARATOR);
        if ($runtimeDirectory === '') {
            throw new RuntimeException('Module lifecycle runtime directory is empty');
        }

        $this->statePath = $runtimeDirectory . DIRECTORY_SEPARATOR . self::STATE_FILE_NAME;
        $this->lockPath = $runtimeDirectory . DIRECTORY_SEPARATOR . self::LOCK_FILE_NAME;
        $this->mutexPath = $runtimeDirectory . DIRECTORY_SEPARATOR . self::MUTEX_FILE_NAME;
        $this->bootId = $bootId ?? $this->systemBootId();
        $this->ownerToken = bin2hex(random_bytes(16));
        $this->authoritativeStateResolver = $authoritativeStateResolver;

        if (!is_dir($runtimeDirectory)) {
            if (!$bootstrap) {
                return;
            }
            if (!mkdir($runtimeDirectory, 0755, true) && !is_dir($runtimeDirectory)) {
                throw new RuntimeException('Cannot create module lifecycle runtime directory');
            }
        }

        if ($bootstrap) {
            $this->ensureLockFile($this->lockPath);
            $this->ensureLockFile($this->mutexPath);
        } elseif (!$this->regularFileExists($this->lockPath) || !$this->regularFileExists($this->mutexPath)) {
            return;
        }

        $this->available = true;
        $stateExists = $this->regularFileExists($this->statePath);
        if ($bootstrap && $stateExists) {
            $this->applyCoordinationFilePermissions($this->statePath);
        }
        if ($bootstrap && !$stateExists) {
            // Bootstrap occurs during install/update or a trusted lifecycle
            // request. Keep cached old routes blocked until Core completes an
            // explicit enable transition.
            $this->writeDisabledMarker();
        }
    }

    public function __destruct()
    {
        $this->releaseExclusiveLease();
        $this->releaseTransitionMutex();
    }

    public function currentState(): string
    {
        if (!$this->available) {
            return self::STATE_DISABLED;
        }

        $marker = $this->readMarker();

        return $marker['state'] ?? self::STATE_OPEN;
    }

    public function isWriteBlocked(): bool
    {
        return $this->currentState() !== self::STATE_OPEN;
    }

    /**
     * @return resource|null
     */
    public function acquireWriteLease(): mixed
    {
        if ($this->isWriteBlocked()) {
            return null;
        }

        $lease = $this->openRegularFile($this->lockPath, 'Cannot open module lifecycle lock');
        if (!flock($lease, LOCK_SH | LOCK_NB)) {
            fclose($lease);
            return null;
        }

        // The transition marker is written before lifecycle waits for LOCK_EX.
        // Rechecking after LOCK_SH closes the race with a writer that started at
        // the same instant as the status change.
        if ($this->isWriteBlocked()) {
            flock($lease, LOCK_UN);
            fclose($lease);
            return null;
        }

        return $lease;
    }

    public function releaseWriteLease(mixed $lease): void
    {
        if (!is_resource($lease)) {
            return;
        }

        flock($lease, LOCK_UN);
        fclose($lease);
    }

    public function requestDisable(): bool
    {
        return $this->requestTransition(self::STATE_DISABLED);
    }

    public function requestEnable(): bool
    {
        return $this->requestTransition(self::TARGET_ENABLED);
    }

    /**
     * Wait until every writer that entered before the marker has completed.
     */
    public function awaitQuiescence(int $timeoutMilliseconds): bool
    {
        if (is_resource($this->exclusiveLease)) {
            return true;
        }
        if (!is_resource($this->transitionMutex)) {
            throw new RuntimeException('Module lifecycle transition mutex is not held');
        }

        $lease = $this->openRegularFile($this->lockPath, 'Cannot open module lifecycle lock');

        $timeoutMilliseconds = max(0, $timeoutMilliseconds);
        $deadline = hrtime(true) + ($timeoutMilliseconds * 1_000_000);
        do {
            if (flock($lease, LOCK_EX | LOCK_NB)) {
                $this->exclusiveLease = $lease;
                return true;
            }
            if (hrtime(true) >= $deadline) {
                break;
            }
            usleep(self::LOCK_RETRY_MICROSECONDS);
        } while (true);

        fclose($lease);
        return false;
    }

    public function completeDisable(): void
    {
        $this->assertExclusiveLease();
        $this->writeDisabledMarker();
        $this->releaseExclusiveLease();
        $this->releaseTransitionMutex();
    }

    public function completeEnable(): void
    {
        $this->assertExclusiveLease();
        $marker = $this->readMarker(false);
        if ($marker !== null) {
            $this->clearMarkerIfUnchanged($marker['raw']);
        }
        $this->releaseExclusiveLease();
        $this->releaseTransitionMutex();
    }

    /**
     * Rolls back only an unfinished transition created by this process.
     */
    public function abortOwnedTransition(): void
    {
        try {
            $marker = $this->readMarker(false);
            if (
                ($marker['state'] ?? null) === self::STATE_TRANSITION
                && ($marker['owner_pid'] ?? 0) === getmypid()
                && hash_equals((string)($marker['owner_token'] ?? ''), $this->ownerToken)
            ) {
                $this->restorePreviousState($marker);
            }
        } finally {
            $this->releaseExclusiveLease();
            $this->releaseTransitionMutex();
        }
    }

    /**
     * Resolve an interrupted transition after Core may have committed its flag.
     */
    public function reconcileOwnedTransition(): void
    {
        try {
            if (!$this->available) {
                return;
            }
            $marker = $this->readMarker(false);
            if (
                ($marker['state'] ?? null) === self::STATE_TRANSITION
                && ($marker['owner_pid'] ?? 0) === getmypid()
                && hash_equals((string)($marker['owner_token'] ?? ''), $this->ownerToken)
            ) {
                $this->restoreAuthoritativeState($marker);
            }
        } finally {
            $this->releaseExclusiveLease();
            $this->releaseTransitionMutex();
        }
    }

    private function requestTransition(string $target): bool
    {
        if (!$this->available) {
            throw new RuntimeException('Module lifecycle coordination is not initialized');
        }
        if (!$this->acquireTransitionMutex()) {
            return false;
        }

        $marker = $this->readMarker();
        if (($marker['state'] ?? null) === self::STATE_TRANSITION) {
            $ownedTransition = ($marker['owner_pid'] ?? 0) === getmypid()
                && hash_equals((string)($marker['owner_token'] ?? ''), $this->ownerToken)
                && ($marker['target'] ?? '') === $target;
            if (!$ownedTransition) {
                $this->releaseTransitionMutex();
            }

            return $ownedTransition;
        }

        // The Core invokes an enable hook only while its authoritative module
        // state is disabled, and a disable hook only while it is enabled.  The
        // requested transition is therefore a safer rollback source than a
        // missing marker on first boot or after an upgrade.
        $previousState = $target === self::TARGET_ENABLED
            ? self::STATE_DISABLED
            : self::STATE_OPEN;

        $this->writeMarker([
            'state' => self::STATE_TRANSITION,
            'target' => $target,
            'previous_state' => $previousState,
            'owner_pid' => getmypid(),
            'owner_token' => $this->ownerToken,
            'started_at' => time(),
            'boot_id' => $this->bootId,
        ]);

        return true;
    }

    /**
     * @return array{state: string, target: string, previous_state: string, owner_pid: int, owner_token: string, started_at: int, boot_id: string, raw: string}|null
     */
    private function readMarker(bool $removeStaleTransition = true): ?array
    {
        if (!$this->regularFileExists($this->statePath)) {
            return null;
        }

        $state = $this->openRegularFile($this->statePath, 'Cannot read module lifecycle state');
        $raw = stream_get_contents($state);
        fclose($state);
        if ($raw === false) {
            throw new RuntimeException('Cannot read module lifecycle state');
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->invalidMarker($raw);
        }
        if (!$this->markerPayloadIsValid($decoded)) {
            return $this->invalidMarker($raw);
        }

        $markerBootId = trim((string)($decoded['boot_id'] ?? ''));
        if ($markerBootId !== '' && $this->bootId !== '' && !hash_equals($this->bootId, $markerBootId)) {
            try {
                $this->clearMarkerIfUnchanged($raw);
            } catch (Throwable) {
                // A marker from another boot is never authoritative for the
                // current process, even when this reader cannot unlink it.
            }
            return null;
        }

        $state = (string)($decoded['state'] ?? self::STATE_TRANSITION);
        if (!in_array($state, [self::STATE_TRANSITION, self::STATE_DISABLED], true)) {
            $state = self::STATE_TRANSITION;
        }
        $marker = [
            'state' => $state,
            'target' => (string)($decoded['target'] ?? self::STATE_DISABLED),
            'previous_state' => (string)($decoded['previous_state'] ?? self::STATE_OPEN),
            'owner_pid' => (int)($decoded['owner_pid'] ?? 0),
            'owner_token' => (string)($decoded['owner_token'] ?? ''),
            'started_at' => (int)($decoded['started_at'] ?? 0),
            'boot_id' => $markerBootId,
            'raw' => $raw,
        ];

        if ($removeStaleTransition && $marker['state'] === self::STATE_TRANSITION) {
            $alreadyHeldMutex = is_resource($this->transitionMutex);
            if ($this->transitionIsActive($marker)) {
                return $marker;
            }

            try {
                return $this->restoreAuthoritativeState($marker);
            } catch (Throwable) {
                // Preserve the transition marker and fail closed when the
                // authoritative Core state cannot be persisted locally.
                return $marker;
            } finally {
                if (!$alreadyHeldMutex) {
                    $this->releaseTransitionMutex();
                }
            }
        }

        return $marker;
    }

    private function markerPayloadIsValid(mixed $decoded): bool
    {
        if (!is_array($decoded)) {
            return false;
        }

        foreach (['state', 'target', 'previous_state', 'owner_pid', 'owner_token', 'started_at', 'boot_id'] as $field) {
            if (!array_key_exists($field, $decoded)) {
                return false;
            }
        }
        if (
            !is_string($decoded['state'])
            || !is_string($decoded['target'])
            || !is_string($decoded['previous_state'])
            || !is_int($decoded['owner_pid'])
            || !is_string($decoded['owner_token'])
            || !is_int($decoded['started_at'])
            || !is_string($decoded['boot_id'])
        ) {
            return false;
        }

        if ($decoded['state'] === self::STATE_DISABLED) {
            return $decoded['target'] === self::STATE_DISABLED
                && $decoded['previous_state'] === self::STATE_DISABLED
                && $decoded['owner_pid'] === 0
                && $decoded['owner_token'] === '';
        }

        return $decoded['state'] === self::STATE_TRANSITION
            && in_array($decoded['target'], [self::TARGET_ENABLED, self::STATE_DISABLED], true)
            && in_array($decoded['previous_state'], [self::STATE_OPEN, self::STATE_DISABLED], true)
            && $decoded['owner_pid'] > 0
            && $decoded['owner_token'] !== '';
    }

    /**
     * Invalid state is deliberately fail-closed.  It stays recoverable because
     * an explicit Core enable request replaces it with an owned transition.
     *
     * @return array{state: string, target: string, previous_state: string, owner_pid: int, owner_token: string, started_at: int, boot_id: string, raw: string}
     */
    private function invalidMarker(string $raw): array
    {
        return [
            'state' => self::STATE_DISABLED,
            'target' => self::STATE_DISABLED,
            'previous_state' => self::STATE_DISABLED,
            'owner_pid' => 0,
            'owner_token' => '',
            'started_at' => time(),
            'boot_id' => $this->bootId,
            'raw' => $raw,
        ];
    }

    /**
     * @param array{state: string, target: string, previous_state: string, owner_pid: int, owner_token: string, started_at: int, boot_id: string} $marker
     */
    private function writeMarker(array $marker): void
    {
        $json = json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temporaryPath = $this->statePath . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $temporary = fopen($temporaryPath, 'x+b');
        if ($temporary === false) {
            throw new RuntimeException('Cannot write temporary module lifecycle state');
        }
        try {
            if (!flock($temporary, LOCK_EX) || fwrite($temporary, $json) !== strlen($json) || !fflush($temporary)) {
                throw new RuntimeException('Cannot write temporary module lifecycle state');
            }
            $this->applyCoordinationStreamPermissions($temporary);
        } catch (Throwable $exception) {
            fclose($temporary);
            unlink($temporaryPath);
            throw $exception;
        }
        fclose($temporary);

        // rename(2) does not follow the destination symlink, but rejecting it
        // makes unsafe coordination state explicit instead of silently healing it.
        $this->regularFileExists($this->statePath);
        if (!rename($temporaryPath, $this->statePath)) {
            unlink($temporaryPath);
            throw new RuntimeException('Cannot publish module lifecycle state');
        }
    }

    private function clearMarkerIfUnchanged(string $expectedRaw): void
    {
        if (!$this->regularFileExists($this->statePath)) {
            return;
        }
        $state = $this->openRegularFile($this->statePath, 'Cannot read module lifecycle state');
        $currentRaw = stream_get_contents($state);
        fclose($state);
        if ($currentRaw === $expectedRaw && !unlink($this->statePath) && $this->regularFileExists($this->statePath)) {
            throw new RuntimeException('Cannot clear module lifecycle state');
        }
    }

    /**
     * @param array{previous_state?: string, raw: string} $marker
     */
    private function restorePreviousState(array $marker): void
    {
        if (($marker['previous_state'] ?? self::STATE_OPEN) === self::STATE_DISABLED) {
            $this->writeDisabledMarker();
            return;
        }

        $this->clearMarkerIfUnchanged($marker['raw']);
    }

    private function acquireTransitionMutex(): bool
    {
        if (is_resource($this->transitionMutex)) {
            return true;
        }

        $mutex = $this->openRegularFile($this->mutexPath, 'Cannot open module lifecycle transition mutex');
        if (!flock($mutex, LOCK_EX | LOCK_NB)) {
            fclose($mutex);
            return false;
        }

        $this->transitionMutex = $mutex;
        return true;
    }

    private function ensureLockFile(string $path): void
    {
        if (!$this->regularFileExists($path)) {
            $lock = fopen($path, 'x+b');
            if ($lock === false) {
                // Another bootstrap process may have won the exclusive create.
                if (!$this->regularFileExists($path)) {
                    throw new RuntimeException('Cannot create module lifecycle lock file');
                }
            } else {
                fclose($lock);
            }
        }

        $this->applyCoordinationFilePermissions($path);
    }

    private function regularFileExists(string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) {
            return false;
        }
        if (($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('Module lifecycle coordination path is not a regular file');
        }

        return true;
    }

    /** @return resource */
    private function openRegularFile(string $path, string $errorMessage): mixed
    {
        if (!$this->regularFileExists($path)) {
            throw new RuntimeException($errorMessage);
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException($errorMessage);
        }
        $openedStat = fstat($stream);
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
        if (
            $openedStat === false
            || $pathStat === false
            || ($openedStat['mode'] & 0170000) !== 0100000
            || ($pathStat['mode'] & 0170000) !== 0100000
            || $openedStat['dev'] !== $pathStat['dev']
            || $openedStat['ino'] !== $pathStat['ino']
        ) {
            fclose($stream);
            throw new RuntimeException('Module lifecycle coordination file changed while opening');
        }

        return $stream;
    }

    private function applyCoordinationFilePermissions(string $path): void
    {
        $stream = $this->openRegularFile($path, 'Cannot open module lifecycle coordination file');
        try {
            $this->applyCoordinationStreamPermissions($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function applyCoordinationStreamPermissions(mixed $stream): void
    {
        $descriptorPath = is_dir('/proc/self/fd') ? '/proc/self/fd/0' : '/dev/fd/0';
        if (
            function_exists('posix_geteuid')
            && posix_geteuid() === 0
            && function_exists('posix_getpwnam')
            && function_exists('posix_getgrnam')
            && posix_getpwnam('www') !== false
            && posix_getgrnam('www') !== false
        ) {
            $this->runDescriptorCommand($stream, $this->systemCommand('chown'), 'www:www', $descriptorPath);
        }
        $this->runDescriptorCommand($stream, $this->systemCommand('chmod'), '0644', $descriptorPath);
    }

    /** @param resource $stream */
    private function runDescriptorCommand(mixed $stream, string ...$command): void
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => $stream,
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start module lifecycle permission helper');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(
                'Cannot set module lifecycle coordination permissions: ' . trim((string)$stderr . (string)$stdout)
            );
        }
    }

    private function systemCommand(string $name): string
    {
        foreach (['/bin/' . $name, '/usr/bin/' . $name] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException("Cannot find {$name} for module lifecycle permissions");
    }

    private function systemBootId(): string
    {
        $bootIdPath = '/proc/sys/kernel/random/boot_id';
        if (!is_readable($bootIdPath)) {
            return '';
        }

        $bootId = file_get_contents($bootIdPath);

        return $bootId === false ? '' : trim($bootId);
    }

    /**
     * @param array{owner_pid: int, owner_token: string} $marker
     */
    private function transitionIsActive(array $marker): bool
    {
        if (is_resource($this->transitionMutex)) {
            return $marker['owner_pid'] === getmypid()
                && hash_equals($marker['owner_token'], $this->ownerToken);
        }

        $mutex = $this->openRegularFile($this->mutexPath, 'Cannot inspect module lifecycle transition mutex');
        if (!flock($mutex, LOCK_EX | LOCK_NB)) {
            fclose($mutex);
            return true;
        }

        // Keep the mutex until stale-state reconciliation finishes. Otherwise
        // a new status request could publish a marker between this check and
        // the recovery write.
        $this->transitionMutex = $mutex;

        return false;
    }

    /**
     * @param array{raw: string} $marker
     * @return array{state: string, target: string, previous_state: string, owner_pid: int, owner_token: string, started_at: int, boot_id: string, raw: string}|null
     */
    private function restoreAuthoritativeState(array $marker): ?array
    {
        if ($this->authoritativeState() === self::STATE_OPEN) {
            $this->clearMarkerIfUnchanged($marker['raw']);
            return null;
        }

        // Unknown is intentionally fail-closed. A subsequent explicit Core
        // enable can still replace this persistent disabled marker.
        $this->writeDisabledMarker();
        return $this->readMarker(false);
    }

    private function authoritativeState(): ?string
    {
        try {
            if (is_callable($this->authoritativeStateResolver)) {
                $state = ($this->authoritativeStateResolver)();
                return in_array($state, [self::STATE_OPEN, self::STATE_DISABLED], true) ? $state : null;
            }
            if (!class_exists(PbxExtensionModules::class)) {
                return null;
            }

            $module = PbxExtensionModules::findFirstByUniqid('ModuleLocalSpeechToText');
            if ($module === null) {
                return self::STATE_DISABLED;
            }

            return (string)$module->disabled === '1' ? self::STATE_DISABLED : self::STATE_OPEN;
        } catch (Throwable) {
            return null;
        }
    }

    private function writeDisabledMarker(): void
    {
        $this->writeMarker([
            'state' => self::STATE_DISABLED,
            'target' => self::STATE_DISABLED,
            'previous_state' => self::STATE_DISABLED,
            'owner_pid' => 0,
            'owner_token' => '',
            'started_at' => time(),
            'boot_id' => $this->bootId,
        ]);
    }

    private function assertExclusiveLease(): void
    {
        if (!is_resource($this->exclusiveLease)) {
            throw new RuntimeException('Module lifecycle transition is not quiescent');
        }
    }

    private function releaseExclusiveLease(): void
    {
        if (!is_resource($this->exclusiveLease)) {
            return;
        }

        flock($this->exclusiveLease, LOCK_UN);
        fclose($this->exclusiveLease);
        $this->exclusiveLease = null;
    }

    private function releaseTransitionMutex(): void
    {
        if (!is_resource($this->transitionMutex)) {
            return;
        }

        flock($this->transitionMutex, LOCK_UN);
        fclose($this->transitionMutex);
        $this->transitionMutex = null;
    }
}
