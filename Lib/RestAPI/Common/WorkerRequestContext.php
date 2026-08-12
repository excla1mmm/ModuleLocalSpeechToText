<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common;

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

        $data['_remote_addr'] = is_scalar($sessionContext['remote_addr'] ?? null)
            ? (string)$sessionContext['remote_addr']
            : '';
        $data['_auth_type'] = is_scalar($sessionContext['auth_type'] ?? null)
            ? (string)$sessionContext['auth_type']
            : '';
        $data['_api_key_id'] = is_scalar($sessionContext['token_id'] ?? null)
            ? (int)$sessionContext['token_id']
            : 0;
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
        return ($data['_auth_type'] ?? null) === 'bearer_token'
            && STTSetting::isWorkerApiKeyIdAllowed((int)($data['_api_key_id'] ?? 0));
    }
}
