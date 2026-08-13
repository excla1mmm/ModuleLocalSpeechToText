<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Throwable;

final class LocalSpeechToTextSyslogParser
{
    /**
     * @return array{
     *   timestamp:string,facility:string,severity:string,level:string,tag:string,pid:int,
     *   component:string,event:string,message:string,context:array<string, scalar|null>
     * }|null
     */
    public static function parse(string $line): ?array
    {
        $line = rtrim($line, "\r\n");
        if (strlen($line) > 8192) {
            return null;
        }
        $pattern = '/^(?<timestamp>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) '
            . '(?<facility>daemon)\.(?<severity>debug|info|notice|warning|err|crit|alert|emerg) '
            . '(?<tag>ModuleLocalSpeechToText)\[(?<pid>\d+)\]: '
            . '\[(?<component>[a-z0-9_]{1,64})\.(?<event>[a-z0-9_]{1,64})\] (?<payload>.*)$/D';
        if (preg_match($pattern, $line, $matches) !== 1
            || strtotime($matches['timestamp']) === false
        ) {
            return null;
        }

        $message = $matches['payload'];
        $context = [];
        $offset = 0;
        while (($delimiter = strpos($message, ' context={', $offset)) !== false) {
            $encodedContext = substr($message, $delimiter + strlen(' context='));
            try {
                $decoded = json_decode($encodedContext, true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $decoded = null;
            }
            if (is_array($decoded) && self::isScalarContext($decoded)) {
                $message = substr($message, 0, $delimiter);
                $context = $decoded;
                break;
            }
            $offset = $delimiter + 1;
        }

        $severity = $matches['severity'];

        return [
            'timestamp' => $matches['timestamp'],
            'facility' => $matches['facility'],
            'severity' => $severity,
            'level' => in_array($severity, ['err', 'crit', 'alert', 'emerg'], true) ? 'error' : $severity,
            'tag' => $matches['tag'],
            'pid' => (int)$matches['pid'],
            'component' => $matches['component'],
            'event' => $matches['event'],
            'message' => $message,
            'context' => $context,
        ];
    }

    /** @param array<array-key, mixed> $context */
    private static function isScalarContext(array $context): bool
    {
        foreach ($context as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string)$key) !== 1
                || (!is_scalar($value) && $value !== null)
            ) {
                return false;
            }
        }

        return true;
    }
}
