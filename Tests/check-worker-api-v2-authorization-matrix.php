<?php
declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;

require_once __DIR__ . '/../Lib/Services/WorkerApiKeyService.php';

function expectAuthorizationMatrix(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$expected = [
    '/api/v3/module-local-speech-to-text/worker-api-contract' => 'read',
    '/api/v3/module-local-speech-to-text/workers' => 'write',
    '/api/v3/module-local-speech-to-text/worker-processing-settings' => 'read',
    '/api/v3/module-local-speech-to-text/job-leases' => 'write',
    '/api/v3/module-local-speech-to-text/job-recordings' => 'read',
    '/api/v3/module-local-speech-to-text/job-results' => 'write',
    '/api/v3/module-local-speech-to-text/job-failures' => 'write',
    '/api/v3/module-local-speech-to-text/jobs' => 'write',
];
expectAuthorizationMatrix(WorkerApiKeyService::WORKER_ALLOWED_PATHS === $expected, 'Exact v2 worker permission map changed');

foreach (['Workers','WorkerProcessingSettings','JobLeases','JobRecordings','JobResults','JobFailures','Jobs'] as $resource) {
    $source = (string)file_get_contents(__DIR__ . "/../Lib/RestAPI/{$resource}/Processor.php");
    expectAuthorizationMatrix($source !== '', "Missing {$resource} processor");
    if ($resource === 'Jobs') expectAuthorizationMatrix(str_contains($source, 'WorkerRequestContext::isRestrictedWorkerKey($data)') && str_contains($source, '403'), 'Restricted keys must be denied management Jobs');
    if (!in_array($resource, ['Workers','Jobs','JobFailures'], true)) expectAuthorizationMatrix(str_contains($source, 'WorkerRequestContext::isRestrictedWorkerKey($data)'), "{$resource} must require restricted provenance");
    if ($resource === 'JobFailures') expectAuthorizationMatrix(str_contains($source, 'ResultProcessor::dispatch($request, true)'), 'JobFailures must delegate to the gated result processor');
}
$executableMatrix = [
    'check-worker-crud-v2-contract.php' => 'STT worker CRUD v2 behavior contract OK',
    'check-worker-job-leases-v2-runtime.php' => 'STT worker job lease v2 executable processor behavior OK',
    'check-worker-job-artifact-processors-runtime.php' => 'STT artifact processors executable replay behavior OK',
    'check-management-jobs-v2-runtime.php' => 'STT management Jobs v2 runtime OK',
];
foreach ($executableMatrix as $test => $sentinel) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $test) . ' 2>&1';
    exec($command, $output, $status);
    expectAuthorizationMatrix($status === 0 && str_contains(implode("\n", $output), $sentinel), "Executable authorization matrix failed: {$test}");
}
if (getenv('WORKER_API_V2_PERMISSION_MUTATION') === '1') {
    $mutated = $expected; unset($mutated['/api/v3/module-local-speech-to-text/job-results']);
    expectAuthorizationMatrix($mutated === WorkerApiKeyService::WORKER_ALLOWED_PATHS, 'Permission mutation detected');
}
echo "STT worker API v2 authorization matrix OK\n";
