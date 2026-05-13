<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use InvalidArgumentException;

final class TranscriptTimeNormalizer
{
    /**
     * @param array<string, mixed> $data
     */
    public static function durationSecondsFromPayload(array $data): ?float
    {
        if (!array_key_exists('duration_ms', $data) || $data['duration_ms'] === null) {
            if (array_key_exists('duration', $data) && $data['duration'] !== null) {
                throw new InvalidArgumentException('duration is not supported; use integer duration_ms');
            }
            return null;
        }

        return self::millisecondsToSeconds($data['duration_ms'], 'duration_ms');
    }

    /**
     * @param array<int, mixed> $segments
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeTranscriptSegments(array $segments): array
    {
        $normalized = [];

        foreach ($segments as $index => $segment) {
            if (!is_array($segment)) {
                throw new InvalidArgumentException(sprintf('transcript[%d] must be an object', $index));
            }

            $hasStart = array_key_exists('start_ms', $segment) && $segment['start_ms'] !== null;
            $hasEnd = array_key_exists('end_ms', $segment) && $segment['end_ms'] !== null;
            if ($hasStart !== $hasEnd) {
                throw new InvalidArgumentException(sprintf('transcript[%d] must contain both start_ms and end_ms', $index));
            }
            if (array_key_exists('start', $segment) || array_key_exists('end', $segment)) {
                throw new InvalidArgumentException(sprintf('transcript[%d] start/end are not supported; use integer start_ms/end_ms', $index));
            }

            if ($hasStart) {
                $startMs = self::millisecondsValue($segment['start_ms'], sprintf('transcript[%d].start_ms', $index));
                $endMs = self::millisecondsValue($segment['end_ms'], sprintf('transcript[%d].end_ms', $index));
                if ($endMs < $startMs) {
                    throw new InvalidArgumentException(sprintf('transcript[%d].end_ms must be greater than or equal to start_ms', $index));
                }
                $segment['start_ms'] = $startMs;
                $segment['end_ms'] = $endMs;
            }

            $normalized[] = $segment;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $segment
     * @return array{0: float, 1: float}
     */
    public static function segmentRangeSeconds(array $segment): array
    {
        if (!array_key_exists('start_ms', $segment) || !array_key_exists('end_ms', $segment)) {
            return [0.0, 0.0];
        }

        try {
            return [
                self::millisecondsToSeconds($segment['start_ms'], 'start_ms'),
                self::millisecondsToSeconds($segment['end_ms'], 'end_ms'),
            ];
        } catch (InvalidArgumentException) {
            return [0.0, 0.0];
        }
    }

    public static function millisecondsToSeconds(mixed $value, string $field = 'milliseconds'): float
    {
        return self::millisecondsValue($value, $field) / 1000.0;
    }

    public static function secondsMetricFromMilliseconds(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return self::millisecondsToSeconds($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function normalizeLargeDecimal(mixed $value, mixed $reference = null): mixed
    {
        return is_numeric($value) ? (float)$value : $value;
    }

    public static function normalizeDecimalWithExpected(mixed $value, mixed $expected): mixed
    {
        return is_numeric($value) ? (float)$value : $value;
    }

    public static function normalizeRatio(mixed $value, bool $allowAboveOne = false): mixed
    {
        return is_numeric($value) ? (float)$value : $value;
    }

    public static function roundSeconds(float $seconds): float
    {
        return round(max(0.0, $seconds), 3);
    }

    private static function millisecondsValue(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $milliseconds = $value;
        } elseif (is_float($value) && floor($value) === $value) {
            $milliseconds = (int)$value;
        } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $milliseconds = (int)$value;
        } else {
            throw new InvalidArgumentException(sprintf('%s must be an integer number of milliseconds', $field));
        }

        if ($milliseconds < 0) {
            throw new InvalidArgumentException(sprintf('%s must be greater than or equal to 0', $field));
        }

        return $milliseconds;
    }
}
