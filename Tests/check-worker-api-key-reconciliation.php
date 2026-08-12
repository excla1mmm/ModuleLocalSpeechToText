<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    class ApiKeys
    {
        /** @var array<int, self> */
        public static array $rows = [];
        public int $id;
        public string $full_permissions = '0';
        public string $allowed_paths = '{}';
        public int $saveCalls = 0;
        public bool $failSave = false;
        public string $key_hash = 'hash';
        public string $key_suffix = '1234';
        public string $key_display = 'display';
        public string $description = 'description';
        public static object $connection;

        public function getWriteConnection(): object { return self::$connection; }

        public static function findFirstById(int $id): ?self
        {
            return self::$rows[$id] ?? null;
        }

        public function save(): bool
        {
            $this->saveCalls++;
            if ($this->failSave || self::$connection->shouldFailSave()) return false;
            self::$connection->persist($this);
            self::$rows[$this->id] = $this;

            return true;
        }

        public function getMessages(): array
        {
            return [];
        }
    }

    class PbxSettings
    {
    }
}

namespace MikoPBX\Modules\Setup {
    abstract class PbxExtensionSetupBase
    {
    }
}

namespace MikoPBX\PBXCoreREST\Services {
    final class TokenValidationService
    {
        public static int $clearCalls = 0;
        public static int $failClearCalls = 0;

        public static function clearCache(): void
        {
            self::$clearCalls++;
            if (self::$failClearCalls > 0) { self::$failClearCalls--; throw new \RuntimeException('injected cache clear failure'); }
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        /** @var list<int> */
        public static array $workerApiKeyIds = [];
        public static array $workerApiKeyHistoryIds = [];

        public static function workerApiKeyIds(): array
        {
            return self::$workerApiKeyIds;
        }
        public static function workerApiKeyHistoryIds(): array { return self::$workerApiKeyHistoryIds; }
    }

    final class STTWorker
    {
    }
}

namespace {
    use MikoPBX\Common\Models\ApiKeys;
    use MikoPBX\PBXCoreREST\Services\TokenValidationService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\Setup\PbxExtensionSetup;

    function expectWorkerReconciliation(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    require_once __DIR__ . '/../Lib/Services/WorkerApiKeyService.php';
    require_once __DIR__ . '/../Setup/PbxExtensionSetup.php';

    ApiKeys::$connection = new class {
        private bool $transaction = false;
        private array $durableRows = [];
        private array $transactionRows = [];
        public int $saveAttempts = 0;
        public int $failOnSaveAttempt = 0;
        public function isUnderTransaction(): bool { return $this->transaction; }
        public function begin(): bool { $this->transaction = true; $this->transactionRows = $this->durableRows; return true; }
        public function commit(): bool { $this->durableRows = $this->transactionRows; $this->transaction = false; return true; }
        public function rollback(): bool { $this->transactionRows = []; $this->transaction = false; return true; }
        public function shouldFailSave(): bool { return $this->failOnSaveAttempt > 0 && $this->saveAttempts + 1 === $this->failOnSaveAttempt; }
        public function persist(ApiKeys $key): void { $this->saveAttempts++; $this->transactionRows[$key->id] = [(string)$key->full_permissions, (string)$key->allowed_paths]; }
        public function seed(ApiKeys $key): void { $this->durableRows[$key->id] = [(string)$key->full_permissions, (string)$key->allowed_paths]; }
        public function durable(int $id): array { return $this->durableRows[$id] ?? []; }
    };

    $legacy = new ApiKeys();
    $legacy->id = 1;
    $legacy->full_permissions = '1';
    $legacy->allowed_paths = json_encode([
        '/api/v3/module-local-speech-to-text/workers' => 'write',
        '/api/v3/module-local-speech-to-text/jobs' => 'write',
        '/api/v3/module-local-speech-to-text/transcripts' => 'read',
        '/api/v3/module-local-speech-to-text/call-transcripts' => 'read',
        '/api/v3/unrelated-resource' => 'write',
    ], JSON_THROW_ON_ERROR);
    ApiKeys::$rows[$legacy->id] = $legacy;
    ApiKeys::$connection->seed($legacy);

    $minimal = new ApiKeys();
    $minimal->id = 2;
    $minimal->full_permissions = '0';
    $minimal->allowed_paths = json_encode(WorkerApiKeyService::WORKER_ALLOWED_PATHS, JSON_THROW_ON_ERROR);
    ApiKeys::$rows[$minimal->id] = $minimal;
    ApiKeys::$connection->seed($minimal);

    $historical = new ApiKeys();
    $historical->id = 3;
    $historical->full_permissions = '1';
    $historical->allowed_paths = '{}';
    ApiKeys::$rows[$historical->id] = $historical;
    ApiKeys::$connection->seed($historical);

    STTSetting::$workerApiKeyIds = [1, 2, 999];
    STTSetting::$workerApiKeyHistoryIds = [3, 999];
    $setup = (new ReflectionClass(PbxExtensionSetup::class))->newInstanceWithoutConstructor();
    $reconcile = new ReflectionMethod(PbxExtensionSetup::class, 'ensureWorkerApiKeyPermissions');
    $reconcile->invoke($setup);

    expectWorkerReconciliation($legacy->full_permissions === '0', 'Upgrade must revoke legacy full permissions');
    expectWorkerReconciliation(
        json_decode($legacy->allowed_paths, true, 8, JSON_THROW_ON_ERROR) === WorkerApiKeyService::WORKER_ALLOWED_PATHS,
        'Upgrade must replace legacy, transcript, and unrelated paths with the exact worker map'
    );
    expectWorkerReconciliation($legacy->saveCalls === 1, 'Changed legacy key must be persisted once');
    expectWorkerReconciliation($minimal->saveCalls === 0, 'Already-minimal worker key must not be rewritten');
    expectWorkerReconciliation($historical->full_permissions === '0' && $historical->saveCalls === 1, 'Existing historical key must be reconciled without recreation');
    expectWorkerReconciliation($historical->key_hash === 'hash' && $historical->key_suffix === '1234' && $historical->key_display === 'display' && $historical->description === 'description', 'Reconciliation must preserve credential identity and display fields');
    expectWorkerReconciliation(TokenValidationService::$clearCalls === 1, 'Changed permissions must invalidate token cache once');

    $reconcile->invoke($setup);
    expectWorkerReconciliation($legacy->saveCalls === 1, 'Repeated reconciliation must be idempotent');
    expectWorkerReconciliation(TokenValidationService::$clearCalls === 2, 'Canonical rows must still receive a post-commit cache invalidation attempt');

    $legacy->full_permissions = '1';
    $legacy->allowed_paths = '{}';
    TokenValidationService::$failClearCalls = 1;
    try { $reconcile->invoke($setup); throw new RuntimeException('Cache failure swallowed'); } catch (RuntimeException) {}
    $reconcile->invoke($setup);
    expectWorkerReconciliation(TokenValidationService::$clearCalls === 4, 'Setup retry must repeat cache invalidation after post-commit clear failure');

    $legacy->full_permissions = '1';
    $legacy->allowed_paths = '{}';
    $historical->full_permissions = '1';
    $historical->allowed_paths = '{}';
    ApiKeys::$connection->seed($legacy);
    ApiKeys::$connection->seed($historical);
    ApiKeys::$connection->failOnSaveAttempt = ApiKeys::$connection->saveAttempts + 2;
    try {
        $reconcile->invoke($setup);
        throw new RuntimeException('Injected reconciliation failure was swallowed');
    } catch (RuntimeException) {
        expectWorkerReconciliation([$legacy->full_permissions, $legacy->allowed_paths] === ['1', '{}'], 'Partial failure must restore the first Core key object');
        expectWorkerReconciliation([$historical->full_permissions, $historical->allowed_paths] === ['1', '{}'], 'Partial failure must restore the failing Core key object');
        expectWorkerReconciliation(ApiKeys::$connection->durable(1) === ['1', '{}'] && ApiKeys::$connection->durable(3) === ['1', '{}'], 'Rollback must leave persisted Core rows unchanged after reread');
        expectWorkerReconciliation(TokenValidationService::$clearCalls === 4, 'Failed reconciliation must not invalidate token cache');
        expectWorkerReconciliation(STTSetting::$workerApiKeyIds === [1, 2, 999] && STTSetting::$workerApiKeyHistoryIds === [3, 999], 'Failed reconciliation must preserve module bindings/history');
    }

    echo "Worker API-key reconciliation behavior OK\n";
}
