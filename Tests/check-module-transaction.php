<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\TransactionRecoveryException;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';

final class TransactionAdapterProbe
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<bool|Throwable> */
    public array $beginOutcomes = [];

    /** @var list<bool|Throwable> */
    public array $commitOutcomes = [];

    /** @var list<bool|Throwable> */
    public array $rollbackOutcomes = [];

    public function __construct(public bool $active = false)
    {
    }

    public function isUnderTransaction(): bool
    {
        return $this->active;
    }

    public function begin(): bool
    {
        $this->events[] = 'begin';
        $outcome = array_shift($this->beginOutcomes) ?? true;
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        if ($outcome) {
            $this->active = true;
        }

        return $outcome;
    }

    public function commit(): bool
    {
        $this->events[] = 'commit';
        $outcome = array_shift($this->commitOutcomes) ?? true;
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        if ($outcome) {
            $this->active = false;
        }

        return $outcome;
    }

    public function rollback(): bool
    {
        $this->events[] = 'rollback';
        $outcome = array_shift($this->rollbackOutcomes) ?? true;
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
        if ($outcome) {
            $this->active = false;
        }

        return $outcome;
    }
}

function expectModuleTransaction(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function captureTransactionException(callable $operation, string $expectedClass): Throwable
{
    try {
        $operation();
    } catch (Throwable $exception) {
        expectModuleTransaction(
            $exception instanceof $expectedClass,
            "Expected {$expectedClass}, got " . $exception::class . ': ' . $exception->getMessage()
        );

        return $exception;
    }

    throw new RuntimeException("Expected {$expectedClass} was not thrown");
}

$sleeps = [];
$transaction = new ModuleTransaction(static function (int $microseconds) use (&$sleeps): void {
    $sleeps[] = $microseconds;
});

$connection = new TransactionAdapterProbe();
$value = $transaction->run($connection, static fn(): string => 'ok');
expectModuleTransaction($value === 'ok', 'successful transaction must return operation result');
expectModuleTransaction($connection->events === ['begin', 'commit'], 'successful transaction order mismatch');

$ambient = new TransactionAdapterProbe(true);
captureTransactionException(
    static fn() => $transaction->run($ambient, static fn(): string => 'unreachable'),
    RuntimeException::class
);
expectModuleTransaction($ambient->events === [], 'ambient transaction must not be changed');

$beginFalse = new TransactionAdapterProbe();
$beginFalse->beginOutcomes = [false];
$operationCalled = false;
captureTransactionException(
    static function () use ($transaction, $beginFalse, &$operationCalled): void {
        $transaction->run($beginFalse, static function () use (&$operationCalled): void {
            $operationCalled = true;
        });
    },
    RuntimeException::class
);
expectModuleTransaction(!$operationCalled, 'operation ran after failed begin');
expectModuleTransaction($beginFalse->events === ['begin'], 'failed begin must not roll back an inactive adapter');

$beginThrow = new TransactionAdapterProbe();
$beginThrow->beginOutcomes = [new RuntimeException('begin exploded')];
$exception = captureTransactionException(
    static fn() => $transaction->run($beginThrow, static fn(): null => null),
    RuntimeException::class
);
expectModuleTransaction($exception->getMessage() === 'begin exploded', 'begin exception was not preserved');

$operationFailure = new TransactionAdapterProbe();
$exception = captureTransactionException(
    static fn() => $transaction->run(
        $operationFailure,
        static fn() => throw new InvalidArgumentException('operation failed')
    ),
    InvalidArgumentException::class
);
expectModuleTransaction($exception->getMessage() === 'operation failed', 'operation exception was not preserved');
expectModuleTransaction(
    $operationFailure->events === ['begin', 'rollback'],
    'operation failure must roll back the owned transaction'
);

$commitFalse = new TransactionAdapterProbe();
$commitFalse->commitOutcomes = [false];
$exception = captureTransactionException(
    static fn() => $transaction->run($commitFalse, static fn(): string => 'value'),
    RuntimeException::class
);
expectModuleTransaction(str_contains($exception->getMessage(), 'commit'), 'commit failure must be explicit');
expectModuleTransaction(
    $commitFalse->events === ['begin', 'commit', 'rollback'],
    'failed commit must roll back when the adapter remains active'
);

$commitThrow = new TransactionAdapterProbe();
$commitThrow->commitOutcomes = [new RuntimeException('commit exploded')];
$exception = captureTransactionException(
    static fn() => $transaction->run($commitThrow, static fn(): string => 'value'),
    RuntimeException::class
);
expectModuleTransaction($exception->getMessage() === 'commit exploded', 'commit exception was not preserved');
expectModuleTransaction(
    $commitThrow->events === ['begin', 'commit', 'rollback'],
    'commit exception must roll back the active transaction'
);

$rollbackFalse = new TransactionAdapterProbe();
$rollbackFalse->rollbackOutcomes = [false];
$exception = captureTransactionException(
    static fn() => $transaction->run(
        $rollbackFalse,
        static fn() => throw new RuntimeException('operation failed')
    ),
    TransactionRecoveryException::class
);
expectModuleTransaction(str_contains($exception->getMessage(), 'rollback'), 'rollback failure must be explicit');

$rollbackThrow = new TransactionAdapterProbe();
$rollbackThrow->rollbackOutcomes = [new RuntimeException('rollback exploded')];
$exception = captureTransactionException(
    static fn() => $transaction->run(
        $rollbackThrow,
        static fn() => throw new RuntimeException('operation failed')
    ),
    TransactionRecoveryException::class
);
expectModuleTransaction($exception->getPrevious()?->getMessage() === 'rollback exploded', 'rollback cause was lost');

$retry = new TransactionAdapterProbe();
$attempts = 0;
$result = $transaction->run(
    $retry,
    static function () use (&$attempts): string {
        $attempts++;
        if ($attempts === 1) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked', 5);
        }

        return 'retried';
    },
    2
);
expectModuleTransaction($result === 'retried' && $attempts === 2, 'busy operation was not retried');
expectModuleTransaction(
    $retry->events === ['begin', 'rollback', 'begin', 'commit'],
    'busy retry did not start from a clean transaction'
);
expectModuleTransaction($sleeps === [25000], 'busy retry backoff mismatch');

$notBusy = new TransactionAdapterProbe();
$attempts = 0;
captureTransactionException(
    static function () use ($transaction, $notBusy, &$attempts): void {
        $transaction->run(
            $notBusy,
            static function () use (&$attempts): void {
                $attempts++;
                throw new RuntimeException('validation failed');
            },
            2
        );
    },
    RuntimeException::class
);
expectModuleTransaction($attempts === 1, 'non-busy failure must not be retried');

echo "Module transaction behavior OK\n";
