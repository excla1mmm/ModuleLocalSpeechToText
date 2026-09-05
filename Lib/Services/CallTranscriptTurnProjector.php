<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class CallTranscriptTurnProjector
{
    private const int TURN_MAX_GAP_MS = 2000;
    private const int TURN_MAX_DURATION_MS = 45000;
    private const int TURN_MAX_TEXT_CHARS = 700;

    /**
     * Rebuilds presentation turns from immutable raw segments. This is safe to
     * apply to historical revision payloads because segment identity, order and
     * revision hashes remain untouched.
     *
     * @param array<string, mixed> $transcript
     * @return array<string, mixed>
     */
    public function project(array $transcript): array
    {
        $rawSegments = is_array($transcript['segments'] ?? null) ? $transcript['segments'] : [];
        $segments = [];
        foreach ($rawSegments as $sourceOrder => $segment) {
            if (!is_array($segment) || trim((string)($segment['text'] ?? '')) === '') {
                continue;
            }
            $segment['_projection_order'] = (int)$sourceOrder;
            $segments[] = $segment;
        }
        if ($segments === []) {
            return $transcript;
        }

        usort($segments, fn (array $left, array $right): int => $this->compareSegments($left, $right));

        $turns = [];
        $current = null;
        foreach ($segments as $segment) {
            if ($current === null || !$this->canAppend($current, $segment)) {
                if ($current !== null) {
                    $turns[] = $current;
                }
                $current = $this->newTurn($segment, count($turns));
                continue;
            }

            $current = $this->append($current, $segment);
        }
        if ($current !== null) {
            $turns[] = $current;
        }

        foreach ($turns as &$turn) {
            unset($turn['_identity']);
        }
        unset($turn);

        $transcript['turns'] = $turns;
        $transcript['turn_count'] = count($turns);
        $transcript['text'] = $this->plainText($turns);

        return $transcript;
    }

    /** @param array<string, mixed> $turn @param array<string, mixed> $segment */
    private function canAppend(array $turn, array $segment): bool
    {
        if ((string)($turn['_identity'] ?? '') !== self::identity($segment)) {
            return false;
        }

        $turnStart = self::timelineValue($turn, 'start');
        $turnEnd = self::timelineValue($turn, 'end');
        $segmentStart = self::timelineValue($segment, 'start');
        $segmentEnd = self::timelineValue($segment, 'end');
        if ($segmentStart !== null && $turnEnd !== null && $segmentStart - $turnEnd > self::TURN_MAX_GAP_MS) {
            return false;
        }
        if ($segmentEnd !== null && $turnStart !== null && $segmentEnd - $turnStart > self::TURN_MAX_DURATION_MS) {
            return false;
        }

        return $this->textLength(
            $this->appendText((string)($turn['text'] ?? ''), (string)($segment['text'] ?? ''))
        ) <= self::TURN_MAX_TEXT_CHARS;
    }

    /** @param array<string, mixed> $segment @return array<string, mixed> */
    private function newTurn(array $segment, int $turnIndex): array
    {
        $segmentId = trim((string)($segment['segment_id'] ?? ''));
        $resultId = max(0, (int)($segment['result_id'] ?? 0));
        $sourceLabel = trim((string)($segment['source_label'] ?? ''));
        $speaker = trim((string)($segment['speaker'] ?? ''));

        return [
            'turn_id' => $segmentId !== '' ? $segmentId : sprintf('call-turn:%d', $turnIndex),
            'index' => $turnIndex,
            'start_ms' => self::integerOrNull($segment['start_ms'] ?? null),
            'end_ms' => self::integerOrNull($segment['end_ms'] ?? null),
            'absolute_start_ms' => self::integerOrNull($segment['absolute_start_ms'] ?? null),
            'absolute_end_ms' => self::integerOrNull($segment['absolute_end_ms'] ?? null),
            'text' => trim((string)$segment['text']),
            'speaker' => $speaker,
            'channel' => isset($segment['channel']) ? (string)$segment['channel'] : null,
            'source_label' => $sourceLabel,
            'source_segment_ids' => $segmentId === '' ? [] : [$segmentId],
            'source_result_ids' => $resultId > 0 ? [$resultId] : [],
            '_identity' => self::identity($segment),
        ];
    }

    /** @param array<string, mixed> $turn @param array<string, mixed> $segment @return array<string, mixed> */
    private function append(array $turn, array $segment): array
    {
        foreach (['start_ms', 'absolute_start_ms'] as $field) {
            $value = self::integerOrNull($segment[$field] ?? null);
            if ($value !== null) {
                $current = self::integerOrNull($turn[$field] ?? null);
                $turn[$field] = $current === null ? $value : min($current, $value);
            }
        }
        foreach (['end_ms', 'absolute_end_ms'] as $field) {
            $value = self::integerOrNull($segment[$field] ?? null);
            if ($value !== null) {
                $current = self::integerOrNull($turn[$field] ?? null);
                $turn[$field] = $current === null ? $value : max($current, $value);
            }
        }

        $turn['text'] = $this->appendText((string)$turn['text'], (string)$segment['text']);
        $segmentId = trim((string)($segment['segment_id'] ?? ''));
        if ($segmentId !== '' && !in_array($segmentId, $turn['source_segment_ids'], true)) {
            $turn['source_segment_ids'][] = $segmentId;
        }
        $resultId = max(0, (int)($segment['result_id'] ?? 0));
        if ($resultId > 0 && !in_array($resultId, $turn['source_result_ids'], true)) {
            $turn['source_result_ids'][] = $resultId;
        }

        return $turn;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareSegments(array $left, array $right): int
    {
        $leftAbsolute = self::integerOrNull($left['absolute_start_ms'] ?? null);
        $rightAbsolute = self::integerOrNull($right['absolute_start_ms'] ?? null);
        $leftStart = self::integerOrNull($left['start_ms'] ?? null) ?? 0;
        $rightStart = self::integerOrNull($right['start_ms'] ?? null) ?? 0;

        return [
            $leftAbsolute === null ? 1 : 0,
            $leftAbsolute ?? (int)($left['part_order'] ?? 0),
            $leftAbsolute === null ? $leftStart : (self::integerOrNull($left['absolute_end_ms'] ?? null) ?? $leftAbsolute),
            (int)($left['_projection_order'] ?? 0),
        ] <=> [
            $rightAbsolute === null ? 1 : 0,
            $rightAbsolute ?? (int)($right['part_order'] ?? 0),
            $rightAbsolute === null ? $rightStart : (self::integerOrNull($right['absolute_end_ms'] ?? null) ?? $rightAbsolute),
            (int)($right['_projection_order'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $item */
    private static function timelineValue(array $item, string $edge): ?int
    {
        return self::integerOrNull($item['absolute_' . $edge . '_ms'] ?? null)
            ?? self::integerOrNull($item[$edge . '_ms'] ?? null);
    }

    /** @param array<string, mixed> $segment */
    private static function identity(array $segment): string
    {
        return implode("\0", [
            self::lower(trim((string)($segment['source_label'] ?? ''))),
            self::lower(trim((string)($segment['speaker'] ?? ''))),
            self::lower(trim((string)($segment['channel'] ?? ''))),
        ]);
    }

    private function appendText(string $left, string $right): string
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }

        return preg_match('/[\s([{]$/u', $left) === 1 ? $left . $right : $left . ' ' . $right;
    }

    /** @param array<int, array<string, mixed>> $turns */
    private function plainText(array $turns): string
    {
        $lines = [];
        foreach ($turns as $turn) {
            $text = trim((string)($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $channel = trim((string)($turn['channel'] ?? ''));
            $label = $channel !== '' ? $channel : trim((string)($turn['source_label'] ?? ''));
            $lines[] = $label === '' ? $text : sprintf('[%s] %s', $label, $text);
        }
        return implode("\n", $lines);
    }

    private function textLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function integerOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)
            ? (int)$value
            : null;
    }
}
