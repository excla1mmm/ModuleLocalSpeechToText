<?php

declare(strict_types=1);

/**
 * Fail-closed matrix for WorkerRequestContext::isAdministrator(). Stubs the
 * classes it depends on (ApiKeys, AclProvider, STTSetting) so it can be
 * exercised against the real implementation in isolation.
 */

namespace MikoPBX\Common\Models {
    class ApiKeys
    {
        /** @var array<int, self> */
        public static array $rows = [];

        public int $id = 0;
        public string $full_permissions = '0';
        public string $allowed_paths = '{}';

        public static function findFirstById(int $id): ?self
        {
            return self::$rows[$id] ?? null;
        }
    }
}

namespace MikoPBX\Common\Providers {
    class AclProvider
    {
        public const string ROLE_ADMINS = 'admins';
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    class STTSetting
    {
        /** @var list<int> */
        public static array $knownWorkerApiKeyIds = [];
        /** @var list<int> */
        public static array $allowedWorkerApiKeyIds = [];

        public static function isWorkerApiKeyIdKnown(int $apiKeyId): bool
        {
            return in_array($apiKeyId, self::$knownWorkerApiKeyIds, true);
        }

        public static function isWorkerApiKeyIdAllowed(int $apiKeyId): bool
        {
            return in_array($apiKeyId, self::$allowedWorkerApiKeyIds, true);
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class WorkerApiKeyService
    {
        public const array WORKER_ALLOWED_PATHS = ['/api/v3/module-local-speech-to-text/workers' => 'write'];
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
    use MikoPBX\Common\Models\ApiKeys;

    function expectIsAdministrator(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    require_once __DIR__ . '/../Lib/RestAPI/Common/WorkerRequestContext.php';

    // Empty auth type means Core built no sessionContext at all, which only
    // happens for a request Core authenticated via SecurityType::LOCALHOST
    // (see PBXCoreREST\Controllers\BaseController::buildRequestMessage()).
    // Core's own convention (GetSearchItemsAction::getUserRole()) treats an
    // empty sessionContext as admins, so this must be granted, not denied.
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator([]) === true,
        'Empty auth type (localhost-authenticated by Core) must be treated as administrator'
    );
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator(['_auth_type' => '']) === true,
        'Explicit empty auth type must be treated as administrator'
    );

    // Auth types Core never emits into sessionContext for this module
    // (including its own 'session' constant, used elsewhere) are unknown
    // here and must fail closed.
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator(['_auth_type' => 'session']) === false,
        'Unknown/session auth type must not be treated as administrator'
    );
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator(['_auth_type' => 'localhost']) === false,
        'Unknown/localhost-labelled auth type must not be treated as administrator'
    );

    // bearer_token backed by a session (user_name + session_id) also needs the admins role.
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator([
            '_auth_type' => 'bearer_token',
            '_auth_user_name' => 'jdoe',
            '_auth_session_id' => 'sess-1',
        ]) === false,
        'Session-backed bearer token without a role must fail closed'
    );
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator([
            '_auth_type' => 'bearer_token',
            '_auth_user_name' => 'jdoe',
            '_auth_session_id' => 'sess-1',
            '_auth_role' => 'admins',
        ]) === true,
        'Session-backed bearer token with the admins role must be granted'
    );

    // Plain API key bearer_token still uses the API key permission model.
    \Modules\ModuleLocalSpeechToText\Models\STTSetting::$knownWorkerApiKeyIds = [];
    $fullPermKey = new ApiKeys();
    $fullPermKey->id = 10;
    $fullPermKey->full_permissions = '1';
    ApiKeys::$rows[10] = $fullPermKey;
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator(['_auth_type' => 'bearer_token', '_api_key_id' => 10]) === true,
        'Full-permission API key must remain administrator'
    );

    $scopedKey = new ApiKeys();
    $scopedKey->id = 11;
    $scopedKey->full_permissions = '0';
    $scopedKey->allowed_paths = json_encode(['/api/v3/module-local-speech-to-text/workers' => 'read']);
    ApiKeys::$rows[11] = $scopedKey;
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator(['_auth_type' => 'bearer_token', '_api_key_id' => 11]) === false,
        'Non-admin scoped API key permission must not be treated as administrator'
    );

    \Modules\ModuleLocalSpeechToText\Models\STTSetting::$knownWorkerApiKeyIds = [12];
    $workerKey = new ApiKeys();
    $workerKey->id = 12;
    $workerKey->full_permissions = '0';
    ApiKeys::$rows[12] = $workerKey;
    expectIsAdministrator(
        WorkerRequestContext::isAdministrator(['_auth_type' => 'bearer_token', '_api_key_id' => 12]) === false,
        'Known worker-provisioned API key must never be treated as administrator'
    );

    echo "WorkerRequestContext isAdministrator fail-closed matrix OK\n";
}
