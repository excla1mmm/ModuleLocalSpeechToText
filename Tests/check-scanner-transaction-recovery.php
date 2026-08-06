<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerTransactionRecovery;
use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerIterationFailureHandler;
use Modules\ModuleLocalSpeechToText\Lib\Services\TransactionRecoveryException;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/ScannerTransactionRecovery.php';
require_once __DIR__ . '/../Lib/Services/ScannerDatabaseFailurePolicy.php';
$iterationFailureHandler = __DIR__ . '/../Lib/Services/ScannerIterationFailureHandler.php';
if (is_file($iterationFailureHandler)) {
    require_once $iterationFailureHandler;
}

final class ScannerConnectionProbe
{
    public int $rollbackCalls = 0;

    public function __construct(
        private bool $active,
        private readonly bool $rollbackResult = true,
        private readonly bool $throwOnRollback = false
    ) {
    }

    public function isUnderTransaction(): bool
    {
        return $this->active;
    }

    public function rollback(): bool
    {
        $this->rollbackCalls++;
        if ($this->throwOnRollback) {
            throw new RuntimeException('rollback exploded');
        }
        if ($this->rollbackResult) {
            $this->active = false;
        }

        return $this->rollbackResult;
    }
}

function expectScannerRecovery(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$active = new ScannerConnectionProbe(true);
$inactive = new ScannerConnectionProbe(false);
(new ScannerTransactionRecovery())->recover([$active, $active, $inactive, null, 'invalid']);
expectScannerRecovery($active->rollbackCalls === 1, 'duplicate active connection must be rolled back once');
expectScannerRecovery($inactive->rollbackCalls === 0, 'inactive connection must not be rolled back');

$failed = new ScannerConnectionProbe(true, false);
$second = new ScannerConnectionProbe(true);
try {
    (new ScannerTransactionRecovery())->recover([$failed, $second]);
    throw new RuntimeException('Expected scanner recovery failure was not thrown');
} catch (TransactionRecoveryException $exception) {
    expectScannerRecovery(
        str_contains($exception->getMessage(), '1 scanner database connection'),
        'scanner recovery failure count is missing'
    );
}
expectScannerRecovery($failed->rollbackCalls === 1, 'failed connection was not attempted');
expectScannerRecovery($second->rollbackCalls === 1, 'recovery stopped before checking remaining connections');

$throwing = new ScannerConnectionProbe(true, true, true);
try {
    (new ScannerTransactionRecovery())->recover([$throwing]);
    throw new RuntimeException('Expected throwing scanner recovery failure was not thrown');
} catch (TransactionRecoveryException $exception) {
    expectScannerRecovery(
        $exception->getPrevious() instanceof TransactionRecoveryException,
        'underlying transaction recovery exception was not retained'
    );
}

function scannerBusyException(): PDOException
{
    $exception = new PDOException('database is busy', 5);
    $exception->errorInfo = ['HY000', 5, 'database is busy'];

    return $exception;
}

$busy = scannerBusyException();
$active = new ScannerConnectionProbe(true);
$outcome = (new ScannerIterationFailureHandler())->handle(
    $busy,
    static fn (): array => [$active],
    0
);
expectScannerRecovery($active->rollbackCalls === 1, 'active transaction was not recovered');
expectScannerRecovery($outcome['database_error_kind'] === 'busy', 'busy classification was lost');
expectScannerRecovery($outcome['consecutive_database_failures'] === 1, 'busy count was not incremented');
expectScannerRecovery($outcome['recovery_action'] === 'continue', 'first busy failure must continue');
expectScannerRecovery($outcome['recovery_succeeded'] === true, 'successful rollback was not reported');

$poisoned = new ScannerConnectionProbe(true, false);
$poisonedOutcome = (new ScannerIterationFailureHandler())->handle(
    scannerBusyException(),
    static fn (): array => [$poisoned],
    1
);
expectScannerRecovery($poisonedOutcome['recovery_succeeded'] === false, 'failed rollback was reported as recovered');
expectScannerRecovery(
    $poisonedOutcome['recovery_exception'] instanceof TransactionRecoveryException,
    'failed rollback exception was not retained'
);
expectScannerRecovery($poisonedOutcome['recovery_action'] === 'recycle', 'failed rollback must recycle');

$providerFailure = new TransactionRecoveryException('connection provider failed');
$providerOutcome = (new ScannerIterationFailureHandler())->handle(
    scannerBusyException(),
    static function () use ($providerFailure): array {
        throw $providerFailure;
    },
    1
);
expectScannerRecovery($providerOutcome['recovery_succeeded'] === false, 'provider failure was reported as recovered');
expectScannerRecovery(
    $providerOutcome['recovery_exception'] === $providerFailure,
    'provider transaction recovery exception was not retained'
);
expectScannerRecovery($providerOutcome['recovery_action'] === 'recycle', 'provider failure must recycle');

$nonPdoActive = new ScannerConnectionProbe(true);
$nonPdoOutcome = (new ScannerIterationFailureHandler())->handle(
    new RuntimeException('scanner logic failed'),
    static fn (): array => [$nonPdoActive],
    2
);
expectScannerRecovery($nonPdoActive->rollbackCalls === 1, 'non-PDO exception did not recover active transaction');
expectScannerRecovery($nonPdoOutcome['database_error_kind'] === 'not_pdo', 'non-PDO classification was lost');
expectScannerRecovery($nonPdoOutcome['consecutive_database_failures'] === 0, 'non-PDO exception did not reset failure count');
expectScannerRecovery($nonPdoOutcome['recovery_action'] === 'continue', 'successful non-PDO recovery must continue');
expectScannerRecovery($nonPdoOutcome['recovery_succeeded'] === true, 'successful non-PDO recovery was not reported');

echo "Scanner transaction recovery OK\n";
