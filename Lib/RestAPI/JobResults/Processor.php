<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\{CanonicalJobId,JobLeaseOwnership,ModuleLifecycleGate,WorkerApiContract,WorkerBindingDecision};
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
        $headerUid = trim((string)($data['_worker_uid_header'] ?? '')); $token = trim(is_scalar($data['lease_token'] ?? null) ? (string)$data['lease_token'] : '');
        if ($workerUid === '' || $headerUid === '' || $workerUid !== $headerUid) return self::error(403, 'worker_identity_forbidden');
        $bindingError = WorkerBindingDecision::authorize($headerUid, (int)($data['_api_key_id'] ?? 0)); if ($bindingError !== null) return $bindingError;
        $ownershipError = JobLeaseOwnership::preflight($jobId, $workerUid, $token); if ($ownershipError !== null) return $ownershipError;
        $data['id'] = $jobId; $gate = new ModuleLifecycleGate(); $lease = $gate->acquireWriteLease(allowDuringDrain: true); if ($lease === null) return self::error(503, 'module_unavailable');
        try { $result = $failure ? LocalSpeechToTextMain::submitError($data) : LocalSpeechToTextMain::submitResult($data); }
        finally { $gate->releaseWriteLease($lease); }
        $result->processor = __METHOD__; $result->function = 'update'; return $result;
    }
    private static function error(int $status, string $code): PBXApiResult { $r = new PBXApiResult(); $r->success=false; $r->httpCode=$status; $r->data=['error_code'=>$code]; return $r; }
}
