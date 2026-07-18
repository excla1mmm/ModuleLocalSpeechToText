<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerTransactionRecovery;
use Modules\ModuleLocalSpeechToText\Lib\Services\TransactionRecoveryException;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/ScannerTransactionRecovery.php';

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

$scanner = (string)file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
expectScannerRecovery(
    str_contains($scanner, 'new ScannerTransactionRecovery')
        && str_contains($scanner, 'Scanner database recovery failed')
        && str_contains($scanner, 'throw $recoveryException;'),
    'scanner must terminate by propagating an unrecoverable transaction failure'
);

echo "Scanner transaction recovery OK\n";
