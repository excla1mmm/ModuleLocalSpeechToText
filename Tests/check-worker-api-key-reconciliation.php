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

        public static function findFirstById(int $id): ?self
        {
            return self::$rows[$id] ?? null;
        }

        public function save(): bool
        {
            $this->saveCalls++;
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

        public static function clearCache(): void
        {
            self::$clearCalls++;
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    final class STTSetting
    {
        /** @var list<int> */
        public static array $workerApiKeyIds = [];

        public static function workerApiKeyIds(): array
        {
            return self::$workerApiKeyIds;
        }
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

    $minimal = new ApiKeys();
    $minimal->id = 2;
    $minimal->full_permissions = '0';
    $minimal->allowed_paths = json_encode(WorkerApiKeyService::WORKER_ALLOWED_PATHS, JSON_THROW_ON_ERROR);
    ApiKeys::$rows[$minimal->id] = $minimal;

    STTSetting::$workerApiKeyIds = [1, 2, 999];
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
    expectWorkerReconciliation(TokenValidationService::$clearCalls === 1, 'Changed permissions must invalidate token cache once');

    $reconcile->invoke($setup);
    expectWorkerReconciliation($legacy->saveCalls === 1, 'Repeated reconciliation must be idempotent');
    expectWorkerReconciliation(TokenValidationService::$clearCalls === 1, 'No-op reconciliation must not clear cache again');

    echo "Worker API-key reconciliation behavior OK\n";
}
