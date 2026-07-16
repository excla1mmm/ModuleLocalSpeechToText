<?php

declare(strict_types=1);

use MikoPBX\PBXCoreREST\Services\ApiKeyPermissionChecker;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;

require_once dirname(__DIR__, 2) . '/../Core/src/PBXCoreREST/Attributes/ActionType.php';
require_once dirname(__DIR__, 2) . '/../Core/src/PBXCoreREST/Services/ApiKeyPermissionChecker.php';
require_once __DIR__ . '/../Lib/Services/WorkerApiKeyService.php';

function expectWorkerPermission(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expectedPaths = [
    '/api/v3/module-local-speech-to-text/workers' => 'write',
    '/api/v3/module-local-speech-to-text/jobs' => 'write',
];

expectWorkerPermission(
    defined(WorkerApiKeyService::class . '::WORKER_ALLOWED_PATHS'),
    'WorkerApiKeyService must expose the canonical minimal worker permissions'
);

/** @var array<string, string> $allowedPaths */
$allowedPaths = WorkerApiKeyService::WORKER_ALLOWED_PATHS;
expectWorkerPermission(
    $allowedPaths === $expectedPaths,
    'Worker credentials must contain only the workers and jobs resources'
);

$key = (object)[
    'id' => 1,
    'full_permissions' => '0',
    'allowed_paths' => json_encode($allowedPaths, JSON_THROW_ON_ERROR),
];
$checker = new ApiKeyPermissionChecker();

foreach ([
    ['POST', '/pbxcore/api/v3/module-local-speech-to-text/workers:register'],
    ['GET', '/pbxcore/api/v3/module-local-speech-to-text/jobs:getNext?worker_uid=worker-a'],
    ['GET', '/pbxcore/api/v3/module-local-speech-to-text/jobs/42:download'],
    ['POST', '/pbxcore/api/v3/module-local-speech-to-text/jobs/42:submitResult'],
    ['POST', '/pbxcore/api/v3/module-local-speech-to-text/jobs/42:submitError'],
    ['POST', '/pbxcore/api/v3/module-local-speech-to-text/jobs/42:heartbeat'],
    ['POST', '/pbxcore/api/v3/module-local-speech-to-text/jobs/42:release'],
] as [$method, $path]) {
    expectWorkerPermission(
        $checker->checkPermission($key, $path, $method),
        "Minimal worker credential must permit {$method} {$path}"
    );
}

foreach ([
    '/pbxcore/api/v3/module-local-speech-to-text/transcripts',
    '/pbxcore/api/v3/module-local-speech-to-text/transcripts/events',
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
    str_contains($setup, 'WorkerApiKeyService::WORKER_ALLOWED_PATHS')
        && str_contains($setup, "full_permissions = '0'")
        && str_contains($setup, '$apiKey->allowed_paths = json_encode('),
    'Upgrade reconciliation must replace legacy worker permissions and clear full access'
);

echo "Worker API-key permissions OK\n";
