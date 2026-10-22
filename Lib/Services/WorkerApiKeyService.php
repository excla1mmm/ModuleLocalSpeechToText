<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\ApiKeys;
use MikoPBX\PBXCoreREST\Services\TokenValidationService;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use RuntimeException;
use Throwable;

class WorkerApiKeyService
{
    public const array WORKER_ALLOWED_PATHS = [
        '/api/v3/module-local-speech-to-text/worker-api-contract' => 'read',
        '/api/v3/module-local-speech-to-text/workers' => 'write',
        '/api/v3/module-local-speech-to-text/worker-processing-settings' => 'read',
        '/api/v3/module-local-speech-to-text/worker-update-license' => 'read',
        '/api/v3/module-local-speech-to-text/job-leases' => 'write',
        '/api/v3/module-local-speech-to-text/job-recordings' => 'read',
        '/api/v3/module-local-speech-to-text/job-results' => 'write',
        '/api/v3/module-local-speech-to-text/job-failures' => 'write',
        '/api/v3/module-local-speech-to-text/summary-leases' => 'write',
        '/api/v3/module-local-speech-to-text/summary-results' => 'write',
        '/api/v3/module-local-speech-to-text/summary-failures' => 'write',
    ];

    private mixed $coreDatabase = null;

    public function __destruct()
    {
        try {
            $this->rollBackCoreApiKeyTransaction();
        } catch (Throwable) {
            // The controller performs the observable rollback. This is only a
            // last-resort safeguard against leaking a shared DB transaction.
        }
    }

    public function workerApiKeyRows(): array
    {
        $rows = [];
        $storedIds = STTSetting::workerApiKeyIds();

        foreach ($storedIds as $apiKeyId) {
            $apiKey = ApiKeys::findFirstById($apiKeyId);
            if (!$apiKey instanceof ApiKeys) {
                // A prior delete may have revoked the Core credential before a
                // module cleanup failed. Keep the harmless stale binding visible
                // and retryable instead of mutating data from the dashboard GET.
                $rows[] = [
                    'id' => $apiKeyId,
                    'keyDisplay' => '#' . $apiKeyId,
                    'createdAtLabel' => '-',
                    'lastUsedAtLabel' => '-',
                    'boundWorkers' => $this->boundWorkerCount($apiKeyId),
                    'missing' => true,
                ];
                continue;
            }

            $rows[] = [
                'id' => (int)$apiKey->id,
                'keyDisplay' => (string)$apiKey->key_display,
                'createdAtLabel' => $this->apiKeyDateLabel((string)$apiKey->created_at),
                'lastUsedAtLabel' => $this->apiKeyDateLabel((string)$apiKey->last_used_at),
                'boundWorkers' => $this->boundWorkerCount((int)$apiKey->id),
                'missing' => false,
            ];
        }

        return $rows;
    }

    public function beginCoreApiKeyTransaction(): void
    {
        if ($this->coreDatabase !== null) {
            throw new RuntimeException('Core API-key transaction is already active');
        }

        $database = (new ApiKeys())->getWriteConnection();
        if ($database->isUnderTransaction()) {
            throw new RuntimeException('Cannot nest the Core API-key transaction');
        }
        if ($database->begin() === false) {
            throw new RuntimeException('Cannot start the Core API-key transaction');
        }

        $this->coreDatabase = $database;
    }

    public function commitCoreApiKeyTransaction(): void
    {
        $database = $this->activeCoreDatabase();
        if ($database->commit() === false) {
            throw new RuntimeException('Cannot commit the Core API-key transaction');
        }
        $this->coreDatabase = null;
    }

    public function rollBackCoreApiKeyTransaction(): void
    {
        if ($this->coreDatabase === null) {
            return;
        }

        $database = $this->coreDatabase;
        try {
            if ($database->isUnderTransaction() && $database->rollback() === false) {
                throw new RuntimeException('Cannot roll back the Core API-key transaction');
            }
        } finally {
            $this->coreDatabase = null;
        }
    }

    public function createCoreWorkerApiKey(string $plainApiKey): ApiKeys
    {
        $this->activeCoreDatabase();

        $apiKey = new ApiKeys();
        $apiKey->description = 'Local Speech To Text Worker ' . date('Y-m-d H:i:s');
        $apiKey->key_hash = password_hash($plainApiKey, PASSWORD_BCRYPT);
        $apiKey->key_suffix = substr($plainApiKey, -4);
        $apiKey->key_display = substr($plainApiKey, 0, 5) . '...' . substr($plainApiKey, -5);
        $apiKey->networkfilterid = null;
        $apiKey->full_permissions = '0';
        $apiKey->allowed_paths = json_encode(self::WORKER_ALLOWED_PATHS, JSON_THROW_ON_ERROR);
        $apiKey->created_at = date('Y-m-d H:i:s');

        if (!$apiKey->save()) {
            throw new RuntimeException(implode(', ', $apiKey->getMessages()));
        }

        return $apiKey;
    }

    public function deleteCoreWorkerApiKey(int $apiKeyId): void
    {
        $this->activeCoreDatabase();

        $apiKey = ApiKeys::findFirstById($apiKeyId);
        if ($apiKey instanceof ApiKeys && !$apiKey->delete()) {
            throw new RuntimeException(implode(', ', $apiKey->getMessages()));
        }
    }

    public function bindWorkerApiKey(int $apiKeyId): void
    {
        STTSetting::addWorkerApiKeyId($apiKeyId);
    }

    public function removeWorkerApiKeyBinding(int $apiKeyId): void
    {
        STTSetting::removeWorkerApiKeyId($apiKeyId);
    }

    public function clearCoreApiKeyCache(): void
    {
        TokenValidationService::clearCache();
    }

    public function reconcileStoredWorkerApiKeys(): void
    {
        $ids = array_values(array_unique(array_merge(
            STTSetting::workerApiKeyIds(),
            STTSetting::workerApiKeyHistoryIds()
        )));
        $changed = false;
        $snapshots = [];
        $this->beginCoreApiKeyTransaction();
        try {
            foreach ($ids as $apiKeyId) {
                $apiKey = ApiKeys::findFirstById((int)$apiKeyId);
                if (!$apiKey instanceof ApiKeys) continue;
                $allowedPaths = json_decode((string)$apiKey->allowed_paths, true);
                if ((string)$apiKey->full_permissions === '0' && $allowedPaths === self::WORKER_ALLOWED_PATHS) continue;
                $snapshots[] = [$apiKey, (string)$apiKey->full_permissions, (string)$apiKey->allowed_paths];
                $apiKey->full_permissions = '0';
                $apiKey->allowed_paths = json_encode(self::WORKER_ALLOWED_PATHS, JSON_THROW_ON_ERROR);
                if (!$apiKey->save()) throw new RuntimeException(implode(', ', $apiKey->getMessages()));
                $changed = true;
            }
            $this->commitCoreApiKeyTransaction();
        } catch (Throwable $exception) {
            $this->rollBackCoreApiKeyTransaction();
            foreach ($snapshots as [$apiKey, $fullPermissions, $allowedPaths]) {
                $apiKey->full_permissions = $fullPermissions;
                $apiKey->allowed_paths = $allowedPaths;
            }
            throw $exception;
        }
        // Always invalidate after a successful reconciliation transaction. If a
        // prior post-commit invalidation failed, the next setup must retry even
        // though all persisted rows are already canonical.
        $this->clearCoreApiKeyCache();
    }

    public function unbindWorkersFromApiKey(int $apiKeyId): void
    {
        $workers = STTWorker::find([
            'conditions' => 'api_key_id = :api_key_id:',
            'bind' => [
                'api_key_id' => $apiKeyId,
            ],
        ]);

        foreach ($workers as $worker) {
            if (!$worker instanceof STTWorker) {
                continue;
            }

            $worker->api_key_id = null;
            if (!$worker->save()) {
                throw new RuntimeException(implode(', ', $worker->getMessages()));
            }
        }
    }

    private function apiKeyDateLabel(string $dateTime): string
    {
        $dateTime = trim($dateTime);

        return $dateTime === '' ? '-' : $dateTime;
    }

    private function boundWorkerCount(int $apiKeyId): int
    {
        return (int)STTWorker::count([
            'conditions' => 'api_key_id = :api_key_id:',
            'bind' => [
                'api_key_id' => $apiKeyId,
            ],
        ]);
    }

    private function activeCoreDatabase(): mixed
    {
        if ($this->coreDatabase === null || !$this->coreDatabase->isUnderTransaction()) {
            throw new RuntimeException('Core API-key transaction is not active');
        }

        return $this->coreDatabase;
    }

}
