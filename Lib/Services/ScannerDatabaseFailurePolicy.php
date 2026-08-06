<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDOException;
use Throwable;

final class ScannerDatabaseFailurePolicy
{
    public const string KIND_NOT_PDO = 'not_pdo';
    public const string KIND_BUSY = 'busy';
    public const string KIND_LOCKED = 'locked';
    public const string KIND_READONLY = 'readonly';
    public const string KIND_IO = 'io';
    public const string KIND_CORRUPT = 'corrupt';
    public const string KIND_CANTOPEN = 'cantopen';
    public const string KIND_NOTADB = 'notadb';
    public const string KIND_UNKNOWN_PDO = 'unknown_pdo';
    public const string ACTION_CONTINUE = 'continue';
    public const string ACTION_RECYCLE = 'recycle';
    public const int BUSY_RECYCLE_THRESHOLD = 3;

    public function classify(Throwable $exception): string
    {
        $pdoException = $this->findPdoException($exception);
        if ($pdoException === null) {
            return self::KIND_NOT_PDO;
        }

        $numericCode = $this->numericCode($pdoException);
        if ($numericCode !== null) {
            $kind = $this->kindForSqliteCode($numericCode & 0xff);
            if ($kind !== null) {
                return $kind;
            }
        }

        return $this->kindForMessage($pdoException->getMessage()) ?? self::KIND_UNKNOWN_PDO;
    }

    public function recoveryAction(
        string $kind,
        int $consecutiveFailures,
        bool $recoverySucceeded
    ): string {
        if (!$recoverySucceeded) {
            return self::ACTION_RECYCLE;
        }
        if ($kind === self::KIND_NOT_PDO) {
            return self::ACTION_CONTINUE;
        }
        if (in_array($kind, [self::KIND_BUSY, self::KIND_LOCKED], true)) {
            return $consecutiveFailures >= self::BUSY_RECYCLE_THRESHOLD
                ? self::ACTION_RECYCLE
                : self::ACTION_CONTINUE;
        }

        return self::ACTION_RECYCLE;
    }

    private function findPdoException(Throwable $exception): ?PDOException
    {
        do {
            if ($exception instanceof PDOException) {
                return $exception;
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return null;
    }

    private function numericCode(PDOException $exception): ?int
    {
        $errorInfoCode = $exception->errorInfo[1] ?? null;
        if (is_int($errorInfoCode) || is_string($errorInfoCode) && is_numeric($errorInfoCode)) {
            return (int)$errorInfoCode;
        }

        $code = $exception->getCode();
        if (is_int($code) || is_string($code) && is_numeric($code)) {
            return (int)$code;
        }

        return null;
    }

    private function kindForSqliteCode(int $code): ?string
    {
        return match ($code) {
            5 => self::KIND_BUSY,
            6 => self::KIND_LOCKED,
            8 => self::KIND_READONLY,
            10 => self::KIND_IO,
            11 => self::KIND_CORRUPT,
            14 => self::KIND_CANTOPEN,
            26 => self::KIND_NOTADB,
            default => null,
        };
    }

    private function kindForMessage(string $message): ?string
    {
        $message = strtolower($message);

        return match (true) {
            str_contains($message, 'sqlite_busy'), str_contains($message, 'database is busy') => self::KIND_BUSY,
            str_contains($message, 'database table is locked') => self::KIND_LOCKED,
            str_contains($message, 'attempt to write a readonly database') => self::KIND_READONLY,
            str_contains($message, 'disk i/o error') => self::KIND_IO,
            str_contains($message, 'database disk image is malformed') => self::KIND_CORRUPT,
            str_contains($message, 'unable to open database file') => self::KIND_CANTOPEN,
            str_contains($message, 'file is not a database') => self::KIND_NOTADB,
            default => null,
        };
    }
}
