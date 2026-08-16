<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/LocalSpeechToTextJournalAccess.php';

final class LocalSpeechToTextLogWriter
{
    private const string ACTIVE_FILE = 'module.log';
    private const string PREVIOUS_FILE = 'module.log.1';
    private const string LOCK_FILE = '.module-log.lock';
    private const int ROOT_UID = 0;

    private readonly int $maxBytes;
    private readonly LocalSpeechToTextJournalAccess $access;

    public function __construct(
        string $directory,
        int $maxBytes = 5_242_880,
        ?callable $effectiveUidProvider = null,
        ?callable $ownerFileCreator = null
    ) {
        $this->maxBytes = $maxBytes;
        $this->access = new LocalSpeechToTextJournalAccess($directory, $effectiveUidProvider, $ownerFileCreator);
        if ($this->maxBytes < 1) {
            throw new RuntimeException('Module log byte limit must be positive');
        }
    }

    public function append(string $jsonLine): bool
    {
        try {
            return $this->appendInternal($jsonLine);
        } catch (LocalSpeechToTextJournalPrivilegeException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    private function appendInternal(string $jsonLine): bool
    {
        $line = rtrim($jsonLine, "\r\n") . "\n";
        if (strlen($line) > $this->maxBytes) {
            return false;
        }

        return $this->withLock(function (string $realDirectory) use ($line): bool {
            $activePath = $realDirectory . '/' . self::ACTIVE_FILE;
            $previousPath = $realDirectory . '/' . self::PREVIOUS_FILE;
            if (!$this->isSafeJournalPath($activePath, $realDirectory)
                || !$this->isSafeJournalPath($previousPath, $realDirectory)
            ) {
                return false;
            }

            $activeStat = $this->regularFileStat($activePath, $realDirectory, allowMissing: true);
            if ($activeStat === false) {
                return false;
            }
            if (is_array($activeStat) && !$this->applyJournalPermissions($activePath, $realDirectory)) {
                return false;
            }
            $activeSize = is_array($activeStat) ? (int)$activeStat['size'] : 0;
            if ($activeSize > 0 && $activeSize + strlen($line) > $this->maxBytes) {
                $previousStat = $this->regularFileStat($previousPath, $realDirectory, allowMissing: true);
                if ($previousStat === false) {
                    return false;
                }
                if (is_array($previousStat)
                    && !$this->mutateJournalPath(fn (): bool => @unlink($previousPath))
                ) {
                    return false;
                }
                if (!$this->isSafeJournalPath($activePath, $realDirectory)
                    || !$this->mutateJournalPath(fn (): bool => @rename($activePath, $previousPath))
                    || !$this->isSafeJournalPath($previousPath, $realDirectory)
                ) {
                    return false;
                }
                if (!$this->applyJournalPermissions($previousPath, $realDirectory)) {
                    return false;
                }
            }

            $handle = $this->openRegularFile($activePath, 'ab', $realDirectory);
            if (!is_resource($handle)) {
                return false;
            }

            $written = false;
            try {
                $written = $this->writeAll($handle, $line) && fflush($handle);
                if ($written && function_exists('fsync')) {
                    $written = fsync($handle);
                }
            } finally {
                $closed = fclose($handle);
                $written = $written && $closed;
            }

            return $written;
        });
    }

    /** @param array<int, string> $jsonLines */
    public function replacePrevious(array $jsonLines): bool
    {
        try {
            return $this->replacePreviousInternal($jsonLines, false);
        } catch (LocalSpeechToTextJournalPrivilegeException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int, string> $jsonLines */
    public function mergePrevious(array $jsonLines): bool
    {
        try {
            return $this->replacePreviousInternal($jsonLines, true);
        } catch (LocalSpeechToTextJournalPrivilegeException $exception) {
            throw $exception;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int, string> $jsonLines */
    private function replacePreviousInternal(array $jsonLines, bool $merge): bool
    {
        return $this->withLock(function (string $realDirectory) use ($jsonLines, $merge): bool {
            $previousPath = $realDirectory . '/' . self::PREVIOUS_FILE;
            if (!$this->isSafeJournalPath($previousPath, $realDirectory)) {
                return false;
            }

            if ($merge && is_file($previousPath)) {
                $handle = $this->openRegularFile($previousPath, 'rb', $realDirectory);
                if (!is_resource($handle)) {
                    return false;
                }
                try {
                    $existingStat = fstat($handle);
                    if (!is_array($existingStat) || (int)$existingStat['size'] > $this->maxBytes) {
                        return false;
                    }
                    $existingContent = stream_get_contents($handle);
                } finally {
                    fclose($handle);
                }
                if (!is_string($existingContent)) {
                    return false;
                }
                $existing = preg_split('/\r?\n/', trim($existingContent));
                $existing = is_array($existing) ? array_values(array_filter(
                    $existing,
                    static fn (string $line): bool => LocalSpeechToTextSyslogParser::parse($line) !== null
                )) : [];
                $merged = $existing;
                $seen = array_fill_keys($existing, true);
                foreach ($jsonLines as $line) {
                    if (!isset($seen[$line])) {
                        $merged[] = $line;
                        $seen[$line] = true;
                    }
                }
                $jsonLines = $merged;
                usort($jsonLines, static function (string $left, string $right): int {
                    $leftEntry = LocalSpeechToTextSyslogParser::parse($left);
                    $rightEntry = LocalSpeechToTextSyslogParser::parse($right);

                    return strcmp((string)($leftEntry['timestamp'] ?? ''), (string)($rightEntry['timestamp'] ?? ''));
                });
            }

            $content = $this->boundedContent($jsonLines);

            try {
                $suffix = bin2hex(random_bytes(6));
            } catch (\Throwable) {
                return false;
            }
            $temporaryPath = $realDirectory . '/.module-log.previous.tmp.' . getmypid() . '.' . $suffix;
            $handle = $this->openRegularFile($temporaryPath, 'x+b', $realDirectory);
            if (!is_resource($handle)) {
                return false;
            }

            $written = false;
            try {
                $written = $this->writeAll($handle, $content) && fflush($handle);
                if ($written && function_exists('fsync')) {
                    $written = fsync($handle);
                }
            } finally {
                $closed = fclose($handle);
                $written = $written && $closed;
            }
            if (!$written) {
                $this->discardTemporaryFile($temporaryPath);
                return false;
            }

            if (!$this->applyJournalPermissions($temporaryPath, $realDirectory)) {
                $this->discardTemporaryFile($temporaryPath);
                return false;
            }
            if (!$this->isSafeJournalPath($temporaryPath, $realDirectory)
                || !$this->isSafeJournalPath($previousPath, $realDirectory)
                || !$this->mutateJournalPath(fn (): bool => @rename($temporaryPath, $previousPath))
            ) {
                $this->discardTemporaryFile($temporaryPath);
                return false;
            }

            $stat = $this->regularFileStat($previousPath, $realDirectory);
            if (!is_array($stat) || (int)$stat['size'] > $this->maxBytes) {
                return false;
            }
            if (!$this->applyJournalPermissions($previousPath, $realDirectory)) {
                return false;
            }

            return true;
        });
    }

    /** @param array<int, string> $lines */
    private function boundedContent(array $lines): string
    {
        $bounded = [];
        $bytes = 0;
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = rtrim((string)$lines[$index], "\r\n") . "\n";
            $lineBytes = strlen($line);
            if ($lineBytes > $this->maxBytes || $bytes + $lineBytes > $this->maxBytes) {
                continue;
            }
            array_unshift($bounded, $line);
            $bytes += $lineBytes;
        }

        return implode('', $bounded);
    }

    /** @param callable(string): bool $callback */
    private function withLock(callable $callback): bool
    {
        $access = $this->access->resolveDirectoryAccess();
        if ($access === null) {
            return false;
        }

        try {
            $realDirectory = $access['realDirectory'];
            $lockPath = $realDirectory . '/' . self::LOCK_FILE;
            $lock = $this->openRegularFile($lockPath, 'c+b', $realDirectory);
            if (!is_resource($lock)) {
                return false;
            }

            $locked = false;
            try {
                $locked = flock($lock, LOCK_EX);
                if (!$locked) {
                    return false;
                }

                return $callback($realDirectory);
            } finally {
                if ($locked) {
                    flock($lock, LOCK_UN);
                }
                fclose($lock);
            }
        } finally {
            $this->access->clear();
        }
    }

    private function isSafeJournalPath(string $path, string $realDirectory): bool
    {
        return $this->regularFileStat($path, $realDirectory, allowMissing: true) !== false;
    }

    /** @return array<string|int, mixed>|null|false */
    private function regularFileStat(string $path, string $realDirectory, bool $allowMissing = false): array|null|false
    {
        if (dirname($path) !== $realDirectory) {
            return false;
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return $allowMissing && !file_exists($path) && !is_link($path) ? null : false;
        }

        return (($stat['mode'] & 0170000) === 0100000) && !is_link($path) ? $stat : false;
    }

    /** @return resource|false */
    private function openRegularFile(string $path, string $mode, string $realDirectory)
    {
        if (dirname($path) !== $realDirectory || is_link($path)) {
            return false;
        }

        if ($this->access->isGuestMode()) {
            return $this->openGuestRegularFile($path, $mode, $realDirectory);
        }

        $handle = fopen($path, $mode);
        if (!is_resource($handle)) {
            return false;
        }

        $handleStat = fstat($handle);
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
        $safe = is_array($handleStat)
            && is_array($pathStat)
            && (($handleStat['mode'] & 0170000) === 0100000)
            && (($pathStat['mode'] & 0170000) === 0100000)
            && (int)$handleStat['dev'] === (int)$pathStat['dev']
            && (int)$handleStat['ino'] === (int)$pathStat['ino']
            && !is_link($path);
        if (!$safe) {
            fclose($handle);
            return false;
        }
        if (!$this->applyJournalPermissions($path, $realDirectory)) {
            fclose($handle);
            return false;
        }

        return $handle;
    }

    /**
     * Guest opens never use creating modes: existing entries are opened with
     * "r+b"/"rb" only, so root cannot create a file by accident even if the
     * entry vanishes between lstat and fopen. Missing entries are created
     * exclusively through the owner window.
     *
     * @return resource|false
     */
    private function openGuestRegularFile(string $path, string $mode, string $realDirectory): mixed
    {
        clearstatcache(true, $path);
        $preStat = @lstat($path);

        if (is_array($preStat)) {
            if (($preStat['mode'] & 0170000) !== 0100000
                || str_contains($mode, 'x')
            ) {
                return false;
            }

            $handle = fopen($path, str_contains($mode, 'r') && !str_contains($mode, '+') ? 'rb' : 'r+b');
            if (!is_resource($handle)) {
                return false;
            }
            if (str_contains($mode, 'a') && fseek($handle, 0, SEEK_END) !== 0) {
                fclose($handle);
                return false;
            }
            $handleStat = fstat($handle);
            if (!is_array($handleStat)
                || !$this->access->statMatchesPath($handleStat, $path)
                || (int)$handleStat['uid'] !== (int)$this->access->canonicalUid()
                || !$this->access->guestModeIsSafe((int)$handleStat['mode'])
                || !$this->applyJournalPermissions($path, $realDirectory)
            ) {
                fclose($handle);
                return false;
            }

            return $handle;
        }

        if (!LocalSpeechToTextJournalAccess::modeCreates($mode)) {
            return false;
        }

        $handle = $this->access->createFileAsOwner($path, $mode);
        if (!is_resource($handle)) {
            return false;
        }
        $handleStat = fstat($handle);
        if (!is_array($handleStat)
            || (int)$handleStat['uid'] !== (int)$this->access->canonicalUid()
            || !$this->access->statMatchesPath($handleStat, $path)
            || !$this->access->guestModeIsSafe((int)$handleStat['mode'])
            || !$this->applyJournalPermissions($path, $realDirectory)
        ) {
            fclose($handle);

            return false;
        }

        return $handle;
    }

    private function applyJournalPermissions(string $path, string $realDirectory): bool
    {
        if (dirname($path) !== $realDirectory || is_link($path)) {
            return false;
        }

        clearstatcache(true, $realDirectory);
        clearstatcache(true, $path);
        $directoryStat = @lstat($realDirectory);
        $pathStat = @lstat($path);
        if (!is_array($directoryStat)
            || !is_array($pathStat)
            || (($directoryStat['mode'] & 0170000) !== 0040000)
            || (($pathStat['mode'] & 0170000) !== 0100000)
        ) {
            return false;
        }

        if ($this->access->isGuestMode()) {
            return (int)$pathStat['uid'] === (int)$this->access->canonicalUid()
                && (int)$pathStat['gid'] === (int)$directoryStat['gid']
                && $this->access->guestModeIsSafe((int)$pathStat['mode']);
        }

        $directoryGroup = (int)$directoryStat['gid'];
        if ((int)$pathStat['gid'] !== $directoryGroup && !chgrp($path, $directoryGroup)) {
            return false;
        }

        clearstatcache(true, $path);
        $updatedPathStat = @lstat($path);
        return is_array($updatedPathStat)
            && (($updatedPathStat['mode'] & 0170000) === 0100000)
            && (int)$updatedPathStat['gid'] === $directoryGroup
            && chmod($path, 0640);
    }

    private function discardTemporaryFile(string $temporaryPath): void
    {
        $this->mutateJournalPath(
            fn (): bool => !is_file($temporaryPath) || @unlink($temporaryPath)
        );
    }

    /**
     * Every path mutation inside the journal tree goes through the owner
     * identity in guest mode, so a substituted path can never make root act.
     */
    private function mutateJournalPath(callable $operation): bool
    {
        if (!$this->access->isGuestMode()) {
            return $operation();
        }

        return $this->access->mutateAsOwner($operation) === true;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $content): bool
    {
        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }
}
