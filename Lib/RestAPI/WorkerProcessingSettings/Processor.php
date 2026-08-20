<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerProcessingSettings;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerBindingDecision;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Phalcon\Di\Injectable;

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $versionError = WorkerApiContract::requireVersion($request);
        if ($versionError !== null) return $versionError;
        $data = WorkerRequestContext::data($request);
        if (!WorkerRequestContext::isRestrictedWorkerKey($data)) return self::forbidden();
        if (($request['action'] ?? null) !== 'getList') return self::forbidden();
        $workerUidHeader = trim((string)($data['_worker_uid_header'] ?? ''));
        unset($data['worker_uid']);
        if ($workerUidHeader === '') return self::forbidden();
        $bindingError = WorkerBindingDecision::authorize($workerUidHeader, (int)($data['_api_key_id'] ?? 0));
        if ($bindingError !== null) return $bindingError;
        $data['worker_uid'] = $workerUidHeader;

        $gate = new ModuleLifecycleGate();
        $writeLease = $gate->acquireWriteLease();
        if ($writeLease === null) return self::unavailable();
        try {
            try {
                $result = (new ModuleDatabaseWriterMutex())->synchronized(
                    static function () use ($data): PBXApiResult {
                        (new ModuleDatabasePragmas())->configureRuntime((new STTWorker())->getWriteConnection());
                        return LocalSpeechToTextMain::getWorkerProcessingSettings($data);
                    }
                );
            } catch (ModuleDatabaseWriterBusyException) {
                return self::databaseBusy();
            }
        } finally {
            $gate->releaseWriteLease($writeLease);
        }
        $result->processor = __METHOD__;
        $result->function = 'getList';
        return $result;
    }

    private static function forbidden(): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 403;
        $result->data = ['error_code' => 'worker_identity_forbidden'];
        $result->messages['error'][] = 'Worker identity does not match the restricted API key';
        return $result;
    }

    private static function unavailable(): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 503;
        $result->data = ['retry_after' => 5];
        $result->messages['error'][] = 'ModuleLocalSpeechToText is temporarily unavailable during maintenance';
        return $result;
    }

    private static function databaseBusy(): PBXApiResult
    {
        $result = new PBXApiResult(); $result->success = false; $result->httpCode = 503;
        $result->data = ['error_code' => 'database_busy', 'retry_after' => 2];
        $result->messages['error'][] = 'ModuleLocalSpeechToText database writer is temporarily busy';
        return $result;
    }
}
