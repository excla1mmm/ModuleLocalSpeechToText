<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
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
foreach ([$recording, $result] as $source) {
    if (strpos($source, 'WorkerApiContract::requireVersion($request)') === false
        || strpos($source, 'WorkerBindingDecision::authorize') === false
        || strpos($source, 'JobLeaseOwnership::preflight') === false
        || strpos($source, 'finally') === false) {
        throw new RuntimeException('Artifact processor ordering/ownership/finally contract is incomplete');
    }
}
if (strpos($failure, 'ResultProcessor::dispatch($request, true)') === false) {
    throw new RuntimeException('Failure resource must use the shared guarded PUT dispatch');
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
