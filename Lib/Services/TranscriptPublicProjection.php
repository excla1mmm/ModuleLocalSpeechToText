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
            $projected['speaker'] = $this->nonEmptyScalar($item['speaker'] ?? null)
                ?? $this->nonEmptyScalar($item['channel'] ?? null);
            $items[] = $projected;
        }

        return $items;
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
