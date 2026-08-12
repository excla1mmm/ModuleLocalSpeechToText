<?php

declare(strict_types=1);

/** @param mixed $actual */
function expectNoWorkingLegacyRoute(bool $condition, string $message, mixed $actual = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($actual === null ? '' : "\nActual: " . var_export($actual, true)));
    }
}

function legacyRouteMethodBody(string $source, string $method): string
{
    $start = strpos($source, "function {$method}(");
    expectNoWorkingLegacyRoute($start !== false, "Legacy processor must declare {$method}()");
    $open = strpos($source, '{', $start);
    expectNoWorkingLegacyRoute($open !== false, "Legacy processor {$method}() has no body");
    $depth = 0;
    for ($offset = $open, $length = strlen($source); $offset < $length; $offset++) {
        if ($source[$offset] === '{') {
            $depth++;
        } elseif ($source[$offset] === '}' && --$depth === 0) {
            return substr($source, $open + 1, $offset - $open - 1);
        }
    }

    throw new RuntimeException("Legacy processor {$method}() body is not balanced");
}

function legacyRouteMatchArm(string $body, string $case): string
{
    $pattern = '/' . preg_quote($case, '/') . '\\s*=>\\s*(LegacyRouteRejector::worker\\(\\))/';
    expectNoWorkingLegacyRoute(
        preg_match_all($pattern, $body, $matches) === 1,
        "{$case} must resolve exactly once to LegacyRouteRejector::worker()",
    );

    return $matches[1][0];
}

$root = dirname(__DIR__);
$routes = [
    'POST /workers:register' => ['file' => 'Lib/RestAPI/Workers/Processor.php', 'case' => 'WorkerAction::REGISTER'],
    'GET /workers:getProcessingSettings' => ['file' => 'Lib/RestAPI/Workers/Processor.php', 'case' => 'WorkerAction::GET_PROCESSING_SETTINGS'],
    'GET /jobs:getNext' => ['file' => 'Lib/RestAPI/Jobs/Processor.php', 'case' => 'JobAction::GET_NEXT'],
    'GET /jobs/{id}:download' => ['file' => 'Lib/RestAPI/Jobs/Processor.php', 'case' => 'JobAction::DOWNLOAD'],
    'POST /jobs/{id}:submitResult' => ['file' => 'Lib/RestAPI/Jobs/Processor.php', 'case' => 'JobAction::SUBMIT_RESULT'],
    'POST /jobs/{id}:submitError' => ['file' => 'Lib/RestAPI/Jobs/Processor.php', 'case' => 'JobAction::SUBMIT_ERROR'],
    'POST /jobs/{id}:heartbeat' => ['file' => 'Lib/RestAPI/Jobs/Processor.php', 'case' => 'JobAction::HEARTBEAT'],
    'POST /jobs/{id}:release' => ['file' => 'Lib/RestAPI/Jobs/Processor.php', 'case' => 'JobAction::RELEASE'],
];

$processorBodies = [];
foreach ($routes as $route => $definition) {
    $path = $root . '/' . $definition['file'];
    if (!isset($processorBodies[$path])) {
        $source = file_get_contents($path);
        expectNoWorkingLegacyRoute($source !== false, "Cannot read legacy processor for {$route}");
        $processorBodies[$path] = legacyRouteMethodBody($source, 'callBack');
    }

    $arm = legacyRouteMatchArm($processorBodies[$path], $definition['case']);
    expectNoWorkingLegacyRoute($arm === 'LegacyRouteRejector::worker()', "{$route} must be a direct legacy rejection");
}

$oldActionFiles = [
    'Lib/RestAPI/Jobs/Actions/GetNextAction.php',
    'Lib/RestAPI/Jobs/Actions/DownloadAction.php',
    'Lib/RestAPI/Jobs/Actions/SubmitResultAction.php',
    'Lib/RestAPI/Jobs/Actions/SubmitErrorAction.php',
    'Lib/RestAPI/Workers/Actions/RegisterAction.php',
    'Lib/RestAPI/Workers/Actions/GetProcessingSettingsAction.php',
];
foreach ($oldActionFiles as $file) {
    expectNoWorkingLegacyRoute(!is_file($root . '/' . $file), "Deleted v1 action class remains: {$file}");
}

$rejector = (string)file_get_contents($root . '/Lib/RestAPI/Common/LegacyRouteRejector.php');
$contract = (string)file_get_contents($root . '/Lib/Services/WorkerApiContract.php');
expectNoWorkingLegacyRoute(
    preg_match('/function worker\(\): PBXApiResult\s*\{\s*return WorkerApiContract::upgradeRequired\(\);\s*\}/s', $rejector) === 1,
    'LegacyRouteRejector::worker() must only delegate to the worker upgrade result',
);
expectNoWorkingLegacyRoute(
    str_contains($contract, '$result->httpCode = 426;')
        && str_contains($contract, "'error_code' => 'worker_upgrade_required'")
        && str_contains($contract, "'required_worker_api_version' => self::API_VERSION"),
    'Legacy worker rejection must retain the HTTP 426 worker_upgrade_required API-version body',
);
expectNoWorkingLegacyRoute(
    !str_contains($rejector, 'Upgrade') && !str_contains($contract, 'responseHeaders'),
    'Legacy worker rejection must not add an Upgrade response header',
);

echo "No working legacy worker routes contract OK\n";
