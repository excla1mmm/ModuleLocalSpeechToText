<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class TranscriptPublicProjection
{
    /**
     * @param array<string, mixed> $transcript
     * @return array<string, mixed>
     */
    public function project(array $transcript): array
    {
        $transcript['segments'] = $this->items(
            $transcript['segments'] ?? [],
            ['segment_id', 'index', 'start_ms', 'end_ms', 'text']
        );
        $transcript['turns'] = $this->items(
            $transcript['turns'] ?? [],
            ['turn_id', 'index', 'start_ms', 'end_ms', 'text']
        );
        $channelText = $this->channelText($transcript['turns']);
        if ($channelText !== null) {
            $transcript['text'] = $channelText;
        }
        unset($transcript['segment_count'], $transcript['turn_count']);

        return $transcript;
    }

    /** @param mixed $value @param list<string> $fields @return list<array<string, mixed>> */
    private function items(mixed $value, array $fields): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $projected = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $item)) {
                    $projected[$field] = $item[$field];
                }
            }
            $projected['channel'] = $this->nonEmptyScalar($item['channel'] ?? null);
            $items[] = $projected;
        }

        return $items;
    }

    /** @param list<array<string, mixed>> $turns */
    private function channelText(array $turns): ?string
    {
        $lines = [];
        $hasChannel = false;
        foreach ($turns as $turn) {
            $text = trim((string)($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $channel = $this->nonEmptyScalar($turn['channel'] ?? null);
            $hasChannel = $hasChannel || $channel !== null;
            $lines[] = $channel === null ? $text : sprintf('[%s] %s', $channel, $text);
        }

        return $hasChannel ? implode("\n", $lines) : null;
    }

    private function nonEmptyScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
