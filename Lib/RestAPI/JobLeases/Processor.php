<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Phalcon\Di\Injectable;

enum JobLeaseAction: string
{
    case CREATE = 'create';
    case PATCH = 'patch';
    case DELETE = 'delete';
}

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $versionError = WorkerApiContract::requireVersion($request);
        if ($versionError !== null) return $versionError;
        $data = WorkerRequestContext::data($request);
        if (!WorkerRequestContext::isRestrictedWorkerKey($data)) return self::forbidden('worker_authorization_forbidden');
        $action = JobLeaseAction::tryFrom((string)($request['action'] ?? ''));
        if (!$action instanceof JobLeaseAction) return self::forbidden('worker_action_forbidden');

        $workerUidHeader = trim((string)($data['_worker_uid_header'] ?? ''));
        $bodyWorkerUid = trim(is_scalar($data['worker_uid'] ?? null) ? (string)$data['worker_uid'] : '');
        if ($workerUidHeader === '' || $bodyWorkerUid === '' || $bodyWorkerUid !== $workerUidHeader || !self::isBoundWorker($workerUidHeader, (int)($data['_api_key_id'] ?? 0))) {
            return self::forbidden('worker_identity_forbidden');
        }
        if ($action !== JobLeaseAction::CREATE) {
            $routeJobId = (int)($request['id'] ?? $data['id'] ?? 0);
            $bodyJobId = array_key_exists('id', $data) ? (int)$data['id'] : $routeJobId;
            if ($routeJobId <= 0 || $routeJobId !== $bodyJobId) return self::conflict('job_id_mismatch');
            $data['id'] = $routeJobId;
            if (trim(is_scalar($data['lease_token'] ?? null) ? (string)$data['lease_token'] : '') === '') return self::conflict('job_lease_required');
        }

        $gate = new ModuleLifecycleGate();
        $writeLease = $gate->acquireWriteLease(allowDuringDrain: $action !== JobLeaseAction::CREATE);
        if ($writeLease === null) return self::unavailable();
        try {
            (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
            $result = match ($action) {
                JobLeaseAction::CREATE => LocalSpeechToTextMain::getNextJob($data),
                JobLeaseAction::PATCH => LocalSpeechToTextMain::heartbeatJob($data),
                JobLeaseAction::DELETE => LocalSpeechToTextMain::releaseJob($data),
            };
        } finally {
            $gate->releaseWriteLease($writeLease);
        }
        $result->processor = __METHOD__;
        $result->function = $action->value;
        return $result;
    }

    private static function isBoundWorker(string $uid, int $keyId): bool
    {
        $worker = STTWorker::findFirst(['conditions' => 'worker_uid = :worker_uid:', 'bind' => ['worker_uid' => $uid]]);
        return $worker instanceof STTWorker && $keyId > 0 && (int)$worker->api_key_id === $keyId;
    }

    private static function forbidden(string $code): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 403; $result->data = ['error_code' => $code];
        $result->messages['error'][] = 'Worker lease authorization failed'; return $result;
    }

    private static function conflict(string $code): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 409; $result->data = ['error_code' => $code];
        $result->messages['error'][] = 'Worker lease ownership data conflicts with the route'; return $result;
    }

    private static function unavailable(): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 503; $result->data = ['retry_after' => 5];
        $result->messages['error'][] = 'ModuleLocalSpeechToText is temporarily unavailable during maintenance'; return $result;
    }
}
