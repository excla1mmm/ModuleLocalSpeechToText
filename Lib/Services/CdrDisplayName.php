<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/**
 * Normalizes the display name Asterisk recorded alongside a CDR row
 * (`CALLERID(name)`, exposed as `src_name`/`dst_name`), so an external
 * counterparty can be labelled with the company the phone book knows instead
 * of showing a bare number.
 *
 * On an inbound call that name is chosen by the remote side, so it is treated
 * as untrusted display text: control characters collapse to spaces and the
 * result is capped. A name that merely repeats its own number is Asterisk's
 * default rather than an identity, and is discarded so the caller falls back
 * to printing the number alone.
 */
final class CdrDisplayName
{
    private const int MAXIMUM_LENGTH = 120;

    private function __construct()
    {
    }

    /** Returns the usable display name for $number, or '' when there is none. */
    public static function forNumber(string $rawName, string $number): string
    {
        $name = self::sanitize($rawName);

        return $name === '' || $name === trim($number) ? '' : $name;
    }

    private static function sanitize(string $raw): string
    {
        $withoutControls = preg_replace('/[\p{C}]+/u', ' ', $raw);
        $collapsed = preg_replace('/\s+/u', ' ', $withoutControls ?? '');
        $trimmed = trim($collapsed ?? '');

        return mb_strlen($trimmed) > self::MAXIMUM_LENGTH
            ? rtrim(mb_substr($trimmed, 0, self::MAXIMUM_LENGTH)) . '…'
            : $trimmed;
    }
}
