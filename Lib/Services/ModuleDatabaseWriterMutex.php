<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Common\Providers\MutexProvider;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Phalcon\Di\Di;
use RuntimeException;

final class ModuleDatabaseWriterMutex
{
    public const string NAME = 'module-local-stt-db-writer';
    private const int ACQUIRE_TIMEOUT_SECONDS = 10;
    private const int LOCK_TTL_SECONDS = 60;

    /** @var Closure(string, callable, int, int): mixed|null */
    private readonly ?Closure $synchronizer;

    /**
     * @param callable(string, callable, int, int): mixed|null $synchronizer
     */
    public function __construct(?callable $synchronizer = null)
    {
        $this->synchronizer = $synchronizer === null ? null : Closure::fromCallable($synchronizer);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws ModuleDatabaseWriterBusyException
     */
    public function synchronized(callable $operation): mixed
    {
        $synchronizer = $this->synchronizer ?? $this->defaultSynchronizer();
        $operationStarted = false;
        $guardedOperation = static function () use ($operation, &$operationStarted): mixed {
            $operationStarted = true;
            return $operation();
        };

        try {
            return $synchronizer(
                self::NAME,
                $guardedOperation,
                self::ACQUIRE_TIMEOUT_SECONDS,
                self::LOCK_TTL_SECONDS
            );
        } catch (RuntimeException $exception) {
            if ($operationStarted) {
                throw $exception;
            }

            throw new ModuleDatabaseWriterBusyException(
                'ModuleLocalSpeechToText database writer is temporarily busy',
                0,
                $exception
            );
        }
    }

    /** @return Closure(string, callable, int, int): mixed */
    private function defaultSynchronizer(): Closure
    {
        $di = Di::getDefault();
        if ($di === null || !$di->has(MutexProvider::SERVICE_NAME)) {
            throw new RuntimeException('ModuleLocalSpeechToText database writer mutex service unavailable');
        }

        /** @var MutexProvider $mutex */
        $mutex = $di->get(MutexProvider::SERVICE_NAME);

        return static fn (
            string $name,
            callable $operation,
            int $timeout,
            int $ttl
        ): mixed => $mutex->synchronized($name, $operation, $timeout, $ttl);
    }
}
