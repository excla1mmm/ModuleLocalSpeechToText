<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex;
use Modules\ModuleLocalSpeechToText\Lib\Services\CanonicalJobId;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\JobLeaseOwnership;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerBindingDecision;
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

        $bodyWorkerUid = trim(is_scalar($data['worker_uid'] ?? null) ? (string)$data['worker_uid'] : '');
        if ($bodyWorkerUid === '') {
            return self::forbidden('worker_identity_forbidden');
        }
        $bindingError = WorkerBindingDecision::authorize($bodyWorkerUid, (int)($data['_api_key_id'] ?? 0));
        if ($bindingError !== null) return $bindingError;
        if ($action !== JobLeaseAction::CREATE) {
            $routeJobId = (int)($data['id'] ?? 0);
            if (array_key_exists('_idFromRequestBody', $data)
                && !CanonicalJobId::matchesRoute($data['_idFromRequestBody'], $routeJobId)) {
                return self::conflict('job_id_mismatch');
            }
            $data['id'] = $routeJobId;
            $leaseToken = trim(is_scalar($data['lease_token'] ?? null) ? (string)$data['lease_token'] : '');
            if ($leaseToken === '') return self::conflict('job_lease_required');
            $ownershipError = JobLeaseOwnership::preflight($routeJobId, $bodyWorkerUid, $leaseToken);
            if ($ownershipError !== null) return $ownershipError;
        }

        $gate = new ModuleLifecycleGate();
        $writeLease = $gate->acquireWriteLease(allowDuringDrain: $action !== JobLeaseAction::CREATE);
        if ($writeLease === null) return self::unavailable();
        try {
            try {
                $result = (new ModuleDatabaseWriterMutex())->synchronized(
                    static function () use ($action, $data): PBXApiResult {
                        (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
                        return match ($action) {
                            JobLeaseAction::CREATE => LocalSpeechToTextMain::getNextJob($data),
                            JobLeaseAction::PATCH => LocalSpeechToTextMain::heartbeatJob($data),
                            JobLeaseAction::DELETE => LocalSpeechToTextMain::releaseJob($data),
                        };
                    }
                );
            } catch (ModuleDatabaseWriterBusyException) {
                return self::databaseBusy();
            }
        } finally {
            $gate->releaseWriteLease($writeLease);
        }
        $result->processor = __METHOD__;
        $result->function = $action->value;
        return $result;
    }

    private static function forbidden(string $code): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 403; $result->data = ['error' => $code, 'error_code' => $code];
        $result->messages['error'][] = 'Worker lease authorization failed'; return $result;
    }

    private static function conflict(string $code): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 409; $result->data = ['error' => $code, 'error_code' => $code];
        $result->messages['error'][] = 'Worker lease ownership data conflicts with the route'; return $result;
    }

    private static function unavailable(): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 503;
        $result->data = ['error' => 'module_unavailable', 'error_code' => 'module_unavailable', 'retry_after' => 5];
        $result->messages['error'][] = 'ModuleLocalSpeechToText is temporarily unavailable during maintenance'; return $result;
    }

    private static function databaseBusy(): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 503;
        $result->data = ['error' => 'database_busy', 'error_code' => 'database_busy', 'retry_after' => 2];
        $result->messages['error'][] = 'ModuleLocalSpeechToText database writer is temporarily busy'; return $result;
    }
}
