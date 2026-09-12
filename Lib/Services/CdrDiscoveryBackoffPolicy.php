<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/**
 * Pure sequence policy for the CDR discovery scanner's idle backoff.
 *
 * Discovery (realtime + backfill CDR scanning) polls at a base interval and backs
 * off while idle so an inactive PBX does not hammer the CDR table. Any sign of
 * activity resets the delay immediately; housekeeping work is scheduled separately
 * from this policy and must not be gated by it.
 */
final class CdrDiscoveryBackoffPolicy
{
    public const int BASE_DELAY_SECONDS = 15;

    /** @var array<int, int> */
    private const array DELAY_STEPS = [15, 30, 60, 120, 180];

    public static function initialDelaySeconds(): int
    {
        return self::BASE_DELAY_SECONDS;
    }

    public static function delayAfterActivity(): int
    {
        return self::BASE_DELAY_SECONDS;
    }

    public static function delayAfterIdle(int $currentDelaySeconds): int
    {
        $index = array_search($currentDelaySeconds, self::DELAY_STEPS, true);
        if ($index === false) {
            return self::BASE_DELAY_SECONDS;
        }

        $nextIndex = min($index + 1, count(self::DELAY_STEPS) - 1);

        return self::DELAY_STEPS[$nextIndex];
    }
}
