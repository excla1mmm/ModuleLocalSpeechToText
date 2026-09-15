<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Throwable;

final class LocalSpeechToTextJournalFormatMigration
{
    private const int MAX_FILE_BYTES = 5_242_880;
    private const string LOCK_FILE = '.module-log.lock';
    private const string TEMP_FILE = '.module-log.migration.tmp';

    public function __construct(private readonly string $directory)
    {
    }

    /** @return array{converted:int,invalid:int,truncated:int,files:int,failed:int} */
    public function run(): array
    {
        $empty = ['converted' => 0, 'invalid' => 0, 'truncated' => 0, 'files' => 0, 'failed' => 0];
        $realDirectory = $this->validatedDirectory();
        if ($realDirectory === null) {
            if ($this->directory !== '' && (file_exists($this->directory) || is_link($this->directory))) {
                $empty['failed'] = 1;
            }
            return $empty;
        }

        $lockPath = $realDirectory . '/' . self::LOCK_FILE;
        $lock = $this->openRegularFile($lockPath, 'c+b', $realDirectory);
        if (!is_resource($lock)) {
            $empty['failed'] = 1;
            return $empty;
        }

        $locked = false;
        try {
            $locked = flock($lock, LOCK_EX);
            if (!$locked) {
                $empty['failed'] = 1;
                return $empty;
            }

            $summary = $empty;
            foreach (['module.log', 'module.log.1'] as $basename) {
                $result = $this->convertFile($realDirectory, $basename);
                foreach ($summary as $key => $value) {
                    $summary[$key] = $value + $result[$key];
                }
            }

            return $summary;
        } finally {
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    /** @return array{converted:int,invalid:int,truncated:int,files:int,failed:int} */
    private function convertFile(string $realDirectory, string $basename): array
    {
        $empty = ['converted' => 0, 'invalid' => 0, 'truncated' => 0, 'files' => 0, 'failed' => 0];
        $path = $realDirectory . '/' . $basename;
        if (!file_exists($path) && !is_link($path)) {
            return $empty;
        }
        $source = $this->readBoundedRegularFile($path, $realDirectory);
        if ($source === null) {
            $empty['failed'] = 1;
            return $empty;
        }
        $lines = $source['lines'];
        $stat = $source['stat'];

        $output = [];
        $changed = false;
        $converted = 0;
        $invalid = 0;
        foreach ($lines as $index => $line) {
            $line = rtrim((string)$line, "\r\n");
            if ($line === '') {
                $changed = true;
                continue;
            }
            if (LocalSpeechToTextSyslogParser::parse($line) !== null) {
                $output[] = $line;
                continue;
            }

            $legacy = $this->convertLegacyJson($line);
            if ($legacy !== null) {
                $output[] = $legacy;
                $converted++;
                $changed = true;
                continue;
            }

            $output[] = LocalSpeechToTextSyslogFormatter::format(
                'migration',
                'legacy_line_unparsed',
                'Legacy log record could not be parsed',
                LOG_WARNING,
                [
                    'line_hash' => 'sha256:' . hash('sha256', $line),
                    'line_number' => $index + 1,
                    'source' => $basename,
                ],
                (int)$stat['mtime'],
                0
            );
            $invalid++;
            $changed = true;
        }

        if (!$changed) {
            return $empty;
        }

        [$output, $truncated] = $this->newestBoundedLines($output);
        if (!$this->replaceFile($realDirectory, $path, $output)) {
            $empty['failed'] = 1;
            return $empty;
        }

        return [
            'converted' => $converted,
            'invalid' => $invalid,
            'truncated' => $truncated,
            'files' => 1,
            'failed' => 0,
        ];
    }

    private function convertLegacyJson(string $line): ?string
    {
        try {
            $entry = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($entry)) {
            return null;
        }
        foreach (['ts', 'level', 'component', 'event', 'message'] as $key) {
            if (!is_string($entry[$key] ?? null) || trim($entry[$key]) === '') {
                return null;
            }
        }
        $timestamp = strtotime($entry['ts']);
        if (!is_int($timestamp)) {
            return null;
        }
        $severity = match (strtolower($entry['level'])) {
            'debug' => 'debug',
            'notice' => 'notice',
            'warning' => 'warning',
            'error' => 'err',
            default => 'info',
        };

        return LocalSpeechToTextSyslogFormatter::formatSeverity(
            $entry['component'],
            $entry['event'],
            $entry['message'],
            $severity,
            is_array($entry['context'] ?? null) ? $entry['context'] : [],
            $timestamp,
            0
        );
    }

    /** @param array<int, string> $lines @return array{array<int, string>, int} */
    private function newestBoundedLines(array $lines): array
    {
        $bounded = [];
        $bytes = 0;
        $truncated = 0;
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $lineBytes = strlen($lines[$index]) + 1;
            if ($lineBytes > self::MAX_FILE_BYTES || $bytes + $lineBytes > self::MAX_FILE_BYTES) {
                $truncated++;
                continue;
            }
            array_unshift($bounded, $lines[$index]);
            $bytes += $lineBytes;
        }

        return [$bounded, $truncated];
    }

    /** @param array<int, string> $lines */
    private function replaceFile(string $realDirectory, string $path, array $lines): bool
    {
        try {
            $temporaryPath = $realDirectory . '/' . self::TEMP_FILE . '.' . bin2hex(random_bytes(6));
        } catch (Throwable) {
            return false;
        }
        $handle = $this->openRegularFile($temporaryPath, 'x+b', $realDirectory);
        if (!is_resource($handle)) {
            return false;
        }
        $content = $lines === [] ? '' : implode("\n", $lines) . "\n";
        $renamed = false;
        try {
            $written = $this->writeAll($handle, $content) && fflush($handle);
            if ($written && function_exists('fsync')) {
                $written = fsync($handle);
            }
            $temporaryStat = fstat($handle);
            clearstatcache(true, $temporaryPath);
            $pathStat = @lstat($temporaryPath);
            if (!$written
                || !is_array($temporaryStat)
                || !is_array($pathStat)
                || (int)$temporaryStat['dev'] !== (int)$pathStat['dev']
                || (int)$temporaryStat['ino'] !== (int)$pathStat['ino']
                || is_link($temporaryPath)
            ) {
                return false;
            }
            $renamed = QuietOperation::run(static fn (): bool => rename($temporaryPath, $path));
            if (!$renamed) {
                return false;
            }
            clearstatcache(true, $path);
            $targetStat = @lstat($path);

            return is_array($targetStat)
                && (int)$temporaryStat['dev'] === (int)$targetStat['dev']
                && (int)$temporaryStat['ino'] === (int)$targetStat['ino']
                && !is_link($path);
        } finally {
            fclose($handle);
            if (!$renamed) {
                QuietOperation::run(static fn (): bool => unlink($temporaryPath));
            }
        }
    }

    private function validatedDirectory(): ?string
    {
        if ($this->directory === '' || is_link($this->directory) || !is_dir($this->directory)) {
            return null;
        }
        $realDirectory = realpath($this->directory);
        if (!is_string($realDirectory)) {
            return null;
        }
        $realDirectory = rtrim($realDirectory, '/');
        $stat = @lstat($realDirectory);
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        return is_array($stat)
            && (($stat['mode'] & 0170000) === 0040000)
            && (($stat['mode'] & 0022) === 0)
            && ($effectiveUid === null || (int)$stat['uid'] === $effectiveUid)
            ? $realDirectory
            : null;
    }

    /** @return array{lines:array<int,string>,stat:array<string|int,mixed>}|null */
    private function readBoundedRegularFile(string $path, string $realDirectory): ?array
    {
        $handle = $this->openRegularFile($path, 'rb', $realDirectory);
        if (!is_resource($handle)) {
            return null;
        }
        try {
            $before = fstat($handle);
            if (!is_array($before) || (int)$before['size'] > self::MAX_FILE_BYTES) {
                return null;
            }
            $content = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $pathStat = @lstat($path);
            if (!is_string($content)
                || strlen($content) > self::MAX_FILE_BYTES
                || !is_array($after)
                || !is_array($pathStat)
                || (int)$before['dev'] !== (int)$after['dev']
                || (int)$before['ino'] !== (int)$after['ino']
                || (int)$before['size'] !== (int)$after['size']
                || (int)$before['mtime'] !== (int)$after['mtime']
                || (int)$after['dev'] !== (int)$pathStat['dev']
                || (int)$after['ino'] !== (int)$pathStat['ino']
                || is_link($path)
            ) {
                return null;
            }
            $lines = preg_split('/\r?\n/', $content);
            if (is_array($lines) && $lines !== [] && end($lines) === '') {
                array_pop($lines);
            }

            return ['lines' => is_array($lines) ? $lines : [], 'stat' => $after];
        } finally {
            fclose($handle);
        }
    }

    /** @return resource|false */
    private function openRegularFile(string $path, string $mode, string $realDirectory)
    {
        if (dirname($path) !== $realDirectory || is_link($path)) {
            return false;
        }
        $previousUmask = umask(0027);
        try {
            $handle = fopen($path, $mode);
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            return false;
        }
        $handleStat = fstat($handle);
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
        if (!is_array($handleStat)
            || !is_array($pathStat)
            || (($handleStat['mode'] & 0170000) !== 0100000)
            || (($pathStat['mode'] & 0170000) !== 0100000)
            || (int)$handleStat['dev'] !== (int)$pathStat['dev']
            || (int)$handleStat['ino'] !== (int)$pathStat['ino']
            || is_link($path)
        ) {
            fclose($handle);
            return false;
        }

        return $handle;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $content): bool
    {
        $offset = 0;
        $length = strlen($content);
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
