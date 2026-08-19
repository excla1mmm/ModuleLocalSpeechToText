<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use InvalidArgumentException;

require_once __DIR__ . '/CallTranscriptTurnProjector.php';

final class CallTranscriptAssembler
{
    private const float DUPLICATE_OVERLAP_RATIO = 0.8;

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    public function assemble(string $callId, array $parts): array
    {
        $callId = trim($callId);
        if ($callId === '') {
            throw new InvalidArgumentException('Call id is required for transcript assembly');
        }

        $parts = array_values(array_filter($parts, 'is_array'));
        usort($parts, fn (array $left, array $right): int => $this->compareParts($left, $right));

        $manifest = [];
        $segments = [];
        $hasUnresolved = false;
        foreach ($parts as $partOrder => $part) {
            $resultId = max(0, (int)($part['result_id'] ?? 0));
            $resolutionStatus = trim((string)($part['resolution_status'] ?? 'complete')) ?: 'complete';
            $hasUnresolved = $hasUnresolved || $resolutionStatus !== 'complete';
            $sourceLabel = trim((string)($part['source_label'] ?? ''));
            if ($sourceLabel === '') {
                $sourceLabel = $resolutionStatus === 'complete'
                    ? 'part:' . $resultId
                    : 'legacy:' . $resultId;
            }

            $manifest[] = [
                'result_id' => $resultId,
                'job_id' => self::nullablePositiveInt($part['job_id'] ?? null),
                'cdr_id' => self::nullablePositiveInt($part['cdr_id'] ?? null),
                'uniqueid' => trim((string)($part['uniqueid'] ?? '')),
                'cdr_start_ms' => self::nullablePositiveInt($part['cdr_start_ms'] ?? null),
                'cdr_end_ms' => self::nullablePositiveInt($part['cdr_end_ms'] ?? null),
                'recording_fingerprint' => trim((string)($part['recording_fingerprint'] ?? '')),
                'content_hash' => trim((string)($part['content_hash'] ?? '')),
                'source_label' => $sourceLabel,
                'resolution_status' => $resolutionStatus,
            ];

            $partSegments = is_array($part['segments'] ?? null) ? $part['segments'] : [];
            foreach ($partSegments as $segmentIndex => $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                $absolute = $this->absoluteSegment(
                    $callId,
                    $part,
                    $segment,
                    (int)$segmentIndex,
                    $partOrder,
                    $sourceLabel,
                    $resolutionStatus
                );
                if ($absolute !== null) {
                    $segments[] = $absolute;
                }
            }
        }

        usort($segments, fn (array $left, array $right): int => $this->compareSegments($left, $right));
        $segments = $this->withoutProvenDuplicates($segments);

        foreach ($segments as $index => $segment) {
            $segment['index'] = $index;
            $segments[$index] = $segment;
        }

        $transcript = (new CallTranscriptTurnProjector())->project([
            'segments' => $segments,
            'segment_count' => count($segments),
            'turns' => [],
            'turn_count' => 0,
            'text' => '',
        ]);

        $semanticSegments = array_map(
            static fn (array $segment): array => [
                'absolute_start_ms' => $segment['absolute_start_ms'],
                'absolute_end_ms' => $segment['absolute_end_ms'],
                'relative_start_ms' => $segment['start_ms'],
                'relative_end_ms' => $segment['end_ms'],
                'source_label' => $segment['source_label'],
                'channel' => $segment['channel'],
                'speaker' => $segment['speaker'],
                'text' => $segment['text'],
            ],
            $segments
        );

        return [
            'call_id' => $callId,
            'content_hash' => hash('sha256', $this->canonicalJson([
                'call_id' => $callId,
                'segments' => $semanticSegments,
            ])),
            'parts_hash' => hash('sha256', $this->canonicalJson($manifest)),
            'completeness_status' => $hasUnresolved ? 'legacy_unresolved' : 'complete',
            'part_manifest' => $manifest,
            'transcript' => $transcript,
        ];
    }

    /**
     * @param array<string, mixed> $part
     * @param array<string, mixed> $segment
     * @return array<string, mixed>|null
     */
    private function absoluteSegment(
        string $callId,
        array $part,
        array $segment,
        int $segmentIndex,
        int $partOrder,
        string $sourceLabel,
        string $resolutionStatus
    ): ?array {
        $text = trim((string)($segment['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        $startMs = self::segmentTimeMs($segment, 'start');
        $endMs = self::segmentTimeMs($segment, 'end');
        if ($endMs !== null && $startMs !== null && $endMs < $startMs) {
            [$startMs, $endMs] = [$endMs, $startMs];
        }

        $cdrStartMs = self::nullablePositiveInt($part['cdr_start_ms'] ?? null);
        $resolved = $resolutionStatus === 'complete' && $cdrStartMs !== null;
        $absoluteStartMs = $resolved && $startMs !== null ? $cdrStartMs + $startMs : null;
        $absoluteEndMs = $resolved && $endMs !== null ? $cdrStartMs + $endMs : null;
        $resultId = max(0, (int)($part['result_id'] ?? 0));

        return [
            'segment_id' => sprintf('call:%s:r%d:s%d', substr(hash('sha256', $callId), 0, 16), $resultId, $segmentIndex),
            'result_id' => $resultId,
            'source_index' => $segmentIndex,
            'part_order' => $partOrder,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'absolute_start_ms' => $absoluteStartMs,
            'absolute_end_ms' => $absoluteEndMs,
            'text' => $text,
            'normalized_text' => self::normalizeText($text),
            'source_label' => $sourceLabel,
            'recording_fingerprint' => trim((string)($part['recording_fingerprint'] ?? '')),
            'resolution_status' => $resolutionStatus,
            'channel' => isset($segment['channel']) ? (string)$segment['channel'] : null,
            'speaker' => isset($segment['speaker']) ? (string)$segment['speaker'] : null,
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareParts(array $left, array $right): int
    {
        $leftResolved = ($left['resolution_status'] ?? 'complete') === 'complete'
            && self::nullablePositiveInt($left['cdr_start_ms'] ?? null) !== null;
        $rightResolved = ($right['resolution_status'] ?? 'complete') === 'complete'
            && self::nullablePositiveInt($right['cdr_start_ms'] ?? null) !== null;

        return [
            $leftResolved ? 0 : 1,
            $leftResolved ? (int)$left['cdr_start_ms'] : 0,
            $leftResolved ? (int)($left['cdr_end_ms'] ?? 0) : 0,
            $leftResolved ? trim((string)($left['source_label'] ?? '')) : '',
            (int)($left['result_id'] ?? 0),
        ] <=> [
            $rightResolved ? 0 : 1,
            $rightResolved ? (int)$right['cdr_start_ms'] : 0,
            $rightResolved ? (int)($right['cdr_end_ms'] ?? 0) : 0,
            $rightResolved ? trim((string)($right['source_label'] ?? '')) : '',
            (int)($right['result_id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareSegments(array $left, array $right): int
    {
        $leftResolved = is_int($left['absolute_start_ms'] ?? null);
        $rightResolved = is_int($right['absolute_start_ms'] ?? null);

        return [
            $leftResolved ? 0 : 1,
            $leftResolved ? $left['absolute_start_ms'] : (int)$left['part_order'],
            $leftResolved ? ($left['absolute_end_ms'] ?? $left['absolute_start_ms']) : (int)($left['start_ms'] ?? 0),
            (string)$left['source_label'],
            (int)$left['result_id'],
            (int)$left['source_index'],
        ] <=> [
            $rightResolved ? 0 : 1,
            $rightResolved ? $right['absolute_start_ms'] : (int)$right['part_order'],
            $rightResolved ? ($right['absolute_end_ms'] ?? $right['absolute_start_ms']) : (int)($right['start_ms'] ?? 0),
            (string)$right['source_label'],
            (int)$right['result_id'],
            (int)$right['source_index'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    private function withoutProvenDuplicates(array $segments): array
    {
        $kept = [];
        foreach ($segments as $candidate) {
            $duplicate = false;
            foreach (array_reverse($kept) as $existing) {
                if ($this->isProvenDuplicate($existing, $candidate)) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $kept[] = $candidate;
            }
        }

        return $kept;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function isProvenDuplicate(array $left, array $right): bool
    {
        if ((string)$left['normalized_text'] === '' || $left['normalized_text'] !== $right['normalized_text']) {
            return false;
        }
        foreach (['absolute_start_ms', 'absolute_end_ms'] as $field) {
            if (!is_int($left[$field] ?? null) || !is_int($right[$field] ?? null)) {
                return false;
            }
        }

        $overlap = min($left['absolute_end_ms'], $right['absolute_end_ms'])
            - max($left['absolute_start_ms'], $right['absolute_start_ms']);
        $shorter = min(
            max(1, $left['absolute_end_ms'] - $left['absolute_start_ms']),
            max(1, $right['absolute_end_ms'] - $right['absolute_start_ms'])
        );

        return $overlap > 0 && ($overlap / $shorter) >= self::DUPLICATE_OVERLAP_RATIO;
    }

    private function canonicalJson(array $value): string
    {
        return json_encode(
            self::canonicalValue($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: '[]';
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalValue(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalValue($item);
        }

        return $value;
    }

    /** @param array<string, mixed> $segment */
    private static function segmentTimeMs(array $segment, string $field): ?int
    {
        $milliseconds = $field . '_ms';
        if (isset($segment[$milliseconds]) && is_numeric($segment[$milliseconds])) {
            return max(0, (int)round((float)$segment[$milliseconds]));
        }
        if (isset($segment[$field]) && is_numeric($segment[$field])) {
            return max(0, (int)round((float)$segment[$field] * 1000));
        }

        return null;
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }

    private static function normalizeText(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}
