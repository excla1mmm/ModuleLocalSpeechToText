<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use Throwable;

class LocalSpeechToTextLogger
{
    private const string MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText';

    public static function write(string $channel, string $message, int $level = LOG_INFO): void
    {
        $filePath = self::logDir() . '/' . self::sanitizeChannel($channel) . '.log';
        $line = sprintf(
            "%s %-5s [%s] %s\n",
            date('Y-m-d H:i:s'),
            self::levelName($level),
            $channel,
            self::normalizeMessage($message)
        );

        if (!self::ensureWritableDirectory(dirname($filePath))) {
            return;
        }

        [$bytesWritten, $warning] = self::captureFilesystemWarning(
            static fn (): int|false => file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX)
        );

        if (!is_int($bytesWritten)) {
            self::reportFileIoError('write log file', $filePath, $warning);
            return;
        }

        self::chmodPath($filePath, 0644);
    }

    public static function syslog(string $ident, string $message, int $level = LOG_INFO): void
    {
        self::write(self::channelFromIdent($ident), $message, $level);
        try {
            SystemMessages::sysLogMsg($ident, $message, $level);
        } catch (Throwable $exception) {
            self::write(
                'logger',
                sprintf('Syslog mirror failed for %s: %s', $ident, $exception->getMessage()),
                LOG_WARNING
            );
        }
    }

    public static function exception(string $context, Throwable $exception): void
    {
        self::syslog(
            'LocalSpeechToTextMain',
            sprintf('%s failed: %s', $context, $exception->getMessage()),
            LOG_ERR
        );
    }

    private static function logDir(): string
    {
        return rtrim(Directories::getDir(Directories::CORE_LOGS_DIR), '/') . '/' . self::MODULE_UNIQUE_ID;
    }

    private static function ensureWritableDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            if (is_writable($dir)) {
                return true;
            }

            self::reportFileIoError('write to log directory', $dir, 'directory is not writable');
            return false;
        }

        if (file_exists($dir)) {
            self::reportFileIoError('create log directory', $dir, 'path exists and is not a directory');
            return false;
        }

        [$created, $warning] = self::captureFilesystemWarning(
            static fn (): bool => mkdir($dir, 0755, true)
        );

        if ($created === true || is_dir($dir)) {
            self::chmodPath($dir, 0755);

            return is_writable($dir);
        }

        self::reportFileIoError('create log directory', $dir, $warning);
        return false;
    }

    private static function channelFromIdent(string $ident): string
    {
        $parts = explode('\\', trim($ident));
        $channel = end($parts);

        return is_string($channel) && $channel !== '' ? $channel : 'module';
    }

    private static function sanitizeChannel(string $channel): string
    {
        $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_.-]+/', '-', trim($channel)) ?? '');
        $sanitized = trim($sanitized, '-.');

        return $sanitized === '' ? 'module' : $sanitized;
    }

    private static function normalizeMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return function_exists('mb_substr') ? mb_substr($message, 0, 4000) : substr($message, 0, 4000);
    }

    private static function levelName(int $level): string
    {
        return match ($level) {
            LOG_ERR, LOG_CRIT, LOG_ALERT, LOG_EMERG => 'ERROR',
            LOG_WARNING => 'WARN',
            LOG_DEBUG => 'DEBUG',
            LOG_NOTICE => 'NOTICE',
            default => 'INFO',
        };
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return array{0: T|null, 1: string|null}
     */
    private static function captureFilesystemWarning(callable $operation): array
    {
        $warning = null;
        $result = null;

        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if (($severity & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) === 0) {
                return false;
            }

            $warning = $message;
            return true;
        });

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $warning = $exception->getMessage();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }

    private static function reportFileIoError(string $action, string $path, ?string $reason = null): void
    {
        $message = sprintf('%s: unable to %s "%s"', self::MODULE_UNIQUE_ID, $action, $path);
        if ($reason !== null && $reason !== '') {
            $message .= ': ' . $reason;
        }

        error_log($message);
    }

    private static function chmodPath(string $path, int $permissions): void
    {
        if (!file_exists($path)) {
            return;
        }

        [$changed, $warning] = self::captureFilesystemWarning(
            static fn (): bool => chmod($path, $permissions)
        );

        if ($changed !== true) {
            self::reportFileIoError('change permissions for', $path, $warning);
        }
    }
}
