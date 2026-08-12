<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\LegacyRouteRejector;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\STTWorkerResourceService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Phalcon\Di\Injectable;

enum WorkerAction: string
{
    case GET_LIST = 'getList';
    case CREATE = 'create';
    case GET_RECORD = 'getRecord';
    case UPDATE = 'update';
    case PATCH = 'patch';
    case DELETE = 'delete';
    case REGISTER = 'register';
    case GET_PROCESSING_SETTINGS = 'getProcessingSettings';
}

class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $actionString = (string)($request['action'] ?? '');
        $action = WorkerAction::tryFrom($actionString);

        return match ($action) {
            WorkerAction::REGISTER => LegacyRouteRejector::worker(),
            WorkerAction::GET_PROCESSING_SETTINGS => LegacyRouteRejector::worker(),
            default => (static function () use ($request, $action, $actionString): PBXApiResult {
                $data = WorkerRequestContext::data($request);
                if ($action === WorkerAction::CREATE && WorkerRequestContext::isRestrictedWorkerKey($data)) {
                    $versionError = WorkerApiContract::requireVersion($request);
                    if ($versionError !== null) {
                        return $versionError;
                    }
                }

                if (WorkerRequestContext::isRestrictedWorkerKey($data)) {
                    if ($action !== WorkerAction::CREATE) {
                        return self::forbidden('worker_resource_forbidden', 'Worker credentials may only self-register');
                    }
                    $bindingError = self::restrictedCreateError($data);
                    if ($bindingError !== null) {
                        return $bindingError;
                    }
                } elseif ($action === WorkerAction::CREATE) {
                    unset($data['_auth_type'], $data['_api_key_id']);
                }

                if (!$action instanceof WorkerAction) {
                    return self::unknownAction($actionString);
                }

                $gate = new ModuleLifecycleGate();
                $writeLease = $gate->acquireWriteLease();
                if ($writeLease === null) {
                    $result = new PBXApiResult();
                    $result->success = false;
                    $result->httpCode = 503;
                    $result->function = $actionString;
                    $result->messages['error'][] = 'ModuleLocalSpeechToText is temporarily unavailable during maintenance';
                    $result->data['retry_after'] = 5;

                    return $result;
                }

                try {
                    (new ModuleDatabasePragmas())->configureRuntime((new STTWorker())->getWriteConnection());
                    $service = new STTWorkerResourceService();
                    $id = (int)($data['id'] ?? 0);
                    $result = match ($action) {
                        WorkerAction::GET_LIST => $service->getList($data),
                        WorkerAction::CREATE => LocalSpeechToTextMain::registerWorker($data),
                        WorkerAction::GET_RECORD => $service->getRecord($id),
                        WorkerAction::UPDATE => $service->update($id, $data),
                        WorkerAction::PATCH => $service->patch($id, $data),
                        WorkerAction::DELETE => $service->delete($id),
                        default => self::unknownAction($actionString),
                    };
                } finally {
                    $gate->releaseWriteLease($writeLease);
                }

                $result->processor = self::class . '::callBack';
                $result->function = $actionString;

                return $result;
            })(),
        };
    }

    private static function restrictedCreateError(array $data): ?PBXApiResult
    {
        $workerUid = is_scalar($data['worker_uid'] ?? null) ? trim((string)$data['worker_uid']) : '';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/', $workerUid) !== 1) {
            return self::validationError('worker_uid format is invalid');
        }

        $apiKeyId = (int)($data['_api_key_id'] ?? 0);
        $worker = STTWorker::findFirst([
            'conditions' => 'worker_uid = :worker_uid:',
            'bind' => ['worker_uid' => $workerUid],
        ]);
        if ($worker instanceof STTWorker) {
            $boundApiKeyId = (int)$worker->api_key_id;
            if ($boundApiKeyId > 0 && $boundApiKeyId !== $apiKeyId) {
                return self::forbidden('worker_api_key_mismatch', 'Worker is bound to another API key');
            }
        }

        $otherWorker = STTWorker::findFirst([
            'conditions' => 'api_key_id = :api_key_id: AND worker_uid <> :other_worker_uid:',
            'bind' => ['api_key_id' => $apiKeyId, 'other_worker_uid' => $workerUid],
        ]);
        if ($otherWorker instanceof STTWorker) {
            return self::forbidden('worker_api_key_already_bound', 'This API key is already bound to another worker');
        }

        return null;
    }

    private static function requestData(array $request): array
    {
        $data = is_array($request['data'] ?? null) ? $request['data'] : [];
        unset($data['_remote_addr'], $data['_auth_type'], $data['_api_key_id']);
        $sessionContext = is_array($request['sessionContext'] ?? null) ? $request['sessionContext'] : [];
        if (isset($sessionContext['remote_addr']) && is_scalar($sessionContext['remote_addr'])) {
            $data['_remote_addr'] = (string)$sessionContext['remote_addr'];
        }
        if (isset($sessionContext['auth_type']) && is_scalar($sessionContext['auth_type'])) {
            $data['_auth_type'] = (string)$sessionContext['auth_type'];
        }
        if (isset($sessionContext['token_id']) && is_scalar($sessionContext['token_id'])) {
            $data['_api_key_id'] = (int)$sessionContext['token_id'];
        }

        return $data;
    }

    private static function forbidden(string $code, string $message): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = 403;
        $result->data = ['error' => $code, 'error_code' => $code];
        $result->messages['error'][] = $message;

        return $result;
    }

    private static function validationError(string $message): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = 422;
        $result->data = ['error' => 'validation_error', 'error_code' => 'validation_error'];
        $result->messages['error'][] = $message;

        return $result;
    }

    private static function unknownAction(string $actionString): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->processor = self::class . '::callBack';
        $result->function = $actionString;
        $result->messages['error'][] = "Unknown action - {$actionString} in " . __CLASS__;

        return $result;
    }
}
