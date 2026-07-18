<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class ScannerTransactionRecovery
{
    /**
     * @param array<int, mixed> $connections
     */
    public function recover(array $connections): void
    {
        $seen = [];
        $failures = [];
        $transaction = new ModuleTransaction();

        foreach ($connections as $connection) {
            if (!is_object($connection)) {
                continue;
            }

            $objectId = spl_object_id($connection);
            if (isset($seen[$objectId])) {
                continue;
            }
            $seen[$objectId] = true;

            try {
                $transaction->recover($connection);
            } catch (TransactionRecoveryException $exception) {
                $failures[] = $exception;
            }
        }

        if ($failures !== []) {
            throw new TransactionRecoveryException(
                sprintf(
                    'Failed to recover %d scanner database connection(s)',
                    count($failures)
                ),
                0,
                $failures[0]
            );
        }
    }
}
