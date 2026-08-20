<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\{CanonicalJobId,JobLeaseOwnership,JobSubmissionReceiptService,ModuleDatabasePragmas,ModuleDatabaseWriterMutex,ModuleLifecycleGate,WorkerApiContract,WorkerBindingDecision};
use Modules\ModuleLocalSpeechToText\Models\{STTJob,STTWorker};
use Phalcon\Di\Injectable;
class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult { return self::dispatch($request, false); }
    public static function dispatch(array $request, bool $failure): PBXApiResult
    {
        $versionError = WorkerApiContract::requireVersion($request); if ($versionError !== null) return $versionError;
        $data = WorkerRequestContext::data($request); if (!WorkerRequestContext::isRestrictedWorkerKey($data)) return self::error(403, 'worker_authorization_forbidden');
        $jobId = (int)($data['id'] ?? 0); if (array_key_exists('_idFromRequestBody', $data) && !CanonicalJobId::matchesRoute($data['_idFromRequestBody'], $jobId)) return self::error(409, 'job_id_mismatch');
        $workerUid = trim(is_scalar($data['worker_uid'] ?? null) ? (string)$data['worker_uid'] : '');
        $token = trim(is_scalar($data['lease_token'] ?? null) ? (string)$data['lease_token'] : '');
        if ($workerUid === '') return self::error(403, 'worker_identity_forbidden');
        $bindingError = WorkerBindingDecision::authorize($workerUid, (int)($data['_api_key_id'] ?? 0)); if ($bindingError !== null) return $bindingError;
        $worker = STTWorker::findFirst(['conditions'=>'worker_uid = :uid:','bind'=>['uid'=>$workerUid]]);
        if (!$worker instanceof STTWorker || $token === '') return self::error(409, 'job_lease_required');
        $operation = $failure ? 'failure' : 'result';
        $digest = JobSubmissionReceiptService::digest($operation, $data);
        $receipts = new JobSubmissionReceiptService();
        $replay = $receipts->replayOrConflict($jobId, (int)$worker->id, $token, $operation, $digest);
        if ($replay !== null) return self::decorate($replay, $failure);
        $ownershipError = JobLeaseOwnership::preflight($jobId, $workerUid, $token); if ($ownershipError !== null) return $ownershipError;
        $data['id'] = $jobId; $gate = new ModuleLifecycleGate(); $lease = $gate->acquireWriteLease(allowDuringDrain: true); if ($lease === null) return self::error(503, 'module_unavailable');
        try {
            try {
                $result = (new ModuleDatabaseWriterMutex())->synchronized(
                    static function () use ($receipts, $jobId, $worker, $token, $operation, $digest, $failure, $data): PBXApiResult {
                        (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
                        return $receipts->execute($jobId, (int)$worker->id, $token, $operation, $digest, static function () use ($failure, $data): PBXApiResult {
                            return self::decorate($failure ? LocalSpeechToTextMain::submitError($data) : LocalSpeechToTextMain::submitResult($data), $failure);
                        });
                    }
                );
            } catch (ModuleDatabaseWriterBusyException) {
                return self::error(503, 'database_busy', 2);
            }
        }
        finally { $gate->releaseWriteLease($lease); }
        return self::decorate($result, $failure);
    }
    public static function decorate(PBXApiResult $result, bool $failure): PBXApiResult { $result->processor = $failure ? \Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobFailures\Processor::class . '::callBack' : self::class . '::callBack'; $result->function = 'update'; return $result; }
    private static function error(int $status, string $code, int $retryAfter = 5): PBXApiResult { $r = new PBXApiResult(); $r->success=false; $r->httpCode=$status; $r->data=['error_code'=>$code]; if($status===503)$r->data['retry_after']=$retryAfter; return $r; }
}
