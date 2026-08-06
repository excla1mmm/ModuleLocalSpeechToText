<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use MikoPBX\Core\System\Directories;
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
    private mixed $lockHandle = null;
    /** @var array{failure_count: int, last_failure_at: int}|null */
    private ?array $state = null;
    private bool $stateLoaded = false;
    private ?string $stateErrorCode = null;

    public function __construct(?string $runtimeDirectory = null, mixed $clock = null)
    {
        $runtimeDirectory ??= rtrim(
            Directories::getDir(Directories::CORE_TEMP_DIR),
            DIRECTORY_SEPARATOR
        ) . '/ModuleLocalSpeechToText/scanner';
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
            return true;
        }

        $this->ensureRuntimeDirectory();
        [$handle, $created] = $this->openLockFile();

        if ($created) {
            if (!chmod($this->lockPath, 0640) || !$this->pathIdentifiesHandle($this->lockPath, $handle)) {
                fclose($handle);
                throw new RuntimeException('Unable to set scanner process lock permissions');
            }
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        if (!$this->pathIdentifiesHandle($this->lockPath, $handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Scanner process lock changed during acquisition');
        }

        $this->lockHandle = $handle;
        $this->state = null;
        $this->stateLoaded = false;
        $this->stateErrorCode = null;

        return true;
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

    public function release(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
        $this->state = null;
        $this->stateLoaded = false;
        $this->stateErrorCode = null;
    }

    private function ensureRuntimeDirectory(): void
    {
        if ($this->runtimeDirectory === '') {
            throw new RuntimeException('Scanner process runtime directory is empty');
        }

        clearstatcache(true, $this->runtimeDirectory);
        $metadata = @lstat($this->runtimeDirectory);
        $created = false;
        if ($metadata === false) {
            if (!@mkdir($this->runtimeDirectory, 0750, true)) {
                clearstatcache(true, $this->runtimeDirectory);
                if (@lstat($this->runtimeDirectory) === false) {
                    throw new RuntimeException('Unable to create scanner process runtime directory');
                }
            } else {
                $created = true;
            }
            clearstatcache(true, $this->runtimeDirectory);
            $metadata = @lstat($this->runtimeDirectory);
        }
        if (!is_array($metadata) || !$this->isDirectoryMetadata($metadata)) {
            throw new RuntimeException('Scanner process runtime directory is unsafe');
        }
        if ($created && !chmod($this->runtimeDirectory, 0750)) {
            throw new RuntimeException('Unable to set scanner process runtime directory permissions');
        }
    }

    /**
     * @return array{0: resource, 1: bool}
     */
    private function openLockFile(): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            clearstatcache(true, $this->lockPath);
            $metadata = @lstat($this->lockPath);
            $created = false;
            if ($metadata === false) {
                $handle = fopen($this->lockPath, 'x+b');
                $created = is_resource($handle);
            } else {
                if (!$this->isRegularFileMetadata($metadata)) {
                    throw new RuntimeException('Scanner process lock is unsafe');
                }
                $handle = fopen($this->lockPath, 'r+b');
            }

            if (!is_resource($handle)) {
                continue;
            }
            if ($this->pathIdentifiesHandle($this->lockPath, $handle)) {
                return [$handle, $created];
            }

            fclose($handle);
            clearstatcache(true, $this->lockPath);
            $current = @lstat($this->lockPath);
            if (is_array($current) && !$this->isRegularFileMetadata($current)) {
                throw new RuntimeException('Scanner process lock is unsafe');
            }
        }

        throw new RuntimeException('Unable to open scanner process lock');
    }

    /** @return array{failure_count: int, last_failure_at: int} */
    private function loadState(): array
    {
        $this->requireOwnership();
        if ($this->stateLoaded && $this->state !== null) {
            return $this->state;
        }

        $this->stateErrorCode = null;
        clearstatcache(true, $this->statePath);
        $metadata = @lstat($this->statePath);
        if ($metadata === false) {
            return $this->setLoadedState([
                'failure_count' => 0,
                'last_failure_at' => 0,
            ]);
        }
        if (!$this->isRegularFileMetadata($metadata)) {
            return $this->setInvalidState();
        }

        $handle = fopen($this->statePath, 'rb');
        if (!is_resource($handle)) {
            return $this->setInvalidState();
        }
        try {
            if (!$this->pathIdentifiesHandle($this->statePath, $handle)) {
                return $this->setInvalidState();
            }
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                return $this->setInvalidState();
            }
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
        $this->requireOwnership();
        clearstatcache(true, $this->statePath);
        $current = @lstat($this->statePath);
        if (is_array($current) && !$this->isRegularFileMetadata($current)) {
            throw new RuntimeException('Scanner recovery state is unsafe');
        }

        $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temporaryPath = $this->statePath . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(8));
        $handle = null;
        try {
            $handle = fopen($temporaryPath, 'x+b');
            if (!is_resource($handle)) {
                throw new RuntimeException('Unable to create scanner recovery state temporary file');
            }
            if (
                !$this->pathIdentifiesHandle($temporaryPath, $handle)
                || !chmod($temporaryPath, 0640)
                || !$this->pathIdentifiesHandle($temporaryPath, $handle)
            ) {
                throw new RuntimeException('Unable to set scanner recovery state permissions');
            }
            $this->writeAll($handle, $contents);
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush scanner recovery state');
            }
            fclose($handle);
            $handle = null;

            clearstatcache(true, $this->statePath);
            $current = @lstat($this->statePath);
            if (is_array($current) && !$this->isRegularFileMetadata($current)) {
                throw new RuntimeException('Scanner recovery state is unsafe');
            }
            if (!rename($temporaryPath, $this->statePath)) {
                throw new RuntimeException('Unable to publish scanner recovery state');
            }
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            clearstatcache(true, $temporaryPath);
            if (@lstat($temporaryPath) !== false) {
                @unlink($temporaryPath);
            }
            throw $exception;
        }
    }

    /** @param resource $handle */
    private function writeAll(mixed $handle, string $contents): void
    {
        $remaining = $contents;
        while ($remaining !== '') {
            $written = fwrite($handle, $remaining);
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

    private function requireOwnership(): void
    {
        if (!is_resource($this->lockHandle)) {
            throw new RuntimeException('Scanner process lock is not owned');
        }
    }

    /** @param resource $handle */
    private function pathIdentifiesHandle(string $path, mixed $handle): bool
    {
        $opened = fstat($handle);
        clearstatcache(true, $path);
        $current = @lstat($path);

        return is_array($opened)
            && is_array($current)
            && $this->isRegularFileMetadata($current)
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
}
