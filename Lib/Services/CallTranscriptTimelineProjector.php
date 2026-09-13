<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/**
 * Publishes the two time scales of an aggregated logical-call transcript:
 * call-relative start_ms/end_ms for the whole call and recording-local
 * recording_start_ms/recording_end_ms inside the recording of one CDR part.
 */
final class CallTranscriptTimelineProjector
{
    /**
     * @param array<string, mixed> $transcript
     * @param array<int, mixed> $manifest
     * @return array<string, mixed>
     */
    public function project(array $transcript, array $manifest): array
    {
        [$partsByResult, $callStartMs] = $this->manifestIndex($manifest);
        $reliable = $callStartMs !== null;

        $segments = [];
        foreach ($this->items($transcript['segments'] ?? null) as $segment) {
            $resultId = self::positiveInt($segment['result_id'] ?? null);
            $segments[] = $this->projectItem($segment, $resultId !== null ? $resultId : null, $partsByResult, $callStartMs, $reliable);
        }
        $transcript['segments'] = $segments;

        $turns = [];
        foreach ($this->items($transcript['turns'] ?? null) as $turn) {
            $sourceResultIds = is_array($turn['source_result_ids'] ?? null) ? $turn['source_result_ids'] : [];
            $resultId = null;
            if (count($sourceResultIds) === 1) {
                $resultId = self::positiveInt($sourceResultIds[0] ?? null);
            } else {
                // A turn that spans several recordings cannot attribute its
                // local time codes to one of them.
                $reliable = false;
            }
            $turns[] = $this->projectItem($turn, $resultId, $partsByResult, $callStartMs, $reliable);
        }
        $transcript['turns'] = $turns;

        $transcript['timeline_reliable'] = $reliable;

        return $transcript;
    }

    /** @param array<int, mixed> $manifest @return array{0: array<int, int>, 1: int|null} */
    private function manifestIndex(array $manifest): array
    {
        $partsByResult = [];
        $callStartMs = null;
        foreach ($manifest as $part) {
            if (!is_array($part)) {
                continue;
            }
            $resultId = self::positiveInt($part['result_id'] ?? null);
            $cdrStartMs = self::intOrNull($part['cdr_start_ms'] ?? null);
            if ($resultId !== null && $cdrStartMs !== null && !isset($partsByResult[$resultId])) {
                $partsByResult[$resultId] = $cdrStartMs;
            }
            if ($cdrStartMs !== null) {
                $callStartMs = $callStartMs === null ? $cdrStartMs : min($callStartMs, $cdrStartMs);
            }
        }

        return [$partsByResult, $callStartMs];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, int> $partsByResult
     * @return array<string, mixed>
     */
    private function projectItem(array $item, ?int $resultId, array $partsByResult, ?int $callStartMs, bool &$reliable): array
    {
        $recordingStart = self::intOrNull($item['start_ms'] ?? null);
        $recordingEnd = self::intOrNull($item['end_ms'] ?? null);
        $item['recording_start_ms'] = $recordingStart;
        $item['recording_end_ms'] = $recordingEnd;

        $partStartMs = $resultId !== null ? ($partsByResult[$resultId] ?? null) : null;

        $globalStart = self::intOrNull($item['absolute_start_ms'] ?? null);
        if ($globalStart === null && $partStartMs !== null && $recordingStart !== null) {
            $globalStart = $partStartMs + $recordingStart;
        }
        $globalEnd = self::intOrNull($item['absolute_end_ms'] ?? null);
        if ($globalEnd === null && $partStartMs !== null && $recordingEnd !== null) {
            $globalEnd = $partStartMs + $recordingEnd;
        }

        if ($callStartMs !== null && $globalStart !== null && $globalEnd !== null) {
            $startMs = max(0, $globalStart - $callStartMs);
            $item['start_ms'] = $startMs;
            $item['end_ms'] = max($startMs, $globalEnd - $callStartMs);

            return $item;
        }

        // The absolute position of this item cannot be restored: keep the
        // previous local codes and downgrade the whole projection.
        $reliable = false;

        return $item;
    }

    /** @return list<array<string, mixed>> */
    private function items(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $value = self::intOrNull($value);

        return $value !== null && $value > 0 ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int)$value;
        }

        return null;
    }
}
