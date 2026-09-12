<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class TranscriptSearchText
{
    public static function fromTranscript(string $transcript): string
    {
        $decoded = json_decode($transcript, true);
        if (!is_array($decoded)) {
            return self::normalize($transcript);
        }

        $text = [];
        foreach ($decoded as $segment) {
            if (is_array($segment) && is_string($segment['text'] ?? null)) {
                $text[] = $segment['text'];
            }
        }

        return self::normalize(implode(' ', $text));
    }

    public static function query(string $query): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', self::normalize($query), $matches);
        $tokens = array_slice(array_values(array_unique($matches[0] ?? [])), 0, 16);

        return implode(' AND ', array_map(
            static fn (string $token): string => '"' . $token . '"*',
            $tokens
        ));
    }

    private static function normalize(string $value): string
    {
        $value = str_replace('ё', 'е', mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
