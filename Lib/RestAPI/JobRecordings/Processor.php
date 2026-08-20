<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobRecordings;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\CanonicalJobId;
use Modules\ModuleLocalSpeechToText\Lib\Services\JobLeaseOwnership;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerBindingDecision;
use Phalcon\Di\Injectable;
use Modules\ModuleLocalSpeechToText\Models\STTJob;

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $versionError = WorkerApiContract::requireVersion($request);
        if ($versionError !== null) return $versionError;
        $data = WorkerRequestContext::data($request);
        if (!WorkerRequestContext::isRestrictedWorkerKey($data)) return self::error(403, 'worker_authorization_forbidden');
        $jobId = (int)($data['id'] ?? 0);
        if (array_key_exists('_idFromRequestBody', $data) && !CanonicalJobId::matchesRoute($data['_idFromRequestBody'], $jobId)) return self::error(409, 'job_id_mismatch');
        $workerUid = trim((string)($data['_worker_uid_header'] ?? ''));
        $leaseToken = trim((string)($data['_lease_token_header'] ?? ''));
        if ($workerUid === '' || $leaseToken === '') return self::error(403, 'worker_identity_forbidden');
        $bindingError = WorkerBindingDecision::authorize($workerUid, (int)($data['_api_key_id'] ?? 0));
        if ($bindingError !== null) return $bindingError;
        $ownershipError = JobLeaseOwnership::preflight($jobId, $workerUid, $leaseToken);
        if ($ownershipError !== null) return $ownershipError;
        $data['id'] = $jobId; $data['worker_uid'] = $workerUid; $data['lease_token'] = $leaseToken;
        unset($data['_worker_uid_header'], $data['_lease_token_header']);
        $gate = new ModuleLifecycleGate();
        $lease = $gate->acquireWriteLease(allowDuringDrain: true);
        if ($lease === null) return self::error(503, 'module_unavailable');
        try {
            try {
                $result = (new ModuleDatabaseWriterMutex())->synchronized(static function () use ($data): PBXApiResult {
                    (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
                    return LocalSpeechToTextMain::downloadJobFile($data);
                });
            } catch (ModuleDatabaseWriterBusyException) {
                return self::error(503, 'database_busy', 2);
            }
        }
        finally { $gate->releaseWriteLease($lease); }
        $result->processor = __METHOD__; $result->function = 'getRecord'; return $result;
    }
    private static function error(int $status, string $code, int $retryAfter = 5): PBXApiResult
    { $r = new PBXApiResult(); $r->success = false; $r->httpCode = $status; $r->data = ['error_code' => $code]; if ($status === 503) $r->data['retry_after'] = $retryAfter; return $r; }
}
