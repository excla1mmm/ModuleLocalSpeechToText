<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/**
 * Runs a filesystem/process call whose return value already communicates
 * success or failure (unlink, rename, mkdir, proc_open, proc_terminate...)
 * without PHP's E_WARNING noise for the expected failure case (file already
 * gone, directory already exists, process already exited, etc.).
 *
 * Used instead of the "@" error-suppression operator, which silences ALL
 * errors raised anywhere during the call (not just the expected one) and
 * leaves no trace for debugging. A scoped error handler suppresses exactly
 * the same class of diagnostic (warnings from the wrapped call) while still
 * letting an actual exception propagate normally.
 */
final class QuietOperation
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        set_error_handler(static function (): bool {
            // The wrapped call's return value (false/null) is how the
            // caller already detects failure; swallow only the warning.
            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
