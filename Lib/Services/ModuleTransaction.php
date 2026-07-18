<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use RuntimeException;
use Throwable;

final class ModuleTransaction
{
    private const int RETRY_DELAY_MICROSECONDS = 25_000;

    public function __construct(private readonly mixed $sleeper = null)
    {
        if ($this->sleeper !== null && !is_callable($this->sleeper)) {
            throw new RuntimeException('Module transaction sleeper must be callable');
        }
    }

    public function run(mixed $connection, callable $operation, int $busyRetries = 2): mixed
    {
        if ($connection->isUnderTransaction()) {
            throw new RuntimeException('Unexpected ambient module database transaction');
        }

        $attempt = 0;
        $busyRetries = max(0, $busyRetries);
        do {
            try {
                if ($connection->begin() !== true || !$connection->isUnderTransaction()) {
                    throw new RuntimeException('Unable to begin module database transaction');
                }

                $result = $operation($connection);
                if ($connection->commit() !== true || $connection->isUnderTransaction()) {
                    throw new RuntimeException('Unable to commit module database transaction');
                }

                return $result;
            } catch (Throwable $exception) {
                $this->recover($connection);
                if (!$this->isBusy($exception) || $attempt >= $busyRetries) {
                    throw $exception;
                }

                $attempt++;
                $this->sleep(self::RETRY_DELAY_MICROSECONDS * $attempt);
            }
        } while (true);
    }

    public function recover(mixed $connection): void
    {
        if (!$connection->isUnderTransaction()) {
            return;
        }

        try {
            $rolledBack = $connection->rollback();
        } catch (Throwable $exception) {
            throw new TransactionRecoveryException(
                'Module database rollback threw an exception',
                0,
                $exception
            );
        }

        if ($rolledBack !== true || $connection->isUnderTransaction()) {
            throw new TransactionRecoveryException('Module database rollback did not clear the transaction');
        }
    }

    private function isBusy(Throwable $exception): bool
    {
        if (in_array((int)$exception->getCode(), [5, 6], true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());
        foreach ([
            'database is locked',
            'database table is locked',
            'sqlite_busy',
            'sqlite_locked',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sleep(int $microseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($microseconds);
            return;
        }

        usleep($microseconds);
    }
}
