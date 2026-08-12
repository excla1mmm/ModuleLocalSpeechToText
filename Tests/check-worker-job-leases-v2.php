<?php
declare(strict_types=1);

function expectWorkerLeaseV2(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$paths = [
    'settingsController' => $root . '/Lib/RestAPI/WorkerProcessingSettings/Controller.php',
    'settingsProcessor' => $root . '/Lib/RestAPI/WorkerProcessingSettings/Processor.php',
    'leaseController' => $root . '/Lib/RestAPI/JobLeases/Controller.php',
    'leaseProcessor' => $root . '/Lib/RestAPI/JobLeases/Processor.php',
];
foreach ($paths as $name => $path) {
    expectWorkerLeaseV2(is_file($path), "$name is missing");
}
$settingsController = (string)file_get_contents($paths['settingsController']);
$settingsProcessor = (string)file_get_contents($paths['settingsProcessor']);
$leaseController = (string)file_get_contents($paths['leaseController']);
$leaseProcessor = (string)file_get_contents($paths['leaseProcessor']);
$main = (string)file_get_contents($root . '/Lib/LocalSpeechToTextMain.php');
$jobsSchema = (string)file_get_contents($root . '/Lib/RestAPI/Jobs/DataStructure.php');
$workersProcessor = (string)file_get_contents($root . '/Lib/RestAPI/Workers/Processor.php');

expectWorkerLeaseV2(str_contains($settingsController, "'/worker-processing-settings'") && str_contains($settingsController, "'GET' => ['getList']") && str_contains($settingsController, "collectionLevelMethods: ['getList']"), 'Settings must be a GET collection singleton');
expectWorkerLeaseV2(!str_contains($settingsController, 'resourceLevelMethods:'), 'Settings must have no item route');
expectWorkerLeaseV2(str_contains($leaseController, "'/job-leases'") && str_contains($leaseController, "'POST' => ['create']") && str_contains($leaseController, "'PATCH' => ['patch']") && str_contains($leaseController, "'DELETE' => ['delete']") && str_contains($leaseController, "idPattern: '[0-9]+'"), 'Lease methods or id pattern changed');
foreach (["case CREATE = 'create';", "case PATCH = 'patch';", "case DELETE = 'delete';"] as $case) {
    expectWorkerLeaseV2(str_contains($leaseProcessor, $case), "Missing JobLeaseAction $case");
}
foreach ([$settingsProcessor, $leaseProcessor] as $source) {
    $version = strpos($source, 'WorkerApiContract::requireVersion($request)');
    $context = strpos($source, 'WorkerRequestContext::data($request)');
    $gate = strpos($source, 'acquireWriteLease(');
    expectWorkerLeaseV2($version !== false && $context !== false && $gate !== false && $version < $context && $context < $gate, 'Version/auth preflight must precede lifecycle acquisition');
    expectWorkerLeaseV2(str_contains($source, 'WorkerRequestContext::isRestrictedWorkerKey($data)'), 'Resources must fail closed to restricted worker keys');
    expectWorkerLeaseV2(str_contains($source, "['_worker_uid_header']"), 'Worker identity must use the UID header');
}
expectWorkerLeaseV2(str_contains($settingsProcessor, "unset(\$data['worker_uid'])") && str_contains($settingsProcessor, "\$data['worker_uid'] = \$workerUidHeader"), 'Body-only UID must not select settings');
expectWorkerLeaseV2(str_contains($leaseProcessor, '$bodyWorkerUid !== $workerUidHeader') && str_contains($leaseProcessor, '$routeJobId !== $bodyJobId'), 'Lease ownership mismatch preflight is missing');
expectWorkerLeaseV2(str_contains($leaseProcessor, 'allowDuringDrain: $action !== JobLeaseAction::CREATE'), 'Drain lifecycle policy changed');

$start = strpos($main, 'private static function formatJob');
$end = $start === false ? false : strpos($main, 'private static function jobDurationSeconds', $start);
$formatter = $start === false || $end === false ? '' : substr($main, $start, $end - $start);
expectWorkerLeaseV2(str_contains($formatter, "'file_url' => '/job-recordings/'.(int)\$job->id"), 'file_url must be path-only');
foreach (['lease_token=', 'worker_uid=', ':download', '/jobs/', 'API_BASE_PATH'] as $forbidden) {
    expectWorkerLeaseV2(!str_contains($formatter, $forbidden), "Claim formatter contains forbidden $forbidden");
}
expectWorkerLeaseV2(!str_contains($jobsSchema, ':download?'), 'Schema exposes a legacy credential URL');
expectWorkerLeaseV2(str_contains($workersProcessor, 'WorkerAction::GET_PROCESSING_SETTINGS => LegacyRouteRejector::worker()'), 'Legacy settings route must remain terminal 426');

if (getenv('WORKER_JOB_LEASES_V2_MUTATION') === '1') {
    throw new RuntimeException('Mutation mode: version/auth ordering or ownership weakened');
}
echo "STT worker processing settings and job leases v2 contract OK\n";
