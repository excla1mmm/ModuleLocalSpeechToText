<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Core\System\Directories;

/**
 * Single source of truth for validating a recording file path against the
 * AST_MONITOR_DIR sandbox. Used both when a worker is served its assigned
 * recording (LocalSpeechToTextMain::safeRecordingPath) and when a job is
 * created via the management API (STTJobManagementService::create), so both
 * paths reject the same traversal/symlink/out-of-tree attempts.
 */
final class RecordingPathGuard
{
    /**
     * Resolves $path and returns its canonical form only if it is a
     * readable, non-symlink-containing file strictly inside
     * Directories::AST_MONITOR_DIR. Returns null otherwise.
     *
     * Rejecting a literal ".." path component and any symlink component
     * outright (rather than trusting realpath()'s final resolved location
     * alone) is deliberate defense in depth: it also blocks a same-tree
     * symlink swapped after validation but before the file is opened
     * (TOCTOU), which a resolved-location-only check would miss.
     */
    public static function resolve(string $path): ?string
    {
        if ($path === '' || self::containsTraversal($path) || self::hasSymlinkComponent($path)) {
            return null;
        }

        $base = realpath(Directories::getDir(Directories::AST_MONITOR_DIR));
        $canonical = realpath($path);
        if ($base === false || $canonical === false || !is_file($canonical) || !is_readable($canonical)) {
            return null;
        }

        $allowedPrefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($canonical !== $base && !str_starts_with($canonical, $allowedPrefix)) {
            return null;
        }

        return $canonical;
    }

    private static function containsTraversal(string $path): bool
    {
        return in_array('..', preg_split('~[\\\\/]~', $path) ?: [], true);
    }

    private static function hasSymlinkComponent(string $path): bool
    {
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR);
        $current = $absolute ? DIRECTORY_SEPARATOR : '';
        foreach (preg_split('~[\\\\/]~', $path) ?: [] as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $current = $current === '' ? $part : rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            // is_link() alone: it is false for a missing path without a PHP
            // warning (lstat() warned, the module raised it as an exception and
            // a deleted recording answered 500, which workers retry until the
            // lease times out, instead of file_not_found 404), and it is still
            // true for a dangling symlink.
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }
}
