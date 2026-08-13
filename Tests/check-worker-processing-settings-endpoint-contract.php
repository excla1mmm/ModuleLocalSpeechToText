<?php

declare(strict_types=1);

function expectProcessingEndpoint(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/Lib/RestAPI/WorkerProcessingSettings/Controller.php');
$processor = (string)file_get_contents($root . '/Lib/RestAPI/WorkerProcessingSettings/Processor.php');
$workersController = (string)file_get_contents($root . '/Lib/RestAPI/Workers/Controller.php');
$workersProcessor = (string)file_get_contents($root . '/Lib/RestAPI/Workers/Processor.php');
$main = (string)file_get_contents($root . '/Lib/LocalSpeechToTextMain.php');
$schemaPath = $root . '/Lib/RestAPI/WorkerProcessingSettings/DataStructure.php';

expectProcessingEndpoint(str_contains($controller, "'/worker-processing-settings'") && str_contains($controller, "'GET' => ['getList']"), 'Caller-bound worker-processing-settings GET route is missing');
expectProcessingEndpoint(str_contains($controller, 'SecurityType::BEARER_TOKEN'), 'Worker-processing-settings must require bearer authentication');
expectProcessingEndpoint(str_contains($processor, 'WorkerApiContract::requireVersion($request)'), 'Worker-processing-settings must require worker API v2');
expectProcessingEndpoint(str_contains($processor, 'WorkerRequestContext::isRestrictedWorkerKey($data)'), 'Worker-processing-settings must be limited to restricted worker credentials');
expectProcessingEndpoint(str_contains($processor, "unset(\$data['worker_uid'])") && str_contains($processor, 'WorkerBindingDecision::authorize($workerUidHeader'), 'Worker-processing-settings must bind the caller UID header to its API key');
expectProcessingEndpoint(is_file($schemaPath), 'Worker-processing-settings data structure is missing');
expectProcessingEndpoint(str_contains($main, 'public static function getWorkerProcessingSettings(array $data): PBXApiResult'), 'Worker settings handler is missing');
preg_match(
    '/public static function getWorkerProcessingSettings\(array \$data\): PBXApiResult(?<body>.*?)public static function downloadJobFile/s',
    $main,
    $handlerMatch
);
$handlerBody = (string)($handlerMatch['body'] ?? '');
expectProcessingEndpoint(
    str_contains($handlerBody, 'requireBearerWorkerApiKey($data)'),
    'Processing settings must require Bearer worker authentication even from localhost'
);
expectProcessingEndpoint(str_contains($main, 'validateWorkerApiKeyBinding($worker, $data)'), 'Worker binding check is missing');
expectProcessingEndpoint(str_contains($main, 'fetchForWorker($worker)'), 'Atomic profile fetch is missing');
expectProcessingEndpoint(!str_contains($main, 'settings_revision'), 'Settings revisions are forbidden');
expectProcessingEndpoint(!str_contains($main, 'applied_settings_revision'), 'Applied settings revisions are forbidden');
expectProcessingEndpoint(str_contains($workersController, "'GET' => ['getList', 'getRecord', 'getProcessingSettings']"), 'Old Workers processing-settings route must remain a tombstone');
expectProcessingEndpoint(str_contains($workersProcessor, 'WorkerAction::GET_PROCESSING_SETTINGS => LegacyRouteRejector::worker()'), 'Old Workers processing-settings action must be a 426 tombstone');
expectProcessingEndpoint(!is_file($root . '/Lib/RestAPI/Workers/Actions/GetProcessingSettingsAction.php'), 'Old Workers processing-settings action must not return');
expectProcessingEndpoint(!is_file($root . '/Lib/RestAPI/Workers/ProcessingSettingsResponseDataStructure.php'), 'Old Workers processing-settings schema must not return');

echo "Worker processing settings endpoint contract OK\n";
