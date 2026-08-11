<?php

declare(strict_types=1);

/** @param mixed $actual */
function expectWorkerApiV2Version(bool $condition, string $message, mixed $actual = null): void
{
    if (!$condition) {
        $suffix = $actual === null ? '' : "\nActual: " . var_export($actual, true);
        throw new RuntimeException($message . $suffix);
    }
}

/** @return list<string> */
function phpFilesWorkerApiV2Version(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

$root = dirname(__DIR__);
$phpFiles = phpFilesWorkerApiV2Version($root . '/Lib');
$sources = [];
foreach ($phpFiles as $file) {
    $contents = file_get_contents($file);
    expectWorkerApiV2Version($contents !== false, "Unable to read {$file}");
    $sources[$file] = $contents;
}
$allPhp = implode("\n", $sources);

$required = [
    'WorkerApiContract::API_VERSION',
    'X-MikoPBX-Worker-API-Version',
    'X-MikoPBX-Worker-UID',
    'X-MikoPBX-Job-Lease-Token',
    'worker_upgrade_required',
];
foreach ($required as $symbol) {
    expectWorkerApiV2Version(str_contains($allPhp, $symbol), "Required worker API v2 symbol is missing: {$symbol}");
}

$gatedProcessors = [];
foreach ($sources as $file => $source) {
    if (!str_contains($file, '/Lib/RestAPI/') || !str_ends_with($file, '/Processor.php')) {
        continue;
    }

    $gatePosition = strpos($source, 'WorkerApiContract::requireVersion');
    if ($gatePosition === false) {
        continue;
    }
    $gatedProcessors[] = $file;
    foreach (['LocalSpeechToTextMain::', 'STTJob::', 'STTWorker::'] as $businessAction) {
        $actionPosition = strpos($source, $businessAction);
        expectWorkerApiV2Version(
            $actionPosition === false || $gatePosition < $actionPosition,
            basename(dirname($file)) . "/Processor.php must require worker API v2 before {$businessAction}",
        );
    }
}
expectWorkerApiV2Version($gatedProcessors !== [], 'At least one v2 REST processor must require the worker API version before business actions');

$fileUrlSources = [];
foreach ($sources as $file => $source) {
    $lines = preg_split('/\R/', $source) ?: [];
    foreach ($lines as $lineNumber => $line) {
        if (!str_contains($line, '/job-recordings/')) {
            continue;
        }
        $fileUrlSources[] = "{$file}:" . ($lineNumber + 1);
        expectWorkerApiV2Version(
            !preg_match('/(?:lease_token=|worker_uid=|:download|\/jobs\/)/', $line),
            "v2 file_url source must be credential-free and use /job-recordings/{job_id}: {$file}:" . ($lineNumber + 1),
        );
    }
}
expectWorkerApiV2Version($fileUrlSources !== [], 'v2 worker API must publish a /job-recordings/{job_id} file_url source');

$swiftExamples = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'swift') {
        $swiftExamples[] = $file->getPathname();
    }
}
foreach ($swiftExamples as $swiftExample) {
    $source = file_get_contents($swiftExample);
    expectWorkerApiV2Version($source !== false, "Unable to read {$swiftExample}");
    if (!str_contains($source, 'file_url')) {
        continue;
    }
    expectWorkerApiV2Version(
        !preg_match('/(?:lease_token=|worker_uid=|:download|\/jobs\/)/', $source),
        "Swift-facing OpenAPI example must keep v2 file_url credential-free: {$swiftExample}",
    );
}

echo "Worker API v2 version and sensitive-data contract OK\n";
