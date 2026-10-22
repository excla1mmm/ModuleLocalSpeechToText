<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/**
 * Optional features a worker declares on registration (`capabilities`) and on
 * every job lease (`worker_capabilities`). A worker that declares nothing is
 * an older one that supports none of them. Unknown names are kept, so a newer
 * worker's features are visible to the admin before the module learns them.
 */
final class WorkerCapabilities
{
    public const string CALL_SUMMARIES = 'call-summaries';

    private const int MAX_ITEMS = 32;
    private const string NAME_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,63}\z/';

    /**
     * The declared list, cleaned: valid names only, unique, sorted. Anything
     * that is not a list declares nothing.
     *
     * @return list<string>
     */
    public static function fromRequest(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $names = [];
        foreach ($value as $name) {
            if (is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1) {
                $names[$name] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return array_slice($names, 0, self::MAX_ITEMS);
    }

    /** @param list<string> $names */
    public static function encode(array $names): string
    {
        return $names === [] ? '' : implode(',', $names);
    }

    /** @return list<string> */
    public static function decode(?string $stored): array
    {
        $stored = trim((string)$stored);

        return $stored === '' ? [] : self::fromRequest(explode(',', $stored));
    }
}
