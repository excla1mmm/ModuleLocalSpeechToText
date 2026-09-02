<?php

declare(strict_types=1);

use MikoPBX\PBXCoreREST\Services\ApiKeyPermissionChecker;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;

require_once __DIR__ . '/fixtures/core/ActionType.php';
require_once __DIR__ . '/fixtures/core/ApiKeyPermissionChecker.php';
require_once __DIR__ . '/../Lib/Services/WorkerApiKeyService.php';

function expectWorkerPermission(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expectedPaths = [
    '/api/v3/module-local-speech-to-text/worker-api-contract' => 'read',
    '/api/v3/module-local-speech-to-text/workers' => 'write',
    '/api/v3/module-local-speech-to-text/worker-processing-settings' => 'read',
    '/api/v3/module-local-speech-to-text/job-leases' => 'write',
    '/api/v3/module-local-speech-to-text/job-recordings' => 'read',
    '/api/v3/module-local-speech-to-text/job-results' => 'write',
    '/api/v3/module-local-speech-to-text/job-failures' => 'write',
];

expectWorkerPermission(
    defined(WorkerApiKeyService::class . '::WORKER_ALLOWED_PATHS'),
    'WorkerApiKeyService must expose the canonical minimal worker permissions'
);

/** @var array<string, string> $allowedPaths */
$allowedPaths = WorkerApiKeyService::WORKER_ALLOWED_PATHS;
expectWorkerPermission(
    $allowedPaths === $expectedPaths,
    'Worker credentials must contain the exact seven worker API v2 paths'
);

$key = (object)[
    'id' => 1,
    'full_permissions' => '0',
    'allowed_paths' => json_encode($allowedPaths, JSON_THROW_ON_ERROR),
];
$checker = new ApiKeyPermissionChecker();

foreach ([
    ['GET', '/pbxcore/api/v3/module-local-speech-to-text/worker-api-contract'],
    ['POST', '/pbxcore/api/v3/module-local-speech-to-text/workers'],
    ['GET', '/pbxcore/api/v3/module-local-speech-to-text/worker-processing-settings'],
    ['POST', '/pbxcore/api/v3/module-local-speech-to-text/job-leases'],
    ['PATCH', '/pbxcore/api/v3/module-local-speech-to-text/job-leases/42'],
    ['DELETE', '/pbxcore/api/v3/module-local-speech-to-text/job-leases/42'],
    ['GET', '/pbxcore/api/v3/module-local-speech-to-text/job-recordings/42'],
    ['PUT', '/pbxcore/api/v3/module-local-speech-to-text/job-results/42'],
    ['PUT', '/pbxcore/api/v3/module-local-speech-to-text/job-failures/42'],
] as [$method, $path]) {
    expectWorkerPermission(
        $checker->checkPermission($key, $path, $method),
        "Minimal worker credential must permit {$method} {$path}"
    );
}

foreach ([
    '/pbxcore/api/v3/module-local-speech-to-text/jobs',
    '/pbxcore/api/v3/module-local-speech-to-text/jobs:getNext',
    '/pbxcore/api/v3/module-local-speech-to-text/jobs/42:download',
    '/pbxcore/api/v3/module-local-speech-to-text/transcripts',
    '/pbxcore/api/v3/module-local-speech-to-text/call-transcripts/42',
    '/pbxcore/api/v3/module-local-speech-to-text/call-transcripts/events',
] as $path) {
    expectWorkerPermission(
        !$checker->checkPermission($key, $path, 'GET'),
        "Worker credential must not read transcript data via {$path}"
    );
}

$setup = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
expectWorkerPermission($setup !== false, 'Cannot read PbxExtensionSetup.php');
expectWorkerPermission(
    str_contains($setup, 'reconcileStoredWorkerApiKeys()'),
    'Upgrade reconciliation must replace legacy worker permissions and clear full access'
);

echo "Worker API-key permissions OK\n";
