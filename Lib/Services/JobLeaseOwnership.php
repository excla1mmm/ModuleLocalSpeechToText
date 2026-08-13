<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;

final class JobLeaseOwnership
{
    public static function preflight(int $jobId, string $workerUid, string $leaseToken): ?PBXApiResult
    {
        $job = STTJob::findFirstById($jobId);
        if (!$job instanceof STTJob) return self::error('job_not_found', "Job #{$jobId} not found", 404);
        $worker = STTWorker::findFirst(['conditions' => 'worker_uid = :worker_uid:', 'bind' => ['worker_uid' => $workerUid]]);
        if (!$worker instanceof STTWorker) return self::error('worker_not_registered', 'Worker is not registered', 404);
        if ((string)$job->status !== STTJob::STATUS_PROCESSING) return self::error('job_not_processing', 'Job is not assigned for processing', 409);
        if ((int)$job->worker_id !== (int)$worker->id) return self::error('job_assigned_to_another_worker', 'Job is assigned to another worker', 403);
        if ((string)$job->lease_token === '' || !hash_equals((string)$job->lease_token, $leaseToken)) return self::error('job_lease_mismatch', 'Job lease token is invalid or expired', 409);
        if ((int)$job->lease_expires_at > 0 && (int)$job->lease_expires_at < time()) return self::error('job_lease_expired', 'Job lease has expired', 409);
        return null;
    }

    private static function error(string $code, string $message, int $httpCode): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = $httpCode;
        $result->data = ['error' => $code];
        $result->messages['error'][] = $message;
        return $result;
    }
}
