<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use RuntimeException;
use Throwable;

final class STTWorkerResourceService
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;
    private const array MUTABLE_FIELDS = ['name', 'model', 'status'];
    private const array INTERNAL_FIELDS = [
        'id', 'httpMethod', '_remote_addr', '_auth_type', '_api_key_id', '_http_method',
        '_auth_user_name', '_auth_session_id',
        '_worker_uid_header', '_lease_token_header',
    ];

    public function getList(array $data): PBXApiResult
    {
        $conditions = [];
        $bind = [];
        foreach (['status', 'worker_uid', 'app_version'] as $field) {
            if (!isset($data[$field]) || !is_scalar($data[$field]) || trim((string)$data[$field]) === '') {
                continue;
            }
            $conditions[] = "{$field} = :{$field}:";
            $bind[$field] = trim((string)$data[$field]);
        }
        if (isset($data['api_version']) && is_scalar($data['api_version']) && (int)$data['api_version'] > 0) {
            $conditions[] = 'api_version = :api_version:';
            $bind['api_version'] = (int)$data['api_version'];
        }

        $requestedLimit = (int)($data['limit'] ?? self::DEFAULT_LIMIT);
        $limit = $requestedLimit > 0 ? min(self::MAX_LIMIT, $requestedLimit) : self::DEFAULT_LIMIT;
        $offset = max(0, (int)($data['offset'] ?? 0));
        $parameters = [
            'order' => 'id ASC',
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($conditions !== []) {
            $parameters['conditions'] = implode(' AND ', $conditions);
            $parameters['bind'] = $bind;
        }

        try {
            $workers = STTWorker::find($parameters);
            $items = [];
            foreach ($workers as $worker) {
                if ($worker instanceof STTWorker) {
                    $items[] = self::formatWorker($worker);
                }
            }

            return self::success($items);
        } catch (Throwable) {
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public function create(array $data): PBXApiResult
    {
        $result = LocalSpeechToTextMain::registerWorker($data);
        if ($result->success && !isset($result->data['id']) && isset($result->data['worker_id'])) {
            $result->data['id'] = (int)$result->data['worker_id'];
        }

        return $result;
    }

    public function getRecord(int $id): PBXApiResult
    {
        $worker = $id > 0 ? STTWorker::findFirstById($id) : null;
        if (!$worker instanceof STTWorker) {
            return self::error('worker_not_found', "Worker #{$id} not found", 404);
        }

        return self::success(self::formatWorker($worker));
    }

    public function update(int $id, array $data): PBXApiResult
    {
        $worker = $id > 0 ? STTWorker::findFirstById($id) : null;
        if (!$worker instanceof STTWorker) {
            return self::error('worker_not_found', "Worker #{$id} not found", 404);
        }

        $payload = self::publicPayload($data);
        $unknown = array_diff(array_keys($payload), self::MUTABLE_FIELDS);
        $missing = array_diff(self::MUTABLE_FIELDS, array_keys($payload));
        if ($unknown !== [] || $missing !== [] || array_key_exists('_idFromRequestBody', $data)) {
            return self::error('validation_error', 'PUT requires exactly name, model and status', 422);
        }

        return $this->persistMutable($worker, $payload);
    }

    public function patch(int $id, array $data): PBXApiResult
    {
        $worker = $id > 0 ? STTWorker::findFirstById($id) : null;
        if (!$worker instanceof STTWorker) {
            return self::error('worker_not_found', "Worker #{$id} not found", 404);
        }

        if (array_key_exists('_idFromRequestBody', $data)) {
            return self::error('validation_error', 'PATCH cannot update id', 422);
        }
        $payload = self::publicPayload($data);
        if ($payload === []) {
            return self::error('validation_error', 'PATCH requires at least one mutable field', 422);
        }
        $unknown = array_diff(array_keys($payload), self::MUTABLE_FIELDS);
        if ($unknown !== []) {
            return self::error('validation_error', 'PATCH may update only name, model and status', 422);
        }

        return $this->persistMutable($worker, $payload);
    }

    public function delete(int $id): PBXApiResult
    {
        $worker = $id > 0 ? STTWorker::findFirstById($id) : null;
        if (!$worker instanceof STTWorker) {
            return self::error('worker_not_found', "Worker #{$id} not found", 404);
        }

        try {
            if (!STTWorker::deleteIfNoActiveLease((int)$worker->id)) {
                return self::error('worker_has_active_lease', 'Worker has an active job lease', 409);
            }

            return self::success(['id' => (int)$worker->id]);
        } catch (Throwable) {
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    private function persistMutable(STTWorker $worker, array $payload): PBXApiResult
    {
        $validated = [];
        foreach (['name', 'model'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            if (!is_scalar($payload[$field]) || strlen(trim((string)$payload[$field])) > 255) {
                return self::error('validation_error', "{$field} must be a string up to 255 characters", 422);
            }
            $validated[$field] = trim((string)$payload[$field]);
        }
        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? trim((string)$payload['status']) : '';
            if (!in_array($status, [STTWorker::STATUS_ONLINE, STTWorker::STATUS_OFFLINE], true)) {
                return self::error('validation_error', 'status must be online or offline', 422);
            }
            $validated['status'] = $status;
        }
        foreach ($validated as $field => $value) {
            $worker->{$field} = $value;
        }
        $worker->updated_at = (string)time();

        try {
            if (!$worker->save()) {
                throw new RuntimeException(implode(', ', $worker->getMessages()));
            }

            return self::success(self::formatWorker($worker));
        } catch (Throwable) {
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    private static function publicPayload(array $data): array
    {
        foreach (self::INTERNAL_FIELDS as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    public static function formatWorker(STTWorker $worker): array
    {
        return [
            'id' => (int)$worker->id,
            'worker_uid' => (string)$worker->worker_uid,
            'name' => (string)$worker->name,
            'ip' => (string)$worker->ip,
            'status' => (string)$worker->status,
            'model' => (string)$worker->model,
            'api_key_id' => $worker->api_key_id === null ? null : (int)$worker->api_key_id,
            'api_version' => (int)$worker->api_version,
            'app_version' => (string)$worker->app_version,
            'compatible' => (int)$worker->api_version === WorkerApiContract::API_VERSION,
            'last_seen' => $worker->last_seen === null ? null : (int)$worker->last_seen,
            'created_at' => (int)$worker->created_at,
            'updated_at' => (int)$worker->updated_at,
        ];
    }

    private static function success(array $data, int $httpCode = 200): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = true;
        $result->httpCode = $httpCode;
        $result->data = $data;

        return $result;
    }

    private static function error(string $code, string $message, int $httpCode): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = $httpCode;
        $result->data = ['error' => $code, 'error_code' => $code];
        $result->messages['error'][] = $message;

        return $result;
    }
}
