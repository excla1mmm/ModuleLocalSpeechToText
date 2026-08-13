<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use RuntimeException;
use Throwable;

final class LocalSpeechToTextLogWriter
{
    private const string ACTIVE_FILE = 'module.log';
    private const string PREVIOUS_FILE = 'module.log.1';
    private const string LOCK_FILE = '.module-log.lock';

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes = 5_242_880
    ) {
        if ($this->maxBytes < 1) {
            throw new RuntimeException('Module log byte limit must be positive');
        }
    }

    public function append(string $jsonLine): bool
    {
        try {
            return $this->appendInternal($jsonLine);
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
                if (is_array($previousStat) && !@unlink($previousPath)) {
                    return false;
                }
                if (!$this->isSafeJournalPath($activePath, $realDirectory)
                    || !@rename($activePath, $previousPath)
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
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int, string> $jsonLines */
    public function mergePrevious(array $jsonLines): bool
    {
        try {
            return $this->replacePreviousInternal($jsonLines, true);
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
                @unlink($temporaryPath);
                return false;
            }

            if (!$this->applyJournalPermissions($temporaryPath, $realDirectory)) {
                @unlink($temporaryPath);
                return false;
            }
            if (!$this->isSafeJournalPath($temporaryPath, $realDirectory)
                || !$this->isSafeJournalPath($previousPath, $realDirectory)
                || !@rename($temporaryPath, $previousPath)
            ) {
                @unlink($temporaryPath);
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
        $realDirectory = $this->ensureDirectory();
        if ($realDirectory === null) {
            return false;
        }

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
    }

    private function ensureDirectory(): ?string
    {
        $directory = rtrim($this->directory, '/');
        if ($directory === '' || is_link($directory)) {
            return null;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            return null;
        }
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if (is_link($directory)
            || !is_array($stat)
            || (($stat['mode'] & 0170000) !== 0040000)
        ) {
            return null;
        }

        $realDirectory = realpath($directory);
        if (!is_string($realDirectory) || !is_dir($realDirectory)) {
            return null;
        }
        if (!chmod($realDirectory, 0750)) {
            return null;
        }
        clearstatcache(true, $realDirectory);
        $updatedStat = @lstat($realDirectory);
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if (!is_array($updatedStat)
            || (($updatedStat['mode'] & 0170000) !== 0040000)
            || (($updatedStat['mode'] & 0022) !== 0)
            || ($effectiveUid !== null && (int)$updatedStat['uid'] !== $effectiveUid)
        ) {
            return null;
        }

        return rtrim($realDirectory, '/');
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
