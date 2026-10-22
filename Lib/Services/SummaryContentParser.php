<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/**
 * Turns the raw assistant text a worker returned into a topic and a summary.
 * The request carries a JSON schema, but the model output is still untrusted:
 * it is unwrapped from Markdown fences, stripped of markup and bounded.
 */
final class SummaryContentParser
{
    public const int MAX_TOPIC_CHARS = 200;
    public const int MAX_SUMMARY_CHARS = 1500;
    private const int MAX_CONTENT_BYTES = 65536;

    /** @return array{topic: string, summary: string}|null null when the content is unusable */
    public static function parse(string $content): ?array
    {
        $decoded = self::decodeObject($content);
        if ($decoded === null) {
            return null;
        }

        $topic = self::clean($decoded['topic'] ?? null, self::MAX_TOPIC_CHARS);
        $summary = self::clean($decoded['summary'] ?? null, self::MAX_SUMMARY_CHARS);
        if ($summary === '') {
            return null;
        }

        return ['topic' => rtrim($topic, '.'), 'summary' => $summary];
    }

    /** @return array<string, mixed>|null */
    private static function decodeObject(string $content): ?array
    {
        // max_tokens keeps a real answer far below this; anything larger is
        // not parsed at all.
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            return null;
        }
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content) ?? $content;
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function clean(mixed $value, int $maxChars): string
    {
        if (!is_string($value)) {
            return '';
        }
        $text = strip_tags($value);
        // Markdown emphasis and headings the model sometimes adds anyway.
        $text = preg_replace('/(\*\*|__|^#+\s*)/mu', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars - 1)) . '…' : $text;
    }
}
