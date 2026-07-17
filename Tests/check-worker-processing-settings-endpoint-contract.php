<?php

declare(strict_types=1);

function expectProcessingEndpoint(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/Lib/RestAPI/Workers/Controller.php');
$processor = (string)file_get_contents($root . '/Lib/RestAPI/Workers/Processor.php');
$main = (string)file_get_contents($root . '/Lib/LocalSpeechToTextMain.php');
$actionPath = $root . '/Lib/RestAPI/Workers/Actions/GetProcessingSettingsAction.php';
$schemaPath = $root . '/Lib/RestAPI/Workers/ProcessingSettingsResponseDataStructure.php';

expectProcessingEndpoint(str_contains($controller, "'GET' => ['getProcessingSettings']"), 'Workers GET route is missing');
expectProcessingEndpoint(str_contains($processor, "case GET_PROCESSING_SETTINGS = 'getProcessingSettings';"), 'Worker action is missing');
expectProcessingEndpoint(is_file($actionPath), 'Processing-settings action is missing');
expectProcessingEndpoint(is_file($schemaPath), 'Processing-settings response schema is missing');
expectProcessingEndpoint(str_contains($main, 'public static function getWorkerProcessingSettings(array $data): PBXApiResult'), 'Worker settings handler is missing');
expectProcessingEndpoint(str_contains($main, 'validateWorkerApiKeyBinding($worker, $data)'), 'Worker binding check is missing');
expectProcessingEndpoint(str_contains($main, 'fetchForWorker($worker)'), 'Atomic profile fetch is missing');
expectProcessingEndpoint(!str_contains($main, 'settings_revision'), 'Settings revisions are forbidden');
expectProcessingEndpoint(!str_contains($main, 'applied_settings_revision'), 'Applied settings revisions are forbidden');

echo "Worker processing settings endpoint contract OK\n";
