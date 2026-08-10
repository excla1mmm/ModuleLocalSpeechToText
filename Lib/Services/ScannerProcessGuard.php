<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use MikoPBX\Core\System\Processes;
use RuntimeException;
use Throwable;

final class ScannerProcessGuard
{
    private const string LOCK_FILE = '.scanner-process.lock';
    private const string STATE_FILE = '.scanner-recovery-state.json';
    private const array BACKOFF_SECONDS = [5, 15, 30, 60];
    private const int RECYCLE_SERIES_TTL_SECONDS = 300;

    private readonly string $runtimeDirectory;
    private readonly string $lockPath;
    private readonly string $statePath;
    private mixed $clock;
    private mixed $runtimeHandle = null;
    private mixed $lockHandle = null;
    private ?string $canonicalRuntimePath = null;
    /** @var array{failure_count: int, last_failure_at: int}|null */
    private ?array $state = null;
    private bool $stateLoaded = false;
    private ?string $stateErrorCode = null;

    public function __construct(?string $runtimeDirectory = null, mixed $clock = null)
    {
        if ($runtimeDirectory === null) {
            $workerLockDirectory = method_exists(Processes::class, 'getLockFileDir')
                ? Processes::getLockFileDir()
                : '/var/run/php-workers';
            $runtimeDirectory = rtrim(
                $workerLockDirectory,
                DIRECTORY_SEPARATOR
            ) . '/ModuleLocalSpeechToText/scanner';
        }
        if ($clock !== null && !is_callable($clock)) {
            throw new RuntimeException('Scanner process clock must be callable');
        }

        $this->runtimeDirectory = rtrim($runtimeDirectory, DIRECTORY_SEPARATOR);
        $this->lockPath = $this->runtimeDirectory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $this->statePath = $this->runtimeDirectory . DIRECTORY_SEPARATOR . self::STATE_FILE;
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function __destruct()
    {
        $this->release();
    }

    public function acquire(): bool
    {
        if (is_resource($this->lockHandle)) {
            $this->assertOwnership();
            return true;
        }

        $this->runtimeHandle = $this->openRuntimeDirectory();
        $handle = null;
        try {
            $this->assertRuntimeDirectory();
            $handle = $this->openLockFile();
            $this->normalizeRegularFileMode(
                $this->lockPath,
                $handle,
                'Unable to secure scanner process lock'
            );
            $this->assertRuntimeDirectory();
            $wouldBlock = 0;
            $locked = $this->filesystemCall(
                static function () use ($handle, &$wouldBlock): bool {
                    return flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
                },
                'Unable to acquire scanner process lock'
            );
            if (!$locked && $wouldBlock === 1) {
                fclose($handle);
                $handle = null;
                fclose($this->runtimeHandle);
                $this->runtimeHandle = null;
                return false;
            }
            if (!$locked) {
                throw new RuntimeException('Unable to acquire scanner process lock');
            }
            if (!$this->pathIdentifiesHandle($this->lockPath, $handle, 0100000)) {
                throw new RuntimeException('Scanner process lock changed during acquisition');
            }
            $this->normalizeRegularFileMode(
                $this->lockPath,
                $handle,
                'Scanner process lock changed during acquisition'
            );
            $this->assertRuntimeDirectory();

            $this->lockHandle = $handle;
            $handle = null;
            $this->state = null;
            $this->stateLoaded = false;
            $this->stateErrorCode = null;

            return true;
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            if (is_resource($this->runtimeHandle)) {
                fclose($this->runtimeHandle);
            }
            $this->runtimeHandle = null;
            $this->canonicalRuntimePath = null;
            throw $exception;
        }
    }

    public function startupBackoffSeconds(): int
    {
        $state = $this->loadState();
        if (!$this->isRecentSeries($state, $this->now())) {
            return 0;
        }

        $index = min($state['failure_count'], count(self::BACKOFF_SECONDS)) - 1;

        return self::BACKOFF_SECONDS[$index];
    }

    public function recordRecycle(): void
    {
        $state = $this->loadState();
        $now = $this->now();
        $failureCount = $this->isRecentSeries($state, $now)
            ? $state['failure_count'] + 1
            : 1;
        $nextState = [
            'failure_count' => $failureCount,
            'last_failure_at' => $now,
        ];

        $this->writeState($nextState);
        $this->state = $nextState;
        $this->stateLoaded = true;
    }

    public function recordHealthy(): void
    {
        $state = $this->loadState();
        if (!$this->isRecentSeries($state, $this->now())) {
            return;
        }

        $nextState = [
            'failure_count' => 0,
            'last_failure_at' => 0,
        ];
        $this->writeState($nextState);
        $this->state = $nextState;
        $this->stateLoaded = true;
    }

    public function lastStateErrorCode(): ?string
    {
        return $this->stateErrorCode;
    }

    public function assertOwnership(): void
    {
        if (!is_resource($this->lockHandle) || !is_resource($this->runtimeHandle)) {
            throw new RuntimeException('Scanner process lock is not owned');
        }
        $this->assertRuntimeDirectory();
        if (!$this->pathIdentifiesHandle($this->lockPath, $this->lockHandle, 0100000)) {
            throw new RuntimeException('Scanner process lock changed while owned');
        }
        $this->assertRuntimeOwnerOwns($this->lockHandle, 'Scanner process lock has an unsafe owner');
        $metadata = fstat($this->lockHandle);
        if (!is_array($metadata) || ($metadata['mode'] & 07777) !== 0640) {
            throw new RuntimeException('Scanner process lock permissions changed while owned');
        }
    }

    public function release(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        if (is_resource($this->runtimeHandle)) {
            fclose($this->runtimeHandle);
        }
        $this->lockHandle = null;
        $this->runtimeHandle = null;
        $this->canonicalRuntimePath = null;
        $this->state = null;
        $this->stateLoaded = false;
        $this->stateErrorCode = null;
    }

    /** @return resource */
    private function openRuntimeDirectory(): mixed
    {
        if ($this->runtimeDirectory === '') {
            throw new RuntimeException('Scanner process runtime directory is empty');
        }

        $metadata = $this->lstatPath(
            $this->runtimeDirectory,
            'Unable to inspect scanner process runtime directory'
        );
        if ($metadata === false) {
            try {
                $created = $this->filesystemCall(
                    fn (): bool => mkdir($this->runtimeDirectory, 0750, true),
                    'Unable to create scanner process runtime directory'
                );
            } catch (RuntimeException $exception) {
                if (!$this->pathExistsOrLink(
                    $this->runtimeDirectory,
                    'Unable to inspect scanner process runtime directory'
                )) {
                    throw $exception;
                }
                $created = false;
            }
            if (!$created && !$this->pathExistsOrLink(
                $this->runtimeDirectory,
                'Unable to inspect scanner process runtime directory'
            )) {
                $metadata = $this->lstatPath(
                    $this->runtimeDirectory,
                    'Unable to inspect scanner process runtime directory'
                );
                if ($metadata === false) {
                    throw new RuntimeException('Unable to create scanner process runtime directory');
                }
            }
            $metadata = $this->lstatPath(
                $this->runtimeDirectory,
                'Unable to inspect scanner process runtime directory'
            );
        }
        if (!is_array($metadata) || !$this->isDirectoryMetadata($metadata)) {
            throw new RuntimeException('Scanner process runtime directory is unsafe');
        }
        $this->retainCanonicalRuntimePath();

        $handle = $this->filesystemCall(
            fn (): mixed => fopen($this->runtimeDirectory, 'r'),
            'Unable to open scanner process runtime directory'
        );
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open scanner process runtime directory');
        }
        try {
            if (!$this->pathIdentifiesHandle($this->runtimeDirectory, $handle, 0040000)) {
                throw new RuntimeException('Scanner process runtime directory changed during open');
            }
            $this->assertCurrentUserOwns($handle, 'Scanner process runtime directory has an unsafe owner');
            if (!$this->filesystemCall(
                fn (): bool => chmod($this->runtimeDirectory, 0750),
                'Unable to set scanner process runtime directory permissions'
            )) {
                throw new RuntimeException('Unable to set scanner process runtime directory permissions');
            }
            if (!$this->pathIdentifiesHandle($this->runtimeDirectory, $handle, 0040000)) {
                throw new RuntimeException('Scanner process runtime directory changed during permission update');
            }
            $updated = fstat($handle);
            if (!is_array($updated) || ($updated['mode'] & 07777) !== 0750) {
                throw new RuntimeException('Scanner process runtime directory permissions are unsafe');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            throw $exception;
        }

        return $handle;
    }

    /** @return resource */
    private function openLockFile(): mixed
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $metadata = $this->lstatPath($this->lockPath, 'Unable to inspect scanner process lock');
            if ($metadata === false) {
                try {
                    $handle = $this->filesystemCall(
                        fn (): mixed => fopen($this->lockPath, 'x+b'),
                        'Unable to open scanner process lock'
                    );
                } catch (RuntimeException) {
                    $handle = false;
                }
            } else {
                if (!$this->isRegularFileMetadata($metadata)) {
                    throw new RuntimeException('Scanner process lock is unsafe');
                }
                $handle = $this->filesystemCall(
                    fn (): mixed => fopen($this->lockPath, 'r+b'),
                    'Unable to open scanner process lock'
                );
            }

            if (!is_resource($handle)) {
                continue;
            }
            if ($this->pathIdentifiesHandle($this->lockPath, $handle, 0100000)) {
                $this->assertRuntimeDirectory();
                $this->assertRuntimeOwnerOwns($handle, 'Scanner process lock has an unsafe owner');
                return $handle;
            }

            fclose($handle);
            $current = $this->lstatPath($this->lockPath, 'Unable to inspect scanner process lock');
            if (is_array($current) && !$this->isRegularFileMetadata($current)) {
                throw new RuntimeException('Scanner process lock is unsafe');
            }
        }

        throw new RuntimeException('Unable to open scanner process lock');
    }

    /** @return array{failure_count: int, last_failure_at: int} */
    private function loadState(): array
    {
        $this->assertOwnership();
        if ($this->stateLoaded && $this->state !== null) {
            return $this->state;
        }

        $this->stateErrorCode = null;
        $metadata = $this->lstatPath($this->statePath, 'Unable to inspect scanner recovery state');
        if ($metadata === false) {
            return $this->setLoadedState([
                'failure_count' => 0,
                'last_failure_at' => 0,
            ]);
        }
        if (!$this->isRegularFileMetadata($metadata)) {
            return $this->setInvalidState();
        }
        if (!$this->metadataIsOwnedByRuntime($metadata) || !is_readable($this->statePath)) {
            return $this->setInvalidState();
        }

        $handle = $this->filesystemCall(
            fn (): mixed => fopen($this->statePath, 'rb'),
            'Unable to open scanner recovery state'
        );
        if (!is_resource($handle)) {
            return $this->setInvalidState();
        }
        try {
            if (!$this->pathIdentifiesHandle($this->statePath, $handle, 0100000)) {
                throw new RuntimeException('Scanner recovery state changed during open');
            }
            $this->assertRuntimeOwnerOwns($handle, 'Scanner recovery state has an unsafe owner');
            $this->normalizeRegularFileMode(
                $this->statePath,
                $handle,
                'Unable to secure scanner recovery state'
            );
            $contents = $this->filesystemCall(
                static fn (): string|false => stream_get_contents($handle),
                'Unable to read scanner recovery state'
            );
            if ($contents === false) {
                return $this->setInvalidState();
            }
            if (!$this->pathIdentifiesHandle($this->statePath, $handle, 0100000)) {
                throw new RuntimeException('Scanner recovery state changed during read');
            }
            $this->normalizeRegularFileMode(
                $this->statePath,
                $handle,
                'Scanner recovery state changed during read'
            );
            $this->assertRuntimeDirectory();
        } finally {
            fclose($handle);
        }

        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->setInvalidState();
        }
        if (
            !is_array($decoded)
            || count($decoded) !== 2
            || !array_key_exists('failure_count', $decoded)
            || !array_key_exists('last_failure_at', $decoded)
            || !is_int($decoded['failure_count'])
            || !is_int($decoded['last_failure_at'])
            || $decoded['failure_count'] < 0
            || $decoded['last_failure_at'] < 0
        ) {
            return $this->setInvalidState();
        }

        return $this->setLoadedState([
            'failure_count' => $decoded['failure_count'],
            'last_failure_at' => $decoded['last_failure_at'],
        ]);
    }

    /**
     * @param array{failure_count: int, last_failure_at: int} $state
     */
    private function writeState(array $state): void
    {
        $this->assertOwnership();
        $this->assertRuntimeDirectory();
        $current = $this->lstatPath($this->statePath, 'Unable to inspect scanner recovery state');
        if (is_array($current) && !$this->isRegularFileMetadata($current)) {
            throw new RuntimeException('Scanner recovery state is unsafe');
        }
        if (is_array($current) && !$this->metadataIsOwnedByRuntime($current)) {
            throw new RuntimeException('Scanner recovery state has an unsafe owner');
        }

        $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temporaryPath = $this->statePath . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(8));
        $handle = null;
        try {
            $handle = $this->filesystemCall(
                static fn (): mixed => fopen($temporaryPath, 'x+b'),
                'Unable to create scanner recovery state temporary file'
            );
            if (!is_resource($handle)) {
                throw new RuntimeException('Unable to create scanner recovery state temporary file');
            }
            $this->assertRuntimeOwnerOwns($handle, 'Scanner recovery state temporary file has an unsafe owner');
            $this->normalizeRegularFileMode(
                $temporaryPath,
                $handle,
                'Unable to set scanner recovery state permissions'
            );
            $this->writeAll($handle, $contents);
            if (!$this->filesystemCall(
                static fn (): bool => fflush($handle),
                'Unable to flush scanner recovery state'
            )) {
                throw new RuntimeException('Unable to flush scanner recovery state');
            }
            if (!$this->pathIdentifiesHandle($temporaryPath, $handle, 0100000)) {
                throw new RuntimeException('Scanner recovery state temporary file changed before publication');
            }
            $this->assertRuntimeDirectory();

            $current = $this->lstatPath($this->statePath, 'Unable to inspect scanner recovery state');
            if (is_array($current) && !$this->isRegularFileMetadata($current)) {
                throw new RuntimeException('Scanner recovery state is unsafe');
            }
            if (is_array($current) && !$this->metadataIsOwnedByRuntime($current)) {
                throw new RuntimeException('Scanner recovery state has an unsafe owner');
            }
            if (!$this->filesystemCall(
                fn (): bool => rename($temporaryPath, $this->statePath),
                'Unable to publish scanner recovery state'
            )) {
                throw new RuntimeException('Unable to publish scanner recovery state');
            }
            if (!$this->pathIdentifiesHandle($this->statePath, $handle, 0100000)) {
                throw new RuntimeException('Scanner recovery state changed during publication');
            }
            $published = fstat($handle);
            if (!is_array($published) || ($published['mode'] & 07777) !== 0640) {
                throw new RuntimeException('Published scanner recovery state permissions are unsafe');
            }
            $this->assertRuntimeOwnerOwns($handle, 'Published scanner recovery state has an unsafe owner');
            $this->assertRuntimeDirectory();
            fclose($handle);
            $handle = null;
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                try {
                    $temporaryPathIsRetained = $this->pathIdentifiesHandle(
                        $temporaryPath,
                        $handle,
                        0100000
                    );
                } catch (Throwable) {
                    $temporaryPathIsRetained = false;
                }
                if ($temporaryPathIsRetained) {
                    try {
                        $this->filesystemCall(
                            static fn (): bool => unlink($temporaryPath),
                            'Unable to remove scanner recovery state temporary file'
                        );
                    } catch (Throwable) {
                    }
                }
                fclose($handle);
            }
            throw $exception;
        }
    }

    /** @param resource $handle */
    private function writeAll(mixed $handle, string $contents): void
    {
        $remaining = $contents;
        while ($remaining !== '') {
            $written = $this->filesystemCall(
                static fn (): int|false => fwrite($handle, $remaining),
                'Unable to write scanner recovery state'
            );
            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write scanner recovery state');
            }
            $remaining = substr($remaining, $written);
        }
    }

    /**
     * @param array{failure_count: int, last_failure_at: int} $state
     * @return array{failure_count: int, last_failure_at: int}
     */
    private function setLoadedState(array $state): array
    {
        $this->state = $state;
        $this->stateLoaded = true;

        return $state;
    }

    /** @return array{failure_count: int, last_failure_at: int} */
    private function setInvalidState(): array
    {
        $this->stateErrorCode = 'scanner_state_invalid';

        return $this->setLoadedState([
            'failure_count' => 1,
            'last_failure_at' => $this->now(),
        ]);
    }

    /**
     * @param array{failure_count: int, last_failure_at: int} $state
     */
    private function isRecentSeries(array $state, int $now): bool
    {
        $age = $now - $state['last_failure_at'];

        return $state['failure_count'] > 0
            && $age >= 0
            && $age <= self::RECYCLE_SERIES_TTL_SECONDS;
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if (!is_int($now)) {
            throw new RuntimeException('Scanner process clock must return an integer');
        }

        return $now;
    }

    private function assertRuntimeDirectory(): void
    {
        if (!is_resource($this->runtimeHandle)) {
            throw new RuntimeException('Scanner process runtime directory is not retained');
        }
        $this->assertCanonicalRuntimePath();
        if (!$this->pathIdentifiesHandle($this->runtimeDirectory, $this->runtimeHandle, 0040000)) {
            throw new RuntimeException('Scanner process runtime directory changed while owned');
        }
        $this->assertCurrentUserOwns(
            $this->runtimeHandle,
            'Scanner process runtime directory has an unsafe owner'
        );
        $metadata = fstat($this->runtimeHandle);
        if (!is_array($metadata) || ($metadata['mode'] & 07777) !== 0750) {
            throw new RuntimeException('Scanner process runtime directory permissions changed while owned');
        }
    }

    /** @param resource $handle */
    private function normalizeRegularFileMode(string $path, mixed $handle, string $errorMessage): void
    {
        $this->assertRuntimeDirectory();
        if (!$this->pathIdentifiesHandle($path, $handle, 0100000)) {
            throw new RuntimeException($errorMessage);
        }
        $this->assertRuntimeOwnerOwns($handle, $errorMessage);
        if (!$this->filesystemCall(
            static fn (): bool => chmod($path, 0640),
            $errorMessage
        )) {
            throw new RuntimeException($errorMessage);
        }
        if (!$this->pathIdentifiesHandle($path, $handle, 0100000)) {
            throw new RuntimeException($errorMessage);
        }
        $metadata = fstat($handle);
        if (!is_array($metadata) || ($metadata['mode'] & 07777) !== 0640) {
            throw new RuntimeException($errorMessage);
        }
        $this->assertRuntimeOwnerOwns($handle, $errorMessage);
        $this->assertRuntimeDirectory();
    }

    /** @param resource $handle */
    private function assertRuntimeOwnerOwns(mixed $handle, string $errorMessage): void
    {
        $metadata = fstat($handle);
        $runtimeMetadata = is_resource($this->runtimeHandle) ? fstat($this->runtimeHandle) : false;
        if (
            !is_array($metadata)
            || !is_array($runtimeMetadata)
            || $metadata['uid'] !== $runtimeMetadata['uid']
        ) {
            throw new RuntimeException($errorMessage);
        }
    }

    /** @param resource $handle */
    private function assertCurrentUserOwns(mixed $handle, string $errorMessage): void
    {
        if (!function_exists('posix_geteuid')) {
            return;
        }
        $metadata = fstat($handle);
        if (!is_array($metadata) || $metadata['uid'] !== posix_geteuid()) {
            throw new RuntimeException($errorMessage);
        }
    }

    /** @param array<int|string, int> $metadata */
    private function metadataIsOwnedByRuntime(array $metadata): bool
    {
        $runtimeMetadata = is_resource($this->runtimeHandle) ? fstat($this->runtimeHandle) : false;

        return is_array($runtimeMetadata) && $metadata['uid'] === $runtimeMetadata['uid'];
    }

    /** @param resource $handle */
    private function pathIdentifiesHandle(string $path, mixed $handle, int $expectedType): bool
    {
        $opened = fstat($handle);
        $current = $this->lstatPath($path, 'Unable to inspect scanner process path');

        return is_array($opened)
            && is_array($current)
            && ($opened['mode'] & 0170000) === $expectedType
            && ($current['mode'] & 0170000) === $expectedType
            && $opened['dev'] === $current['dev']
            && $opened['ino'] === $current['ino'];
    }

    /** @param array<int|string, int> $metadata */
    private function isRegularFileMetadata(array $metadata): bool
    {
        return ($metadata['mode'] & 0170000) === 0100000;
    }

    /** @param array<int|string, int> $metadata */
    private function isDirectoryMetadata(array $metadata): bool
    {
        return ($metadata['mode'] & 0170000) === 0040000;
    }

    private function assertCanonicalRuntimePath(): void
    {
        $canonicalPath = $this->filesystemCall(
            fn (): string|false => realpath($this->runtimeDirectory),
            'Unable to resolve scanner process runtime directory'
        );
        if (
            $canonicalPath === false
            || $this->canonicalRuntimePath === null
            || $canonicalPath !== $this->canonicalRuntimePath
        ) {
            throw new RuntimeException('Scanner process runtime directory pathname is unsafe');
        }
    }

    private function retainCanonicalRuntimePath(): void
    {
        $canonicalPath = $this->filesystemCall(
            fn (): string|false => realpath($this->runtimeDirectory),
            'Unable to resolve scanner process runtime directory'
        );
        if ($canonicalPath === false) {
            throw new RuntimeException('Scanner process runtime directory pathname is unsafe');
        }
        $this->canonicalRuntimePath = $canonicalPath;
    }

    /** @return array<int|string, int>|false */
    private function lstatPath(string $path, string $errorMessage): array|false
    {
        clearstatcache(true, $path);
        if (!$this->pathExistsOrLink($path, $errorMessage)) {
            return false;
        }

        $metadata = $this->filesystemCall(
            static fn (): array|false => lstat($path),
            $errorMessage
        );

        return is_array($metadata) ? $metadata : false;
    }

    private function pathExistsOrLink(string $path, string $errorMessage): bool
    {
        $exists = $this->filesystemCall(
            static fn (): bool => file_exists($path),
            $errorMessage
        );
        if ($exists) {
            return true;
        }

        return $this->filesystemCall(
            static fn (): bool => is_link($path),
            $errorMessage
        );
    }

    private function filesystemCall(callable $operation, string $errorMessage): mixed
    {
        $warningRaised = false;
        set_error_handler(
            static function () use (&$warningRaised): bool {
                $warningRaised = true;
                return true;
            }
        );
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }
        if ($warningRaised) {
            throw new RuntimeException($errorMessage);
        }

        return $result;
    }
}
