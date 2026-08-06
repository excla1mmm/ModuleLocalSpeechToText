<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use RuntimeException;
use Throwable;

final class ScannerIterationFailureHandler
{
    private readonly ScannerTransactionRecovery $transactionRecovery;
    private readonly ScannerDatabaseFailurePolicy $failurePolicy;

    public function __construct(
        ?ScannerTransactionRecovery $transactionRecovery = null,
        ?ScannerDatabaseFailurePolicy $failurePolicy = null
    ) {
        $this->transactionRecovery = $transactionRecovery ?? new ScannerTransactionRecovery();
        $this->failurePolicy = $failurePolicy ?? new ScannerDatabaseFailurePolicy();
    }

    /**
     * @return array{
     *     database_error_kind: string,
     *     recovery_action: string,
     *     recovery_succeeded: bool,
     *     consecutive_database_failures: int,
     *     recovery_exception: ?TransactionRecoveryException
     * }
     */
    public function handle(
        Throwable $exception,
        callable $connectionsProvider,
        int $previousConsecutiveFailures
    ): array {
        $databaseErrorKind = $this->failurePolicy->classify($exception);
        $consecutiveFailures = $databaseErrorKind === ScannerDatabaseFailurePolicy::KIND_NOT_PDO
            ? 0
            : max(0, $previousConsecutiveFailures) + 1;
        $recoveryException = null;

        try {
            $connections = $connectionsProvider();
            if (!is_array($connections)) {
                throw new RuntimeException('Scanner connection provider must return an array');
            }
            $this->transactionRecovery->recover($connections);
        } catch (TransactionRecoveryException $caught) {
            $recoveryException = $caught;
        } catch (Throwable $caught) {
            $recoveryException = new TransactionRecoveryException(
                'Scanner database recovery failed',
                0,
                $caught
            );
        }

        $recoverySucceeded = $recoveryException === null;

        return [
            'database_error_kind' => $databaseErrorKind,
            'recovery_action' => $this->failurePolicy->recoveryAction(
                $databaseErrorKind,
                $consecutiveFailures,
                $recoverySucceeded
            ),
            'recovery_succeeded' => $recoverySucceeded,
            'consecutive_database_failures' => $consecutiveFailures,
            'recovery_exception' => $recoveryException,
        ];
    }
}
