<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallSummaryService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CanonicalJobId;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerBindingDecision;
use Modules\ModuleLocalSpeechToText\Models\STTSummary;

/**
 * The gate every summary route shares with the job routes: worker API
 * version, a restricted worker key, the key-to-worker binding, the module
 * lifecycle write lease and the single database writer.
 */
final class SummaryRequestDispatcher
{
    public const string CLAIM = 'claim';
    public const string HEARTBEAT = 'heartbeat';
    public const string RELEASE = 'release';
    public const string RESULT = 'result';
    public const string FAILURE = 'failure';

    public static function dispatch(array $request, string $operation, string $processor, string $function): PBXApiResult
    {
        $result = self::run($request, $operation);
        $result->processor = $processor;
        $result->function = $function;

        return $result;
    }

    private static function run(array $request, string $operation): PBXApiResult
    {
        $versionError = WorkerApiContract::requireVersion($request);
        if ($versionError !== null) return $versionError;
        $data = WorkerRequestContext::data($request);
        if (!WorkerRequestContext::isRestrictedWorkerKey($data)) return self::error(403, 'worker_authorization_forbidden');

        $workerUid = trim(is_scalar($data['worker_uid'] ?? null) ? (string)$data['worker_uid'] : '');
        if ($workerUid === '') return self::error(403, 'worker_identity_forbidden');
        $bindingError = WorkerBindingDecision::authorize($workerUid, (int)($data['_api_key_id'] ?? 0));
        if ($bindingError !== null) return $bindingError;
        if ($operation !== self::CLAIM) {
            $routeId = (int)($data['id'] ?? 0);
            if (array_key_exists('_idFromRequestBody', $data) && !CanonicalJobId::matchesRoute($data['_idFromRequestBody'], $routeId)) {
                return self::error(409, 'summary_id_mismatch');
            }
            $data['id'] = $routeId;
        }

        $gate = new ModuleLifecycleGate();
        $writeLease = $gate->acquireWriteLease(allowDuringDrain: $operation !== self::CLAIM);
        if ($writeLease === null) return self::error(503, 'module_unavailable', 5);
        try {
            return (new ModuleDatabaseWriterMutex())->synchronized(
                static function () use ($operation, $data): PBXApiResult {
                    (new ModuleDatabasePragmas())->configureRuntime((new STTSummary())->getWriteConnection());
                    $service = new CallSummaryService();
                    return match ($operation) {
                        self::CLAIM => $service->claim($data),
                        self::HEARTBEAT => $service->heartbeat($data),
                        self::RELEASE => $service->release($data),
                        self::RESULT => $service->submitResult($data),
                        default => $service->submitFailure($data),
                    };
                }
            );
        } catch (ModuleDatabaseWriterBusyException) {
            return self::error(503, 'database_busy', 2);
        } finally {
            $gate->releaseWriteLease($writeLease);
        }
    }

    private static function error(int $status, string $code, int $retryAfter = 0): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = $status;
        $result->data = ['error_code' => $code];
        if ($retryAfter > 0) $result->data['retry_after'] = $retryAfter;
        $result->messages['error'][] = 'Summary request was rejected';

        return $result;
    }
}
