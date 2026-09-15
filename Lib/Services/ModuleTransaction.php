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

    /**
     * Runs $operation inside a SAVEPOINT nested within an ambient transaction
     * the caller does not own (and therefore cannot begin/commit/rollback
     * itself). Any Throwable rolls back only this savepoint -- not the whole
     * ambient transaction -- before rethrowing, so callers can catch specific
     * failures and still let the ambient transaction commit whatever they
     * do next (e.g. a compensating write) without also committing the
     * partial work $operation left behind.
     */
    public function runNested(mixed $connection, callable $operation, string $savepoint): mixed
    {
        if (!$connection->isUnderTransaction()) {
            throw new RuntimeException('Nested module database savepoint requires an ambient transaction');
        }
        self::assertSavepointName($savepoint);

        if ($connection->execute("SAVEPOINT {$savepoint}") !== true) {
            throw new RuntimeException('Unable to create module database savepoint');
        }

        try {
            $result = $operation($connection);
        } catch (Throwable $exception) {
            $this->rollbackToSavepoint($connection, $savepoint);
            throw $exception;
        }

        if ($connection->execute("RELEASE SAVEPOINT {$savepoint}") !== true) {
            throw new RuntimeException('Unable to release module database savepoint');
        }

        return $result;
    }

    private function rollbackToSavepoint(mixed $connection, string $savepoint): void
    {
        try {
            $rolledBack = $connection->execute("ROLLBACK TO SAVEPOINT {$savepoint}");
        } catch (Throwable $exception) {
            throw new TransactionRecoveryException(
                'Module database savepoint rollback threw an exception',
                0,
                $exception
            );
        }
        if ($rolledBack !== true) {
            throw new TransactionRecoveryException('Module database savepoint rollback failed');
        }

        if ($connection->execute("RELEASE SAVEPOINT {$savepoint}") !== true) {
            throw new TransactionRecoveryException('Module database savepoint release after rollback failed');
        }
    }

    private static function assertSavepointName(string $savepoint): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $savepoint) !== 1) {
            throw new RuntimeException('Module database savepoint name is invalid');
        }
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
