<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;

final class WorkerBindingDecision
{
    public static function authorize(string $workerUid, int $apiKeyId): ?PBXApiResult
    {
        $worker = STTWorker::findFirst([
            'conditions' => 'worker_uid = :worker_uid:',
            'bind' => ['worker_uid' => $workerUid],
        ]);
        if (!$worker instanceof STTWorker) {
            return self::error('worker_not_registered', 'Worker is not registered', 403);
        }
        if ($apiKeyId <= 0 || (int)$worker->api_key_id !== $apiKeyId) {
            return self::error('worker_api_key_mismatch', 'Worker is bound to another API key', 403);
        }

        $other = STTWorker::findFirst([
            'conditions' => 'api_key_id = :api_key_id: AND id <> :worker_id:',
            'bind' => ['api_key_id' => $apiKeyId, 'worker_id' => (int)$worker->id],
        ]);
        if ($other instanceof STTWorker) {
            return self::error(
                'worker_api_key_already_bound',
                'This API key is ambiguously bound to multiple workers',
                409,
            );
        }

        return null;
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
