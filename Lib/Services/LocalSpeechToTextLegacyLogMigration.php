<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use FilesystemIterator;
use SplFileInfo;

final class LocalSpeechToTextLegacyLogMigration
{
    private const int MAX_PREVIOUS_BYTES = 5_242_880;
    private const int CHUNK_BYTES = 65_536;
    private const string LEGACY_BASENAME_PATTERN =
        '/^(?:api|web-ui|setup|workerlocalspeechtotextjobs|localspeechtotextmain|logger)\.log(?:\.\d+)?$/Di';

    public function __construct(
        private readonly string $directory,
        private readonly LocalSpeechToTextLogWriter $writer
    ) {
    }

    /** @return array{imported: int, removed: int, skipped: int} */
    public function run(): array
    {
        $realDirectory = $this->validatedDirectory();
        if ($realDirectory === null) {
            return ['imported' => 0, 'removed' => 0, 'skipped' => 0];
        }

        $candidates = $this->legacyCandidates($realDirectory);
        if ($candidates === []) {
            return ['imported' => 0, 'removed' => 0, 'skipped' => 0];
        }

        $newestFirst = [];
        $successfullyRead = [];
        $bytes = 0;
        $limitReached = false;
        foreach ($candidates as $candidate) {
            $readCompleted = false;
            $sourceLineFromEnd = 0;
            foreach ($this->linesNewestFirst($candidate, $readCompleted) as $line) {
                $sourceLineFromEnd++;
                if ($limitReached) {
                    continue;
                }
                $encoded = $this->convertLine(
                    $line,
                    $candidate['basename'],
                    $candidate['modifiedAt'],
                    $sourceLineFromEnd
                );
                if ($encoded === null) {
                    continue;
                }
                $lineBytes = strlen($encoded) + 1;
                if ($bytes + $lineBytes > self::MAX_PREVIOUS_BYTES) {
                    $limitReached = true;
                    continue;
                }
                $newestFirst[] = $encoded;
                $bytes += $lineBytes;
            }
            if ($readCompleted) {
                $successfullyRead[$candidate['path']] = true;
            }
        }

        if ($newestFirst === []) {
            return ['imported' => 0, 'removed' => 0, 'skipped' => count($candidates)];
        }
        $importedCount = count($newestFirst);
        if (!$this->writer->mergePrevious(array_reverse($newestFirst))) {
            return ['imported' => 0, 'removed' => 0, 'skipped' => count($candidates)];
        }

        $removed = 0;
        $skipped = 0;
        foreach ($candidates as $candidate) {
            if (!isset($successfullyRead[$candidate['path']])
                || !$this->stillSameContainedFile($candidate, $realDirectory)
                || !@unlink($candidate['path'])
            ) {
                $skipped++;
                continue;
            }
            $removed++;
        }

        return ['imported' => $importedCount, 'removed' => $removed, 'skipped' => $skipped];
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

    /**
     * @return array<int, array{path:string,basename:string,modifiedAt:int,size:int,dev:int,ino:int}>
     */
    private function legacyCandidates(string $realDirectory): array
    {
        $candidates = [];
        foreach (new FilesystemIterator($realDirectory, FilesystemIterator::SKIP_DOTS) as $item) {
            if (!$item instanceof SplFileInfo || $item->isLink() || !$item->isFile() || !$item->isReadable()) {
                continue;
            }
            $basename = $item->getBasename();
            if (preg_match(self::LEGACY_BASENAME_PATTERN, $basename) !== 1) {
                continue;
            }

            $path = $item->getPathname();
            $realPath = realpath($path);
            $stat = @lstat($path);
            if (!is_string($realPath)
                || dirname($realPath) !== $realDirectory
                || !is_array($stat)
                || (($stat['mode'] & 0170000) !== 0100000)
                || is_link($path)
            ) {
                continue;
            }
            $candidates[] = [
                'path' => $realPath,
                'basename' => basename($basename),
                'modifiedAt' => (int)$stat['mtime'],
                'size' => (int)$stat['size'],
                'dev' => (int)$stat['dev'],
                'ino' => (int)$stat['ino'],
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            return [$right['modifiedAt'], $right['basename']] <=> [$left['modifiedAt'], $left['basename']];
        });

        return $candidates;
    }

    /**
     * @param array{path:string,basename:string,modifiedAt:int,size:int,dev:int,ino:int} $candidate
     * @return iterable<int, string>
     */
    private function linesNewestFirst(array $candidate, ?bool &$completed = null): iterable
    {
        $completed = false;
        $handle = fopen($candidate['path'], 'rb');
        if (!is_resource($handle)) {
            return;
        }

        try {
            $openedStat = fstat($handle);
            if (!is_array($openedStat)
                || (($openedStat['mode'] & 0170000) !== 0100000)
                || (int)$openedStat['dev'] !== $candidate['dev']
                || (int)$openedStat['ino'] !== $candidate['ino']
                || (int)$openedStat['size'] < $candidate['size']
            ) {
                return;
            }
            if ($candidate['size'] <= 0) {
                $completed = true;
                return;
            }

            $position = $candidate['size'];
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
                    $line = trim(substr($buffer, $newline + 1));
                    $buffer = substr($buffer, 0, $newline);
                    if ($line !== '') {
                        yield $line;
                    }
                }
            }
            $line = trim($buffer);
            if ($line !== '') {
                yield $line;
            }
            $completed = true;
        } finally {
            fclose($handle);
        }
    }

    private function convertLine(
        string $line,
        string $source,
        int $fallbackTimestamp,
        int $sourceLineFromEnd
    ): ?string
    {
        $message = LocalSpeechToTextLogSanitizer::message($line);
        if ($message === '') {
            return null;
        }
        $timestamp = $this->lineTimestamp($line) ?? $fallbackTimestamp;
        $severity = match (true) {
            preg_match('/\b(?:error|fatal)\b/i', $line) === 1 => 'err',
            preg_match('/\bcritical\b/i', $line) === 1 => 'crit',
            preg_match('/\bemergency\b/i', $line) === 1 => 'emerg',
            preg_match('/\balert\b/i', $line) === 1 => 'alert',
            preg_match('/\bwarn(?:ing)?\b/i', $line) === 1 => 'warning',
            preg_match('/\bnotice\b/i', $line) === 1 => 'notice',
            preg_match('/\bdebug\b/i', $line) === 1 => 'debug',
            default => 'info',
        };
        $component = preg_replace('/\.log(?:\.\d+)?$/Di', '', $source) ?? 'system';
        $component = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $component) ?? '');
        $component = trim($component, '_') ?: 'system';

        return LocalSpeechToTextSyslogFormatter::formatSeverity(
            substr($component, 0, 64),
            'legacy_log_imported',
            $message,
            $severity,
            ['source' => basename($source), 'source_line_from_end' => $sourceLineFromEnd],
            $timestamp,
            0
        );
    }

    private function lineTimestamp(string $line): ?int
    {
        foreach ([
            '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)?)\]/',
            '/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)?)/',
            '/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\b/',
        ] as $pattern) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }
            $candidate = $matches[1];
            if (preg_match('/^[A-Z][a-z]{2}\s+/', $candidate) === 1) {
                $candidate .= ' ' . date('Y');
            }
            $timestamp = strtotime($candidate);
            if (is_int($timestamp)) {
                return $timestamp;
            }
        }

        return null;
    }

    /** @param array{path:string,basename:string,modifiedAt:int,size:int,dev:int,ino:int} $candidate */
    private function stillSameContainedFile(array $candidate, string $realDirectory): bool
    {
        $path = $candidate['path'];
        clearstatcache(true, $path);
        $realPath = realpath($path);
        $stat = @lstat($path);

        return is_string($realPath)
            && dirname($realPath) === $realDirectory
            && !is_link($path)
            && is_file($path)
            && is_array($stat)
            && (($stat['mode'] & 0170000) === 0100000)
            && (int)$stat['dev'] === $candidate['dev']
            && (int)$stat['ino'] === $candidate['ino']
            && (int)$stat['size'] === $candidate['size']
            && (int)$stat['mtime'] === $candidate['modifiedAt'];
    }
}
