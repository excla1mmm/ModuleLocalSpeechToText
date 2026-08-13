<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class LocalSpeechToTextSyslogFormatter
{
    public const string MODULE_TAG = 'ModuleLocalSpeechToText';
    private const int MAX_LINE_BYTES = 8192;
    private const int MAX_CONTEXT_FIELDS = 32;
    private const int MAX_CONTEXT_STRING_BYTES = 512;

    /** @param array<array-key, mixed> $context */
    public static function format(
        string $component,
        string $event,
        string $message,
        int $level = LOG_INFO,
        array $context = [],
        ?int $timestamp = null,
        ?int $pid = null
    ): string {
        return self::formatSeverity(
            $component,
            $event,
            $message,
            self::severity($level),
            $context,
            $timestamp,
            $pid
        );
    }

    /** @param array<array-key, mixed> $context */
    public static function formatSeverity(
        string $component,
        string $event,
        string $message,
        string $severity,
        array $context = [],
        ?int $timestamp = null,
        ?int $pid = null
    ): string {
        $component = self::machineKey($component, 'system');
        $event = self::machineKey($event, 'event');
        $message = LocalSpeechToTextLogSanitizer::message($message);
        $context = LocalSpeechToTextLogSanitizer::context($context);
        ksort($context, SORT_STRING);
        $context = array_slice($context, 0, self::MAX_CONTEXT_FIELDS, true);
        foreach ($context as $key => $value) {
            if (is_string($value) && strlen($value) > self::MAX_CONTEXT_STRING_BYTES) {
                $context[$key] = substr($value, 0, self::MAX_CONTEXT_STRING_BYTES - 3) . '...';
            }
        }
        if (!in_array($severity, ['debug', 'info', 'notice', 'warning', 'err', 'crit', 'alert', 'emerg'], true)) {
            $severity = 'info';
        }

        $prefix = sprintf(
            '%s daemon.%s %s[%d]: [%s.%s] %s',
            date('Y-m-d H:i:s', $timestamp ?? time()),
            $severity,
            self::MODULE_TAG,
            max(0, $pid ?? getmypid() ?: 0),
            $component,
            $event,
            $message
        );
        // Preserve literal message suffixes that look like the structured trailer.
        $forceEmptyContext = $context === [] && self::hasContextLikeSuffix($message);
        $line = self::appendContext($prefix, $context, $forceEmptyContext);
        while (strlen($line) > self::MAX_LINE_BYTES && $context !== []) {
            array_pop($context);
            $line = self::appendContext($prefix, $context, false);
        }

        return substr($line, 0, self::MAX_LINE_BYTES);
    }

    /** @param array<string, bool|float|int|string|null> $context */
    private static function appendContext(string $prefix, array $context, bool $forceEmpty = false): string
    {
        if ($context !== [] || $forceEmpty) {
            $encoded = $forceEmpty && $context === []
                ? '{}'
                : json_encode(
                    $context,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
            if (is_string($encoded)) {
                return $prefix . ' context=' . $encoded;
            }
        }

        return $prefix;
    }

    private static function hasContextLikeSuffix(string $message): bool
    {
        if (preg_match('/ context=(\{.*\})$/D', $message, $matches) !== 1) {
            return false;
        }
        $decoded = json_decode($matches[1], true);

        return is_array($decoded);
    }

    private static function machineKey(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value === '' ? $fallback : substr($value, 0, 64);
    }

    private static function severity(int $level): string
    {
        return match ($level) {
            LOG_DEBUG => 'debug',
            LOG_NOTICE => 'notice',
            LOG_WARNING => 'warning',
            LOG_ERR => 'err',
            LOG_CRIT => 'crit',
            LOG_ALERT => 'alert',
            LOG_EMERG => 'emerg',
            default => 'info',
        };
    }
}
