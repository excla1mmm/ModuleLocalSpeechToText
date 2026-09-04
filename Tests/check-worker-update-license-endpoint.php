<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    final class PbxSettings
    {
        public const string PBX_LICENSE = 'PBXLicense';
        public static string $license = '';

        public static function getValueByKey(string $key): string
        {
            return $key === self::PBX_LICENSE ? self::$license : '';
        }
    }
}

namespace {
    use MikoPBX\Common\Models\PbxSettings;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerUpdateLicenseService;

    function expectWorkerUpdateLicense(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = dirname(__DIR__);
    $controllerPath = $root . '/Lib/RestAPI/WorkerUpdateLicense/Controller.php';
    $processorPath = $root . '/Lib/RestAPI/WorkerUpdateLicense/Processor.php';
    $schemaPath = $root . '/Lib/RestAPI/WorkerUpdateLicense/DataStructure.php';
    $servicePath = $root . '/Lib/Services/WorkerUpdateLicenseService.php';

    foreach ([$controllerPath, $processorPath, $schemaPath, $servicePath] as $path) {
        expectWorkerUpdateLicense(is_file($path), basename($path) . ' is missing');
    }

    require_once $servicePath;
    PbxSettings::$license = '  test-license-key  ';
    expectWorkerUpdateLicense(
        (new WorkerUpdateLicenseService())->licenseHash() === hash('sha256', 'test-license-key'),
        'The PBX license must be trimmed and SHA-256 hashed before leaving the station'
    );
    PbxSettings::$license = " \n\t ";
    expectWorkerUpdateLicense(
        (new WorkerUpdateLicenseService())->licenseHash() === null,
        'An empty PBX license must not produce an update credential'
    );

    $controller = (string)file_get_contents($controllerPath);
    $processor = (string)file_get_contents($processorPath);
    $schema = (string)file_get_contents($schemaPath);

    expectWorkerUpdateLicense(str_contains($controller, "'/worker-update-license'"), 'Worker update-license route is missing');
    expectWorkerUpdateLicense(str_contains($controller, 'SecurityType::BEARER_TOKEN'), 'Update-license route must require bearer authentication');
    expectWorkerUpdateLicense(str_contains($processor, 'WorkerApiContract::requireVersion($request)'), 'Update-license route must require worker API v2');
    expectWorkerUpdateLicense(str_contains($processor, 'WorkerRequestContext::isRestrictedWorkerKey($data)'), 'Update-license route must reject non-worker API keys');
    expectWorkerUpdateLicense(str_contains($processor, 'WorkerBindingDecision::authorize($workerUid'), 'Update-license route must authorize the worker/key binding');
    expectWorkerUpdateLicense(str_contains($processor, "'license_not_configured'"), 'Missing PBX licenses must return an explicit error');
    expectWorkerUpdateLicense(str_contains($schema, "'license_hash'"), 'Update-license response must document license_hash');
    expectWorkerUpdateLicense(str_contains($schema, "'^[a-f0-9]{64}$'"), 'license_hash must be documented as lowercase SHA-256');

    echo "Worker update license endpoint OK\n";
}
