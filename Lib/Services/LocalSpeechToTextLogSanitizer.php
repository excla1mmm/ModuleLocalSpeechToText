<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Throwable;

final class LocalSpeechToTextLogSanitizer
{
    private const int MAX_MESSAGE_BYTES = 4000;
    private const int MAX_CONTEXT_STRING_BYTES = 512;
    private const int MAX_CONTEXT_FIELDS = 32;
    private const string REDACTED = '[REDACTED]';
    private const string SENSITIVE_MESSAGE_KEY_PATTERN =
        '(?:authorization|token|[a-z0-9]+[_-]?token|api[_-]?key|worker[_-]?api[_-]?key|'
        . 'client[_-]?secret|secret|password|credential|prompt(?:[_-]?(?:text|content))?|'
        . 'transcript(?:[_-]?(?:text|content))?|payload(?:[_-]?(?:text|content))?|'
        . 'raw[_-]?(?:request|response)|capability[_-]?payload|monitor[_-]?path|'
        . 'recording[_-]?(?:path|file)|audio|path|file(?:name)?|phone|number|extension|caller(?:[_-]?id)?|'
        . 'callee(?:[_-]?id)?|did|src(?:[_-]?num)?|dst(?:[_-]?num)?|'
        . '(?:source|destination)[_-]?number|text[_-]?hash|diagnostic[_-]?hash|'
        . '(?:api[_-]?)?key[_-]?(?:hash|suffix))';

    public static function message(string $message): string
    {
        $encodedUtf8 = json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($encodedUtf8)) {
            $decodedUtf8 = json_decode($encodedUtf8, true);
            if (is_string($decodedUtf8)) {
                $message = $decodedUtf8;
            }
        }
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $unicodeCleaned = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $message);
        if (is_string($unicodeCleaned)) {
            $message = $unicodeCleaned;
        }
        $message = preg_replace(
            '/(authorization\s*[:=]\s*)(?:bearer|basic)\s+[^\s,;]+/i',
            '$1' . self::REDACTED,
            $message
        ) ?? $message;
        $message = preg_replace(
            '/\b(?:bearer|basic)\s+[a-z0-9+\/=._~-]+/i',
            self::REDACTED,
            $message
        ) ?? $message;
        $message = preg_replace(
            '/([?&](?:token|[a-z0-9]+[_-]?token|api[_-]?key|worker[_-]?api[_-]?key|client[_-]?secret|secret|password|credential)=)[^&#\s]*/i',
            '$1' . self::REDACTED,
            $message
        ) ?? $message;
        $message = preg_replace(
            '/("' . self::SENSITIVE_MESSAGE_KEY_PATTERN . '"\s*:\s*)[\{\[].*$/i',
            '$1"' . self::REDACTED . '"',
            $message
        ) ?? $message;
        $message = preg_replace(
            '/("' . self::SENSITIVE_MESSAGE_KEY_PATTERN . '"\s*:\s*)"(?:\\\\.|[^"\\\\])*"/i',
            '$1"' . self::REDACTED . '"',
            $message
        ) ?? $message;
        $message = preg_replace(
            '/\b(' . self::SENSITIVE_MESSAGE_KEY_PATTERN . ')\s*[:=]\s*'
                . '(?<quote>["\'])(?:\\\\.|(?!\k<quote>).)*\k<quote>/i',
            '$1=' . self::REDACTED,
            $message
        ) ?? $message;
        $message = preg_replace(
            '/\b(' . self::SENSITIVE_MESSAGE_KEY_PATTERN . ')\s*[:=]\s*[^,;&]+/i',
            '$1=' . self::REDACTED,
            $message
        ) ?? $message;
        $message = preg_replace(
            '#(?<![a-z0-9_:/\\\\])(?:/[^\r\n,;)\]}]+|[a-z]:\\\\[^\r\n,;)\]}]+|\\\\\\\\[^\r\n,;)\]}]+)#i',
            '[PATH]',
            $message
        ) ?? $message;
        $message = preg_replace(
            '#\b([a-z][a-z0-9+.-]*://)[^/@\s]+@#i',
            '$1' . self::REDACTED . '@',
            $message
        ) ?? $message;
        $message = preg_replace(
            '/(?<!\d)(?:\+?\d[\s().-]*){7,}\d(?!\d)/',
            '[PHONE]',
            $message
        ) ?? $message;

        $message = trim(preg_replace('/\s{2,}/', ' ', $message) ?? $message);

        return self::truncate($message, self::MAX_MESSAGE_BYTES);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<string, bool|float|int|string|null>
     */
    public static function context(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (count($safe) >= self::MAX_CONTEXT_FIELDS) {
                break;
            }

            $key = strtolower(trim((string)$key));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || (!is_scalar($value) && $value !== null)
            ) {
                continue;
            }
            if (self::isSensitiveKey($key)) {
                $safe[$key] = self::REDACTED;
                continue;
            }

            $safe[$key] = is_string($value)
                ? self::truncate(self::message($value), self::MAX_CONTEXT_STRING_BYTES)
                : $value;
        }

        return $safe;
    }

    public static function errorCode(Throwable|string $error): string
    {
        $value = $error instanceof Throwable ? get_class($error) : $error;
        $parts = explode('\\', $value);
        $value = (string)end($parts);
        $value = trim($value);
        $value = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $value) ?? $value;
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value === '' ? 'operation_failed' : substr($value, 0, 64);
    }

    private static function isSensitiveKey(string $key): bool
    {
        if (in_array($key, ['api_key_id', 'worker_api_key_id'], true)) {
            return false;
        }

        return preg_match(
            '/(?:^|_)(?:authorization|api_?key|token|secret|password|credential|prompt|transcript|payload|raw_request|raw_response|capability_payload|monitor_path|recording_path|recording_file|audio|path|phone|number|extension|caller|callee|did|src|dst|text_hash|diagnostic_hash|key_hash|key_suffix)(?:_|$)/D',
            $key
        ) === 1;
    }

    private static function truncate(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, max(0, $maxBytes - 3)) . '...';
    }
}
