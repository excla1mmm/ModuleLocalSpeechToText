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
$bindingServicePath = $root . '/Lib/Services/WorkerBindingDecision.php';
$ownershipServicePath = $root . '/Lib/Services/JobLeaseOwnership.php';
$jobIdServicePath = $root . '/Lib/Services/CanonicalJobId.php';
expectWorkerLeaseV2(is_file($bindingServicePath), 'Shared exclusive worker binding decision is missing');
expectWorkerLeaseV2(is_file($ownershipServicePath), 'Read-only job lease ownership preflight is missing');
expectWorkerLeaseV2(is_file($jobIdServicePath), 'Canonical job ID parser is missing');
$bindingService = (string)file_get_contents($bindingServicePath);
$ownershipService = (string)file_get_contents($ownershipServicePath);

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
expectWorkerLeaseV2(str_contains($leaseProcessor, '$bodyWorkerUid !== $workerUidHeader') && str_contains($leaseProcessor, 'CanonicalJobId::matchesRoute('), 'Lease ownership mismatch preflight is missing');
expectWorkerLeaseV2(str_contains($leaseProcessor, "['_idFromRequestBody']"), 'Real Core route/body ID metadata must be compared');
expectWorkerLeaseV2(str_contains($leaseProcessor, 'CanonicalJobId::matchesRoute('), 'Core body ID must use exact canonical validation');
expectWorkerLeaseV2(substr_count($settingsProcessor . $leaseProcessor, 'WorkerBindingDecision::authorize(') === 2, 'Both Task 5 processors must share exclusive binding authorization');
expectWorkerLeaseV2(str_contains($bindingService, 'worker_api_key_already_bound') && str_contains($bindingService, '409'), 'Ambiguous bindings must return 409');
expectWorkerLeaseV2(str_contains($leaseProcessor, 'JobLeaseOwnership::preflight('), 'PATCH/DELETE must perform read-only ownership preflight');
expectWorkerLeaseV2(strpos($leaseProcessor, 'JobLeaseOwnership::preflight(') < strpos($leaseProcessor, 'acquireWriteLease('), 'Ownership preflight must precede lifecycle acquisition');
expectWorkerLeaseV2(str_contains($ownershipService, 'hash_equals(') && str_contains($ownershipService, 'lease_expires_at'), 'Ownership preflight must check exact token and freshness');
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

$heartbeatStart = strpos($main, 'public static function heartbeatJob');
$releaseStart = strpos($main, 'public static function releaseJob');
$formatStart = strpos($main, 'private static function formatJob');
$heartbeat = substr($main, $heartbeatStart, $releaseStart - $heartbeatStart);
$release = substr($main, $releaseStart, $formatStart - $releaseStart);
foreach ([$heartbeat, $release] as $operation) {
    expectWorkerLeaseV2(strpos($operation, 'validateAssignedJob(') < strpos($operation, 'bestEffortWorkerTouch('), 'Denied ownership must not mutate worker liveness');
}
$getNextStart = strpos($main, 'public static function getNextJob');
$settingsStart = strpos($main, 'public static function getWorkerProcessingSettings');
$getNext = substr($main, $getNextStart, $settingsStart - $getNextStart);
expectWorkerLeaseV2(strpos($getNext, 'prepareJobSnapshot()') < strpos($getNext, 'claimNextForWorker('), 'All fallible claim settings must be snapshotted before CAS');
expectWorkerLeaseV2(str_contains($getNext, 'releaseForWorker(') && str_contains($getNext, 'job_claim_compensated') && str_contains($getNext, 'job_claim_compensation_failed'), 'Defense compensation outcomes must be observable');
expectWorkerLeaseV2(strpos($getNext, 'formatJob(') < strpos($getNext, "'job_claimed'"), 'job_claimed telemetry must follow payload construction');

if (getenv('WORKER_JOB_LEASES_V2_MUTATION') === '1') {
    $mutated = str_replace('JobLeaseOwnership::preflight(', 'JobLeaseOwnership::bypass(', $leaseProcessor);
    expectWorkerLeaseV2(str_contains($mutated, 'JobLeaseOwnership::preflight('), 'Mutation detected: ownership preflight was bypassed');
}
echo "STT worker processing settings and job leases v2 contract OK\n";
