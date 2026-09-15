<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;

/**
 * Worker identity lookup, REST API key binding checks, and job-lease
 * ownership validation shared by every LocalSpeechToTextMain worker-facing
 * endpoint. Extracted from LocalSpeechToTextMain to keep that class focused
 * on request orchestration.
 */
final class WorkerAuthorizationValidator
{
    public static function findWorker(string $workerUid): ?STTWorker
    {
        $worker = STTWorker::findFirst([
            'conditions' => 'worker_uid = :worker_uid:',
            'bind' => [
                'worker_uid' => $workerUid,
            ],
        ]);

        return $worker instanceof STTWorker ? $worker : null;
    }

    public static function validateWorkerApiKey(array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return null;
        }

        $apiKeyId = self::requestApiKeyId($data) ?? 0;
        if ($apiKeyId <= 0) {
            return JobApiResponseBuilder::error('worker_api_key_required', 'Worker REST API key is required', 403);
        }

        $workerApiKeyIds = STTSetting::workerApiKeyIds();
        if ($workerApiKeyIds === []) {
            return JobApiResponseBuilder::error('worker_api_key_not_configured', 'Worker REST API key is not configured', 403);
        }

        if (!in_array($apiKeyId, $workerApiKeyIds, true)) {
            return JobApiResponseBuilder::error('worker_api_key_forbidden', 'This API key is not assigned to Local Speech To Text workers', 403);
        }

        return null;
    }

    public static function requireBearerWorkerApiKey(array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return JobApiResponseBuilder::error('worker_api_key_required', 'Worker REST API key is required', 403);
        }

        return self::validateWorkerApiKey($data);
    }

    public static function validateWorkerRegistrationBinding(string $workerUid, array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return null;
        }

        $worker = self::findWorker($workerUid);
        $requestApiKeyId = self::requestApiKeyId($data) ?? 0;
        if (!$worker instanceof STTWorker) {
            return self::validateWorkerApiKeyExclusiveBinding($workerUid, $requestApiKeyId);
        }

        $boundApiKeyId = (int)$worker->api_key_id;
        if ($boundApiKeyId <= 0 || $boundApiKeyId === $requestApiKeyId) {
            return self::validateWorkerApiKeyExclusiveBinding($workerUid, $requestApiKeyId);
        }

        if (!STTSetting::isWorkerApiKeyIdAllowed($boundApiKeyId)) {
            return self::validateWorkerApiKeyExclusiveBinding($workerUid, $requestApiKeyId);
        }

        return JobApiResponseBuilder::error('worker_api_key_mismatch', 'Worker is bound to another API key', 403);
    }

    public static function validateWorkerApiKeyExclusiveBinding(string $workerUid, int $apiKeyId): ?PBXApiResult
    {
        if ($apiKeyId <= 0) {
            return null;
        }

        $worker = STTWorker::findFirst([
            'conditions' => 'api_key_id = :api_key_id: AND worker_uid <> :worker_uid:',
            'bind' => [
                'api_key_id' => $apiKeyId,
                'worker_uid' => $workerUid,
            ],
        ]);
        if ($worker instanceof STTWorker) {
            return JobApiResponseBuilder::error('worker_api_key_already_bound', 'This API key is already bound to another worker', 409);
        }

        return null;
    }

    public static function validateWorkerApiKeyBinding(STTWorker $worker, array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return null;
        }

        $boundApiKeyId = (int)$worker->api_key_id;
        if ($boundApiKeyId <= 0) {
            return JobApiResponseBuilder::error('worker_api_key_not_bound', 'Worker must be registered with the configured API key', 403);
        }

        if ($boundApiKeyId !== self::requestApiKeyId($data)) {
            return JobApiResponseBuilder::error('worker_api_key_mismatch', 'Worker is bound to another API key', 403);
        }

        return null;
    }

    public static function validateAssignedJob(STTJob $job, STTWorker $worker, string $leaseToken): ?PBXApiResult
    {
        if ((string)$job->status !== STTJob::STATUS_PROCESSING) {
            return JobApiResponseBuilder::error('job_not_processing', 'Job is not assigned for processing', 409);
        }

        if ((int)$job->worker_id !== (int)$worker->id) {
            return JobApiResponseBuilder::error('job_assigned_to_another_worker', 'Job is assigned to another worker', 403);
        }

        if ((string)$job->lease_token === '' || !hash_equals((string)$job->lease_token, $leaseToken)) {
            return JobApiResponseBuilder::error('job_lease_mismatch', 'Job lease token is invalid or expired', 409);
        }

        if ((int)$job->lease_expires_at > 0 && (int)$job->lease_expires_at < time()) {
            // Stale-lease recovery is the scanner's responsibility (it runs
            // once per scan cycle). Doing it here was a side effect inside
            // request validation, and inside the receipt transaction it
            // gets rolled back anyway - just report the conflict.
            return JobApiResponseBuilder::error('job_lease_expired', 'Job lease has expired', 409);
        }

        return null;
    }

    public static function isBearerAuthenticatedRequest(array $data): bool
    {
        $value = $data['_auth_type'] ?? '';

        return (is_scalar($value) ? trim((string)$value) : '') === 'bearer_token';
    }

    public static function requestApiKeyId(array $data): ?int
    {
        $apiKeyId = (int)($data['_api_key_id'] ?? 0);

        return $apiKeyId > 0 ? $apiKeyId : null;
    }
}
