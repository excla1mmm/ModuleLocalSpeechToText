<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Generator;
use MikoPBX\Core\System\Directories;
use Throwable;

final class ModuleLogReader
{
    private const int CHUNK_BYTES = 65_536;
    private const array PERIODS = [0, 3600, 10800, 43200, 86400];
    private const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

    private readonly string $directory;
    private readonly int $maxEntries;

    public function __construct(?string $directory = null, int $maxEntries = 1000)
    {
        $this->directory = rtrim(
            $directory ?? (Directories::getDir(Directories::CORE_LOGS_DIR) . '/ModuleLocalSpeechToText'),
            '/'
        );
        $this->maxEntries = max(1, min(1000, $maxEntries));
    }

    /** @return array<string, mixed> */
    public function recentForPeriod(int $period): array
    {
        if (!in_array($period, self::PERIODS, true)) {
            $period = 3600;
        }

        $realDirectory = $this->validatedDirectory();
        if ($realDirectory === null) {
            return $this->payload([], 0, 0, $period, false, 'unavailable');
        }

        $cutoff = $period === 0 ? null : time() - $period;
        $newestFirst = [];
        $malformedCount = 0;
        $fileCount = 0;
        $presentSources = 0;
        $unsafeSource = false;

        foreach (array_reverse($this->sourcePaths()) as $path) {
            clearstatcache(true, $path);
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            $presentSources++;

            $handle = $this->openValidatedSource($path, $realDirectory);
            if (!is_resource($handle)) {
                $unsafeSource = true;
                continue;
            }
            $fileCount++;

            try {
                foreach ($this->linesNewestFirst($handle) as $line) {
                    if ($line === '') {
                        continue;
                    }
                    $entry = $this->decodeEntry($line);
                    if ($entry === null) {
                        $malformedCount++;
                        continue;
                    }

                    $timestamp = strtotime($entry['timestamp']);
                    if ($cutoff !== null && is_int($timestamp) && $timestamp < $cutoff) {
                        break 2;
                    }
                    $newestFirst[] = $entry;
                    if (count($newestFirst) > $this->maxEntries) {
                        break 2;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        $truncated = count($newestFirst) > $this->maxEntries;
        if ($truncated) {
            $newestFirst = array_slice($newestFirst, 0, $this->maxEntries);
        }
        $entries = array_reverse($newestFirst);

        if ($entries !== []) {
            $sourceStatus = ($malformedCount > 0 || $unsafeSource) ? 'partial' : 'ok';
        } elseif ($malformedCount > 0) {
            $sourceStatus = 'corrupt';
        } elseif ($unsafeSource) {
            $sourceStatus = 'unavailable';
        } elseif ($presentSources === 0) {
            $sourceStatus = 'missing';
        } else {
            $sourceStatus = 'empty';
        }

        return $this->payload(
            $entries,
            $fileCount,
            $malformedCount,
            $period,
            $truncated,
            $sourceStatus
        );
    }

    /** @return array<int, string> */
    private function sourcePaths(): array
    {
        return [
            $this->directory . '/module.log.1',
            $this->directory . '/module.log',
        ];
    }

    private function validatedDirectory(): ?string
    {
        if ($this->directory === '' || is_link($this->directory) || !is_dir($this->directory)) {
            return null;
        }
        clearstatcache(true, $this->directory);
        $stat = @lstat($this->directory);
        $realDirectory = realpath($this->directory);
        if (!is_array($stat)
            || (($stat['mode'] & 0170000) !== 0040000)
            || !is_string($realDirectory)
        ) {
            return null;
        }

        return rtrim($realDirectory, '/');
    }

    /** @return resource|false */
    private function openValidatedSource(string $path, string $realDirectory)
    {
        if (dirname($path) !== $this->directory
            || is_link($path)
            || !is_file($path)
            || !is_readable($path)
        ) {
            return false;
        }
        $realPath = realpath($path);
        if (!is_string($realPath) || dirname($realPath) !== $realDirectory) {
            return false;
        }

        $handle = fopen($path, 'rb');
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

        return $handle;
    }

    /**
     * @param resource $handle
     * @return Generator<int, string>
     */
    private function linesNewestFirst($handle): Generator
    {
        $stat = fstat($handle);
        $size = is_array($stat) ? (int)$stat['size'] : 0;
        if ($size <= 0) {
            return;
        }

        $position = $size;
        $buffer = '';
        while ($position > 0) {
            $readBytes = min(self::CHUNK_BYTES, $position);
            $position -= $readBytes;
            if (fseek($handle, $position) !== 0) {
                return;
            }
            $chunk = fread($handle, $readBytes);
            if (!is_string($chunk) || strlen($chunk) !== $readBytes) {
                return;
            }
            $buffer = $chunk . $buffer;

            while (($newline = strrpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, $newline + 1), "\r");
                $buffer = substr($buffer, 0, $newline);
                if ($line !== '') {
                    yield $line;
                }
            }
        }

        $line = rtrim($buffer, "\r");
        if ($line !== '') {
            yield $line;
        }
    }

    /** @return array<string, mixed>|null */
    private function decodeEntry(string $line): ?array
    {
        $syslogEntry = LocalSpeechToTextSyslogParser::parse($line);
        if ($syslogEntry !== null) {
            $timestamp = strtotime($syslogEntry['timestamp']);
            if (!is_int($timestamp)) {
                return null;
            }
            $syslogEntry['timestamp'] = date(DATE_RFC3339, $timestamp);
            $syslogEntry['ts'] = $syslogEntry['timestamp'];
            $syslogEntry['message'] = LocalSpeechToTextLogSanitizer::message($syslogEntry['message']);
            $syslogEntry['context'] = LocalSpeechToTextLogSanitizer::context($syslogEntry['context']);
            return $syslogEntry;
        }

        try {
            $decoded = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['ts', 'level', 'component', 'event', 'message', 'context']) {
            return null;
        }
        foreach (['ts', 'level', 'component', 'event', 'message'] as $required) {
            if (!is_string($decoded[$required] ?? null) || trim($decoded[$required]) === '') {
                return null;
            }
        }
        if (strtotime($decoded['ts']) === false
            || !in_array($decoded['level'], self::LEVELS, true)
            || preg_match('/^[a-z0-9_]{1,64}$/D', $decoded['component']) !== 1
            || preg_match('/^[a-z0-9_]{1,64}$/D', $decoded['event']) !== 1
            || !is_array($decoded['context'])
        ) {
            return null;
        }
        foreach ($decoded['context'] as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string)$key) !== 1
                || (!is_scalar($value) && $value !== null)
            ) {
                return null;
            }
        }

        $timestamp = strtotime($decoded['ts']);
        if (!is_int($timestamp)) {
            return null;
        }
        $level = strtolower($decoded['level']);
        $severity = $level === 'error' ? 'err' : $level;

        return [
            'timestamp' => date(DATE_RFC3339, $timestamp),
            'ts' => date(DATE_RFC3339, $timestamp),
            'facility' => 'daemon',
            'severity' => $severity,
            'level' => $level,
            'tag' => LocalSpeechToTextSyslogFormatter::MODULE_TAG,
            'pid' => 0,
            'component' => $decoded['component'],
            'event' => $decoded['event'],
            'message' => LocalSpeechToTextLogSanitizer::message($decoded['message']),
            'context' => LocalSpeechToTextLogSanitizer::context($decoded['context']),
        ];
    }

    /**
     * @param array<int, array{ts:string,level:string,component:string,event:string,message:string,context:array<string, scalar|null>}> $entries
     * @return array<string, mixed>
     */
    private function payload(
        array $entries,
        int $fileCount,
        int $malformedCount,
        int $period,
        bool $truncated,
        string $sourceStatus
    ): array {
        $lines = array_map(static function (array $entry): string {
            return LocalSpeechToTextSyslogFormatter::formatSeverity(
                $entry['component'],
                $entry['event'],
                $entry['message'],
                $entry['severity'],
                $entry['context'],
                strtotime($entry['timestamp']) ?: 0,
                $entry['pid']
            );
        }, $entries);

        return [
            'entries' => $entries,
            'logText' => implode("\n", $lines),
            'lineCount' => count($entries),
            'fileCount' => $fileCount,
            'generatedAt' => date(DATE_RFC3339),
            'period' => $period,
            'availableFrom' => $entries[0]['timestamp'] ?? null,
            'truncated' => $truncated,
            'sourceStatus' => $sourceStatus,
            'invalidLineCount' => $malformedCount,
            'malformedCount' => $malformedCount,
        ];
    }
}
