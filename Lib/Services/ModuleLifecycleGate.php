<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use RuntimeException;
use Throwable;

final class ModuleLifecycleGate
{
    public const string STATE_OPEN = 'open';
    public const string STATE_DRAINING = 'draining';
    public const string STATE_DISABLED = 'disabled';

    private const string MARKER_FILE = '.module-lifecycle-state-v2.json';
    private const string LOCK_FILE = '.module-lifecycle-v2.lock';
    private const int RETRY_MICROSECONDS = 10_000;
    private const int STALE_DRAIN_MINIMUM_AGE_SECONDS = 1;
    private const int STALE_DRAIN_MAXIMUM_AGE_SECONDS = 120;
    private const int COMPLETION_GRACE_MILLISECONDS = 5000;

    private string $markerPath;
    private string $lockPath;
    private string $ownerToken;
    private bool $available = false;
    private mixed $exclusiveLease = null;

    public function __construct(?string $runtimeDirectory = null, bool $bootstrap = false)
    {
        $runtimeDirectory ??= dirname(__DIR__, 2) . '/db';
        $runtimeDirectory = rtrim($runtimeDirectory, DIRECTORY_SEPARATOR);
        if ($runtimeDirectory === '') {
            throw new RuntimeException('Module lifecycle runtime directory is empty');
        }

        $this->markerPath = $runtimeDirectory . DIRECTORY_SEPARATOR . self::MARKER_FILE;
        $this->lockPath = $runtimeDirectory . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        $this->ownerToken = bin2hex(random_bytes(16));

        if ($bootstrap && !is_dir($runtimeDirectory)) {
            if (!mkdir($runtimeDirectory, 0755, true) && !is_dir($runtimeDirectory)) {
                throw new RuntimeException('Unable to create module lifecycle runtime directory');
            }
        }
        if ($bootstrap && !$this->regularFileExists($this->lockPath)) {
            $lock = fopen($this->lockPath, 'x+b');
            if ($lock === false && !$this->regularFileExists($this->lockPath)) {
                throw new RuntimeException('Unable to create module lifecycle lock');
            }
            if (is_resource($lock)) {
                fclose($lock);
                if (!chmod($this->lockPath, 0664)) {
                    throw new RuntimeException('Unable to set module lifecycle lock permissions');
                }
            }
        }

        $this->available = $this->regularFileExists($this->lockPath);
    }

    public function __destruct()
    {
        try {
            $this->abortOwnedDrain();
        } catch (Throwable) {
            $this->releaseExclusiveLease();
        }
    }

    public function currentState(): string
    {
        if (!$this->available || !file_exists($this->markerPath)) {
            return $this->available ? self::STATE_OPEN : self::STATE_DISABLED;
        }

        $this->recoverStaleDrain();
        if (!file_exists($this->markerPath)) {
            return self::STATE_OPEN;
        }

        $marker = $this->readMarker();
        $state = (string)($marker['state'] ?? '');

        return in_array($state, [self::STATE_DRAINING, self::STATE_DISABLED], true)
            ? $state
            : self::STATE_DISABLED;
    }

    /**
     * @return resource|null
     */
    public function acquireWriteLease(bool $allowDuringDrain = false): mixed
    {
        if (!$this->available || !$this->stateAllowsLease($this->currentState(), $allowDuringDrain)) {
            return null;
        }

        $lease = $this->openLock();
        if (!flock($lease, LOCK_SH | LOCK_NB)) {
            fclose($lease);
            return null;
        }
        if (!$this->stateAllowsLease($this->currentState(), $allowDuringDrain)) {
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

    public function beginDrain(
        int $timeoutMilliseconds,
        int $completionGraceMilliseconds = self::COMPLETION_GRACE_MILLISECONDS
    ): bool
    {
        if (is_resource($this->exclusiveLease)) {
            return true;
        }
        if (!$this->available) {
            return false;
        }

        $this->writeMarker([
            'state' => self::STATE_DRAINING,
            'owner_pid' => getmypid(),
            'owner_token' => $this->ownerToken,
            'updated_at' => time(),
        ]);

        $lease = $this->openLock();
        $timeoutMilliseconds = max(0, $timeoutMilliseconds);
        $startedAt = hrtime(true);
        $deadline = $startedAt + ($timeoutMilliseconds * 1_000_000);
        $graceDeadline = min(
            $deadline,
            $startedAt + (max(0, $completionGraceMilliseconds) * 1_000_000)
        );
        while (hrtime(true) < $graceDeadline) {
            $remainingMicroseconds = max(1, (int)(($graceDeadline - hrtime(true)) / 1000));
            usleep(min(self::RETRY_MICROSECONDS, $remainingMicroseconds));
        }
        do {
            if (flock($lease, LOCK_EX | LOCK_NB)) {
                $this->exclusiveLease = $lease;
                return true;
            }
            if (hrtime(true) >= $deadline) {
                break;
            }
            usleep(self::RETRY_MICROSECONDS);
        } while (true);

        fclose($lease);
        $this->clearOwnedDrainingMarker();
        return false;
    }

    public function completeDisable(): void
    {
        if (!is_resource($this->exclusiveLease)) {
            throw new RuntimeException('Module lifecycle drain was not acquired');
        }
        $this->writeMarker([
            'state' => self::STATE_DISABLED,
            'updated_at' => time(),
        ]);
        $this->releaseExclusiveLease();
    }

    public function completeEnable(): void
    {
        if (file_exists($this->markerPath) && !unlink($this->markerPath)) {
            throw new RuntimeException('Unable to clear module lifecycle marker');
        }
        $this->releaseExclusiveLease();
    }

    public function abortOwnedDrain(): void
    {
        $this->clearOwnedDrainingMarker();
        $this->releaseExclusiveLease();
    }

    /**
     * @return array<string, mixed>
     */
    private function readMarker(): array
    {
        if (!$this->regularFileExists($this->markerPath)) {
            return [];
        }
        $contents = file_get_contents($this->markerPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read module lifecycle marker');
        }
        try {
            $marker = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid module lifecycle marker', 0, $exception);
        }

        return is_array($marker) ? $marker : [];
    }

    /**
     * @param array<string, int|string> $marker
     */
    private function writeMarker(array $marker): void
    {
        $temporaryPath = $this->markerPath . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $contents = json_encode($marker, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write module lifecycle marker');
        }
        if (!chmod($temporaryPath, 0664)) {
            unlink($temporaryPath);
            throw new RuntimeException('Unable to set module lifecycle marker permissions');
        }
        if (!rename($temporaryPath, $this->markerPath)) {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw new RuntimeException('Unable to publish module lifecycle marker');
        }
    }

    private function clearOwnedDrainingMarker(): void
    {
        if (!$this->regularFileExists($this->markerPath)) {
            return;
        }
        $marker = $this->readMarker();
        if (
            ($marker['state'] ?? null) === self::STATE_DRAINING
            && (int)($marker['owner_pid'] ?? 0) === getmypid()
            && hash_equals((string)($marker['owner_token'] ?? ''), $this->ownerToken)
        ) {
            if (!unlink($this->markerPath)) {
                throw new RuntimeException('Unable to clear owned module lifecycle marker');
            }
        }
    }

    private function recoverStaleDrain(): void
    {
        if (!$this->regularFileExists($this->markerPath)) {
            return;
        }
        $candidate = $this->readMarker();
        if (!$this->isStaleDrainMarker($candidate)) {
            return;
        }

        $lease = $this->openLock();
        if (!flock($lease, LOCK_EX | LOCK_NB)) {
            fclose($lease);
            return;
        }

        try {
            if (!$this->regularFileExists($this->markerPath)) {
                return;
            }
            $current = $this->readMarker();
            if (!$this->sameDrainOwner($candidate, $current) || !$this->isStaleDrainMarker($current)) {
                return;
            }
            if (!unlink($this->markerPath)) {
                throw new RuntimeException('Unable to recover stale module lifecycle drain');
            }
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    /** @param array<string, mixed> $marker */
    private function isStaleDrainMarker(array $marker): bool
    {
        if (($marker['state'] ?? null) !== self::STATE_DRAINING) {
            return false;
        }
        $ownerPid = (int)($marker['owner_pid'] ?? 0);
        $updatedAt = (int)($marker['updated_at'] ?? 0);
        if ($ownerPid <= 0 || $updatedAt <= 0) {
            return false;
        }
        if ((time() - $updatedAt) < self::STALE_DRAIN_MINIMUM_AGE_SECONDS) {
            return false;
        }

        $age = time() - $updatedAt;
        if ($age >= self::STALE_DRAIN_MAXIMUM_AGE_SECONDS) {
            return true;
        }

        return !$this->processIsAlive($ownerPid);
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     */
    private function sameDrainOwner(array $candidate, array $current): bool
    {
        $candidateToken = (string)($candidate['owner_token'] ?? '');
        $currentToken = (string)($current['owner_token'] ?? '');

        return $candidateToken !== ''
            && (int)($candidate['owner_pid'] ?? 0) === (int)($current['owner_pid'] ?? 0)
            && hash_equals($candidateToken, $currentToken);
    }

    private function processIsAlive(int $processId): bool
    {
        if (!function_exists('posix_kill')) {
            return true;
        }
        if (posix_kill($processId, 0)) {
            return true;
        }

        return function_exists('posix_get_last_error') && posix_get_last_error() !== 3;
    }

    private function stateAllowsLease(string $state, bool $allowDuringDrain): bool
    {
        return $state === self::STATE_OPEN
            || ($allowDuringDrain && $state === self::STATE_DRAINING);
    }

    /** @return resource */
    private function openLock(): mixed
    {
        if (!$this->regularFileExists($this->lockPath)) {
            throw new RuntimeException('Module lifecycle lock is unavailable');
        }
        $lock = fopen($this->lockPath, 'r+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to open module lifecycle lock');
        }

        return $lock;
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

    private function regularFileExists(string $path): bool
    {
        return is_file($path) && !is_link($path);
    }
}
