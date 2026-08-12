<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'Lib/Services/JobSubmissionReceiptService.php',
    'Lib/Services/JobSubmissionReceiptMigration.php',
    'Models/STTJobSubmissionReceipt.php',
    'Lib/RestAPI/JobRecordings/Controller.php',
    'Lib/RestAPI/JobRecordings/Processor.php',
    'Lib/RestAPI/JobResults/Controller.php',
    'Lib/RestAPI/JobResults/Processor.php',
    'Lib/RestAPI/JobResults/DataStructure.php',
    'Lib/RestAPI/JobFailures/Controller.php',
    'Lib/RestAPI/JobFailures/Processor.php',
    'Lib/RestAPI/JobFailures/DataStructure.php',
];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException("Task 6 resource is missing: {$file}");
    }
}

$recording = file_get_contents($root . '/Lib/RestAPI/JobRecordings/Processor.php');
$result = file_get_contents($root . '/Lib/RestAPI/JobResults/Processor.php');
$failure = file_get_contents($root . '/Lib/RestAPI/JobFailures/Processor.php');
$jobModel = file_get_contents($root . '/Models/STTJob.php');
$setup = file_get_contents($root . '/Setup/PbxExtensionSetup.php');
foreach ([$recording, $result] as $source) {
    if (strpos($source, 'WorkerApiContract::requireVersion($request)') === false
        || strpos($source, 'WorkerBindingDecision::authorize') === false
        || strpos($source, 'JobLeaseOwnership::preflight') === false
        || strpos($source, 'finally') === false) {
        throw new RuntimeException('Artifact processor ordering/ownership/finally contract is incomplete');
    }
}
if (!str_contains($setup, "new ModuleSchemaMigration(\n                14,") || !str_contains($setup, 'stt-schema-014-job-submission-receipts')) {
    throw new RuntimeException('Production migration 14 receipt registration is missing');
}
$failedMethod = strstr($jobModel, 'public function markFailedForWorker');
$failedMethod = strstr((string)$failedMethod, 'public function markTerminalErrorForWorker', true);
if (str_contains((string)$failedMethod, 'findFirst')) {
    throw new RuntimeException('Failure CAS must not reload after a successful update');
}
if (strpos($failure, 'ResultProcessor::dispatch($request, true)') === false) {
    throw new RuntimeException('Failure resource must use the shared guarded PUT dispatch');
}
if (getenv('WORKER_JOB_ARTIFACTS_V2_MUTATION') === '1') {
    $mutated = str_replace('return self::decorate($replay, $failure);', 'return $replay;', $result);
    if (str_contains($mutated, 'return self::decorate($replay, $failure);')) throw new RuntimeException('Mutation fixture did not alter replay decoration');
    throw new RuntimeException('Mutation detected: terminal replay decoration was bypassed');
}
if (strpos($recording, "['_worker_uid_header']") === false
    || strpos($recording, "['_lease_token_header']") === false
    || preg_match('/[?&](?:lease_token|worker_uid)=/', $recording)) {
    throw new RuntimeException('Recording ownership must be header-only and secret-free');
}
foreach (['DownloadAction.php', 'GetNextAction.php', 'SubmitResultAction.php', 'SubmitErrorAction.php'] as $legacy) {
    if (is_file($root . '/Lib/RestAPI/Jobs/Actions/' . $legacy)) {
        throw new RuntimeException("Executable legacy action remains: {$legacy}");
    }
}

echo "STT worker job artifacts v2 contract OK\n";
