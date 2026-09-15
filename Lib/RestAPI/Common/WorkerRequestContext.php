<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common;

use MikoPBX\Common\Models\ApiKeys;
use MikoPBX\Common\Providers\AclProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

final class WorkerRequestContext
{
    /**
     * @return array<string, mixed>
     */
    public static function data(array $request): array
    {
        $data = is_array($request['data'] ?? null) ? $request['data'] : [];
        $sessionContext = is_array($request['sessionContext'] ?? null) ? $request['sessionContext'] : [];
        $headers = is_array($request['httpHeaders'] ?? null) ? $request['httpHeaders'] : [];
        $headers = array_filter($headers, static fn(mixed $value): bool => is_scalar($value));

        $data['_remote_addr'] = is_scalar($sessionContext['remote_addr'] ?? null)
            ? (string)$sessionContext['remote_addr']
            : '';
        $data['_auth_type'] = is_scalar($sessionContext['auth_type'] ?? null)
            ? (string)$sessionContext['auth_type']
            : '';
        $data['_api_key_id'] = is_scalar($sessionContext['token_id'] ?? null)
            ? (int)$sessionContext['token_id']
            : 0;
        $data['_auth_user_name'] = is_scalar($sessionContext['user_name'] ?? null)
            ? trim((string)$sessionContext['user_name'])
            : '';
        $data['_auth_session_id'] = is_scalar($sessionContext['session_id'] ?? null)
            ? trim((string)$sessionContext['session_id'])
            : '';
        // Mirrors the web UI session's 'role' value (see
        // LocalSpeechToTextBaseController::usersUIIdentity()), assuming the
        // Core REST layer copies the authenticated session's role into
        // sessionContext the same way it copies user_name/session_id.
        $data['_auth_role'] = is_scalar($sessionContext['role'] ?? null)
            ? trim((string)$sessionContext['role'])
            : '';
        $data['_http_method'] = is_scalar($request['httpMethod'] ?? null)
            ? (string)$request['httpMethod']
            : '';
        $data['_worker_uid_header'] = (string)($headers['x-mikopbx-worker-uid'] ?? '');
        $data['_lease_token_header'] = (string)($headers['x-mikopbx-job-lease-token'] ?? '');

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function isRestrictedWorkerKey(array $data): bool
    {
        $apiKeyId = $data['_api_key_id'] ?? null;

        return ($data['_auth_type'] ?? null) === 'bearer_token'
            && STTSetting::isWorkerApiKeyIdAllowed(is_scalar($apiKeyId) ? (int)$apiKeyId : 0)
            && self::isLiveWorkerApiKey(is_scalar($apiKeyId) ? (int)$apiKeyId : 0);
    }

    /** @param array<string, mixed> $data */
    public static function isRevokedWorkerKey(array $data): bool
    {
        $apiKeyId = is_scalar($data['_api_key_id'] ?? null) ? (int)$data['_api_key_id'] : 0;

        return ($data['_auth_type'] ?? null) === 'bearer_token'
            && STTSetting::isWorkerApiKeyIdKnown($apiKeyId)
            && (
                !STTSetting::isWorkerApiKeyIdAllowed($apiKeyId)
                || !self::isLiveWorkerApiKey($apiKeyId)
            );
    }

    /**
     * Core's PBXCoreREST\Controllers\BaseController only populates
     * `sessionContext` (and therefore `auth_type`) when the request carries
     * a bearer token; a request that Core authenticated via
     * SecurityType::LOCALHOST has no sessionContext at all, so `_auth_type`
     * arrives here as ''. Core's own convention for that case is to treat it
     * as an administrator (see
     * PBXCoreREST\Lib\Search\GetSearchItemsAction::getUserRole(), which
     * defaults to AclProvider::ROLE_ADMINS when sessionContext is empty), so
     * we mirror that here rather than failing closed on it.
     *
     * A 'bearer_token' backed by a session (user_name + session_id present,
     * i.e. a JWT minted for a logged-in browser session) is only granted
     * admin when the underlying session's role is Core's "admins" role - the
     * same rule LocalSpeechToTextBaseController::usersUIViewCapabilities()
     * applies to the web UI. A plain 'bearer_token' (API key, no JWT
     * identity) falls through to the API key permission model below.
     *
     * Any other auth type - including Core's own 'session' constant used
     * elsewhere for non-REST contexts, which BaseController never emits into
     * sessionContext for this module's requests - is unrecognized here and
     * fails closed.
     *
     * @param array<string, mixed> $data
     */
    public static function isAdministrator(array $data): bool
    {
        $authType = is_scalar($data['_auth_type'] ?? null) ? (string)$data['_auth_type'] : '';
        if ($authType === '') {
            return true;
        }
        if ($authType !== 'bearer_token') {
            return false;
        }
        if ((string)($data['_auth_user_name'] ?? '') !== ''
            && (string)($data['_auth_session_id'] ?? '') !== '') {
            return self::isSessionAdministrator($data);
        }

        $apiKeyId = is_scalar($data['_api_key_id'] ?? null) ? (int)$data['_api_key_id'] : 0;
        if ($apiKeyId <= 0 || STTSetting::isWorkerApiKeyIdKnown($apiKeyId)) {
            return false;
        }
        $apiKey = ApiKeys::findFirstById($apiKeyId);
        if (!$apiKey instanceof ApiKeys) {
            return false;
        }
        if ((string)$apiKey->full_permissions === '1') {
            return true;
        }

        $permissions = json_decode((string)$apiKey->allowed_paths, true);
        if (!is_array($permissions)) {
            return false;
        }

        return ($permissions['/api/v3/module-local-speech-to-text/workers'] ?? null) === 'admin';
    }

    /**
     * Used only for a JWT-backed bearer token (session user_name + session_id
     * present). If the JWT payload did not carry a 'role' claim, we cannot
     * identify the caller's role and fail closed (deny admin) rather than
     * assume it.
     *
     * @param array<string, mixed> $data
     */
    private static function isSessionAdministrator(array $data): bool
    {
        $role = is_scalar($data['_auth_role'] ?? null) ? trim((string)$data['_auth_role']) : '';

        return $role === AclProvider::ROLE_ADMINS;
    }

    private static function isLiveWorkerApiKey(int $apiKeyId): bool
    {
        if ($apiKeyId <= 0) {
            return false;
        }
        $apiKey = ApiKeys::findFirstById($apiKeyId);
        if (!$apiKey instanceof ApiKeys || (string)$apiKey->full_permissions !== '0') {
            return false;
        }
        $permissions = json_decode((string)$apiKey->allowed_paths, true);
        if (!is_array($permissions)) {
            return false;
        }
        ksort($permissions);
        $expected = WorkerApiKeyService::WORKER_ALLOWED_PATHS;
        ksort($expected);

        return $permissions === $expected;
    }
}
