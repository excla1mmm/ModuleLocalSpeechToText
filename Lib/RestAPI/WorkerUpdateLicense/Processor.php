<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerUpdateLicense;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common\WorkerRequestContext;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerBindingDecision;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerUpdateLicenseService;
use Phalcon\Di\Injectable;
use Throwable;

final class Processor extends Injectable
{
    public static function callBack(array $request): PBXApiResult
    {
        $versionError = WorkerApiContract::requireVersion($request);
        if ($versionError !== null) return $versionError;
        $data = WorkerRequestContext::data($request);
        if (!WorkerRequestContext::isRestrictedWorkerKey($data)) return self::error(403, 'worker_identity_forbidden');
        if (($request['action'] ?? null) !== 'getList') return self::error(403, 'worker_identity_forbidden');
        $workerUid = trim((string)($data['_worker_uid_header'] ?? ''));
        if ($workerUid === '') return self::error(403, 'worker_identity_forbidden');
        $bindingError = WorkerBindingDecision::authorize($workerUid, (int)($data['_api_key_id'] ?? 0));
        if ($bindingError !== null) return $bindingError;

        $gate = new ModuleLifecycleGate();
        $lease = $gate->acquireWriteLease();
        if ($lease === null) return self::error(503, 'module_unavailable');
        try {
            $licenseHash = (new WorkerUpdateLicenseService())->licenseHash();
            if ($licenseHash === null) return self::error(409, 'license_not_configured');

            $result = new PBXApiResult();
            $result->success = true;
            $result->httpCode = 200;
            $result->data = ['license_hash' => $licenseHash];
        } catch (Throwable) {
            $result = self::error(500, 'internal_error');
        } finally {
            $gate->releaseWriteLease($lease);
        }
        $result->processor = __METHOD__;
        $result->function = 'getList';

        return $result;
    }

    private static function error(int $status, string $code): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->httpCode = $status;
        $result->data = ['error' => $code, 'error_code' => $code];
        $result->messages['error'][] = match ($code) {
            'license_not_configured' => 'PBX license is not configured',
            'module_unavailable' => 'ModuleLocalSpeechToText is temporarily unavailable during maintenance',
            'internal_error' => 'Internal server error',
            default => 'Worker identity does not match the restricted API key',
        };

        return $result;
    }
}
