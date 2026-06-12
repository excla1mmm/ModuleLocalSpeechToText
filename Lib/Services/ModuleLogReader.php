<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Core\System\Directories;

class ModuleLogReader
{
    private const string MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText';
    private const int MODULE_LOG_MAX_RESPONSE_LINES = 2000;
    private const int MODULE_LOG_TAIL_LINES_PER_FILE = 4000;

    public function moduleLogsPayload(int $sinceTimestamp): array
    {
        $entries = [];
        foreach ($this->moduleLogSources() as $source) {
            $sourceTimestamp = $this->fileModificationTime($source['path']);
            $lastTimestamp = is_int($sourceTimestamp) ? $sourceTimestamp : 0;
            $lineNumber = 0;

            foreach ($this->tailLines($source['path'], self::MODULE_LOG_TAIL_LINES_PER_FILE) as $line) {
                $lineNumber++;
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                if ($source['filterModuleLines'] && !$this->isModuleSystemLogLine($line)) {
                    continue;
                }

                $timestamp = $this->parseLogTimestamp($line);
                if ($timestamp !== 0) {
                    $lastTimestamp = $timestamp;
                } else {
                    $timestamp = $lastTimestamp;
                }

                if ($timestamp !== 0 && $timestamp < $sinceTimestamp) {
                    continue;
                }

                $entries[] = [
                    'timestamp' => $timestamp,
                    'source' => $source['label'],
                    'lineNumber' => $lineNumber,
                    'line' => $line,
                ];
            }
        }

        usort($entries, static function (array $left, array $right): int {
            return [
                (int)$left['timestamp'],
                (string)$left['source'],
                (int)$left['lineNumber'],
            ] <=> [
                (int)$right['timestamp'],
                (string)$right['source'],
                (int)$right['lineNumber'],
            ];
        });

        if (count($entries) > self::MODULE_LOG_MAX_RESPONSE_LINES) {
            $entries = array_slice($entries, -self::MODULE_LOG_MAX_RESPONSE_LINES);
        }

        $lines = array_map(
            static fn (array $entry): string => sprintf('[%s] %s', $entry['source'], $entry['line']),
            $entries
        );

        return [
            'logText' => implode("\n", $lines),
            'lineCount' => count($lines),
            'fileCount' => count($this->moduleLogSources()),
        ];
    }

    /**
     * @return array<int, array{path: string, label: string, filterModuleLines: bool}>
     */
    private function moduleLogSources(): array
    {
        $coreLogsDir = rtrim(Directories::getDir(Directories::CORE_LOGS_DIR), '/');
        $sources = [];
        $seen = [];
        $moduleLogDir = $coreLogsDir . '/' . self::MODULE_UNIQUE_ID;

        if (is_dir($moduleLogDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($moduleLogDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile() || !$fileInfo->isReadable()) {
                    continue;
                }
                if (preg_match('/\.log(?:\.\d+)?$/', $fileInfo->getFilename()) !== 1) {
                    continue;
                }

                $realPath = $fileInfo->getRealPath();
                if ($realPath === false || isset($seen[$realPath])) {
                    continue;
                }

                $seen[$realPath] = true;
                $relativePath = ltrim(str_replace($moduleLogDir, '', $realPath), '/');
                $sources[] = [
                    'path' => $realPath,
                    'label' => self::MODULE_UNIQUE_ID . '/' . $relativePath,
                    'filterModuleLines' => false,
                ];
            }
        }

        foreach ([$coreLogsDir . '/system/messages', $coreLogsDir . '/messages'] as $systemLogPath) {
            $realPath = realpath($systemLogPath);
            if ($realPath === false || isset($seen[$realPath]) || !is_file($realPath) || !is_readable($realPath)) {
                continue;
            }

            $seen[$realPath] = true;
            $sources[] = [
                'path' => $realPath,
                'label' => str_replace($coreLogsDir . '/', '', $realPath),
                'filterModuleLines' => true,
            ];
        }

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    private function tailLines(string $filePath, int $maxLines): array
    {
        $fileSize = $this->fileSize($filePath);
        if (!is_int($fileSize) || $fileSize <= 0) {
            return [];
        }

        $handle = $this->openReadHandle($filePath);
        if ($handle === false) {
            return [];
        }

        $chunkSize = 65536;
        $position = $fileSize;
        $buffer = '';

        while ($position > 0 && substr_count($buffer, "\n") <= $maxLines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            if (fseek($handle, $position) !== 0) {
                break;
            }

            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            if (strlen($buffer) > 8 * 1024 * 1024) {
                break;
            }
        }

        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $buffer);
        if (!is_array($lines)) {
            return [];
        }

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_slice($lines, -$maxLines);
    }

    private function isModuleSystemLogLine(string $line): bool
    {
        return stripos($line, self::MODULE_UNIQUE_ID) !== false
            || stripos($line, 'LocalSpeechToText') !== false
            || stripos($line, 'local-speech-to-text') !== false;
    }

    private function parseLogTimestamp(string $line): int
    {
        $patterns = [
            '/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/' => 'Y-m-d H:i:s',
            '/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/' => 'Y-m-d H:i:s',
            '/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})/' => 'Y/m/d H:i:s',
            '/^\[(\d{1,2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2})\s+[^\]]+\]/' => 'd-M-Y H:i:s',
            '/^\[[A-Za-z]{3},\s+(\d{1,2}\s+[A-Za-z]{3}\s+\d{2}\s+\d{2}:\d{2}:\d{2}\s+[+-]\d{4})\]/' => 'd M y H:i:s O',
        ];

        foreach ($patterns as $pattern => $format) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }

            $timestamp = strtotime($matches[1]);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        if (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+/', $line, $matches) === 1) {
            $timestamp = strtotime($matches[1] . ' ' . date('Y'));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return 0;
    }



    private function fileModificationTime(string $filePath): int
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return 0;
        }

        clearstatcache(true, $filePath);
        $timestamp = filemtime($filePath);

        return is_int($timestamp) ? $timestamp : 0;
    }

    private function fileSize(string $filePath): int
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return 0;
        }

        clearstatcache(true, $filePath);
        $size = filesize($filePath);

        return is_int($size) ? $size : 0;
    }

    /**
     * @return resource|false
     */
    private function openReadHandle(string $filePath)
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        return fopen($filePath, 'rb');
    }
}