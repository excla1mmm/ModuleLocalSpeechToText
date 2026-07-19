<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Providers\ModulesDBConnectionsProvider;
use Phalcon\Di\Di;
use RuntimeException;
use Throwable;

final class ModuleMetadataCache
{
    private const int METADATA_DATABASE = 2;
    private const int DEFAULT_REDIS_DATABASE = 1;
    private const string METADATA_PREFIX = '_PH_METADATA:';
    private const string MODEL_NAMESPACE = 'Modules\\ModuleLocalSpeechToText\\Models\\';
    private const string SCAN_PATTERN = '_PH_METADATA:*ModuleLocalSpeechToText*';

    public function __construct(
        private readonly mixed $redis = null,
        private readonly mixed $runtimeReset = null,
        private readonly mixed $warningLogger = null
    ) {
        foreach ([$this->runtimeReset, $this->warningLogger] as $callback) {
            if ($callback !== null && !is_callable($callback)) {
                throw new RuntimeException('Module metadata callback must be callable');
            }
        }
    }

    public function invalidate(): int
    {
        $deleted = 0;
        $redis = null;
        $originalDatabase = self::DEFAULT_REDIS_DATABASE;
        try {
            $redis = $this->redisClient();
            if (method_exists($redis, 'getDBNum')) {
                $originalDatabase = (int)$redis->getDBNum();
            }
            if ($redis->select(self::METADATA_DATABASE) !== true) {
                throw new RuntimeException('Unable to select Redis metadata database');
            }

            $iterator = null;
            $iterations = 0;
            $matchedKeys = [];
            do {
                $keys = $redis->scan($iterator, self::SCAN_PATTERN, 100);
                if (is_array($keys)) {
                    foreach ($keys as $key) {
                        $key = (string)$key;
                        if (!str_starts_with($key, self::METADATA_PREFIX)
                            || !str_contains($key, self::MODEL_NAMESPACE)) {
                            continue;
                        }
                        $matchedKeys[$key] = true;
                    }
                }
                $iterations++;
                if ($iterations > 10000) {
                    throw new RuntimeException('Redis metadata scan did not converge');
                }
            } while ((int)$iterator !== 0);

            foreach (array_keys($matchedKeys) as $key) {
                $result = $redis->del($key);
                if (is_int($result) && $result > 0) {
                    $deleted += $result;
                }
            }
        } catch (Throwable $exception) {
            $this->warn('Unable to invalidate STT Redis metadata: ' . $exception->getMessage());
        } finally {
            if (is_object($redis)) {
                try {
                    if ($redis->select($originalDatabase) !== true) {
                        throw new RuntimeException('Redis database selection was not restored');
                    }
                } catch (Throwable $exception) {
                    $this->warn('Unable to restore Redis database selection: ' . $exception->getMessage());
                }
            }
        }

        $this->resetRuntimeMetadata();

        return $deleted;
    }

    private function redisClient(): mixed
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $di = Di::getDefault();
        if ($di === null) {
            throw new RuntimeException('Dependency container is unavailable for metadata invalidation');
        }

        return $di->getShared('redis');
    }

    private function resetRuntimeMetadata(): void
    {
        if ($this->runtimeReset !== null) {
            ($this->runtimeReset)();
            return;
        }

        ModulesDBConnectionsProvider::recreateModulesDBConnections();
    }

    private function warn(string $message): void
    {
        if ($this->warningLogger !== null) {
            ($this->warningLogger)($message);
            return;
        }

        LocalSpeechToTextLogger::event(
            'storage',
            'database_operation_failed',
            'Module metadata cache operation failed',
            LOG_WARNING,
            [
                'operation' => 'metadata_cache_invalidation',
                'error_code' => 'metadata_cache_operation_failed',
            ]
        );
    }
}
