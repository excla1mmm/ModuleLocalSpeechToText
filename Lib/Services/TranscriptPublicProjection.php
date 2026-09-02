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
        foreach (['segments', 'turns'] as $collection) {
            if (!is_array($transcript[$collection] ?? null)) {
                continue;
            }

            $items = [];
            foreach ($transcript[$collection] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $speaker = $this->nonEmptyScalar($item['speaker'] ?? null)
                    ?? $this->nonEmptyScalar($item['channel'] ?? null);
                unset($item['start'], $item['end'], $item['channel']);
                $item['speaker'] = $speaker;
                $items[] = $item;
            }
            $transcript[$collection] = $items;
        }

        return $transcript;
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
