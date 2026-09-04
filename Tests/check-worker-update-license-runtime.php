<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    final class PbxSettings
    {
        public const string PBX_LICENSE = 'PBXLicense';
        public static string $license = '';
        public static function getValueByKey(string $key): string { return self::$license; }
    }
}

namespace MikoPBX\PBXCoreREST\Lib {
    class PBXApiResult
    {
        public bool $success = false;
        public int $httpCode = 200;
        public mixed $data = null;
        public array $messages = ['error' => []];
        public string $processor = '';
        public string $function = '';
    }
}

namespace Phalcon\Di { class Injectable {} }

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    use MikoPBX\PBXCoreREST\Lib\PBXApiResult;

    final class WorkerApiContract
    {
        public static function requireVersion(array $request): ?PBXApiResult
        {
            return ($request['version_ok'] ?? false) ? null : new PBXApiResult();
        }
    }

    final class WorkerBindingDecision
    {
        public static function authorize(string $workerUid, int $apiKeyId): ?PBXApiResult
        {
            if ($workerUid === 'worker-1' && $apiKeyId === 42) return null;
            $result = new PBXApiResult();
            $result->httpCode = 403;
            return $result;
        }
    }

    final class ModuleLifecycleGate
    {
        public function acquireWriteLease(): mixed { return fopen('php://memory', 'r'); }
        public function releaseWriteLease(mixed $lease): void { fclose($lease); }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common {
    final class WorkerRequestContext
    {
        public static function data(array $request): array { return $request['data'] ?? []; }
        public static function isRestrictedWorkerKey(array $data): bool { return $data['_restricted'] ?? false; }
    }
}

namespace {
    use MikoPBX\Common\Models\PbxSettings;
    use Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerUpdateLicense\Processor;

    function expectWorkerUpdateLicenseRuntime(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    require_once __DIR__ . '/../Lib/Services/WorkerUpdateLicenseService.php';
    require_once __DIR__ . '/../Lib/RestAPI/WorkerUpdateLicense/Processor.php';

    $request = [
        'version_ok' => true,
        'action' => 'getList',
        'data' => [
            '_restricted' => true,
            '_worker_uid_header' => 'worker-1',
            '_api_key_id' => 42,
        ],
    ];
    PbxSettings::$license = 'station-license-key';
    $result = Processor::callBack($request);
    expectWorkerUpdateLicenseRuntime($result->success && $result->httpCode === 200, 'Authorized worker must receive the update credential');
    expectWorkerUpdateLicenseRuntime(($result->data['license_hash'] ?? '') === hash('sha256', PbxSettings::$license), 'Endpoint returned the wrong license hash');
    expectWorkerUpdateLicenseRuntime(!str_contains(json_encode($result->data, JSON_THROW_ON_ERROR), PbxSettings::$license), 'Endpoint exposed the raw PBX license');

    PbxSettings::$license = '';
    $missing = Processor::callBack($request);
    expectWorkerUpdateLicenseRuntime(!$missing->success && $missing->httpCode === 409 && ($missing->data['error_code'] ?? '') === 'license_not_configured', 'Missing PBX license must return 409');

    $request['data']['_restricted'] = false;
    expectWorkerUpdateLicenseRuntime(Processor::callBack($request)->httpCode === 403, 'Non-worker API keys must be rejected');

    echo "Worker update license runtime OK\n";
}
