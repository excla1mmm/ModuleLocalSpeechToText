<?php

declare(strict_types=1);

function expectWorkerApiV2Ui(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/App/Controllers/ModuleLocalSpeechToTextController.php');
$view = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');

expectWorkerApiV2Ui(str_contains($controller, 'use Modules\\ModuleLocalSpeechToText\\Lib\\Services\\WorkerApiContract;'), 'Controller must import WorkerApiContract');
expectWorkerApiV2Ui(
    str_contains($controller, '$this->view->workerApiVersion = WorkerApiContract::API_VERSION;'),
    'Controller must pass the required worker API version from WorkerApiContract'
);
expectWorkerApiV2Ui(
    str_contains($controller, '(int)$worker->api_version === WorkerApiContract::API_VERSION'),
    'Compatibility must require the exact WorkerApiContract API version'
);

foreach ([
    'module_local_speech_to_text_WorkerUid',
    'module_local_speech_to_text_ApiVersion',
    'module_local_speech_to_text_AppVersion',
    'module_local_speech_to_text_Compatible',
    'module_local_speech_to_text_Incompatible',
    'module_local_speech_to_text_WorkerUpdateRequired',
    'module_local_speech_to_text_WorkerUpdateHelp',
] as $key) {
    expectWorkerApiV2Ui(str_contains($view, "t._('{$key}')"), "Workers view is missing {$key}");
}

expectWorkerApiV2Ui(str_contains($view, 'workerCompatibility[worker.id]'), 'Workers view must render controller-calculated compatibility');
expectWorkerApiV2Ui(str_contains($view, 'worker.worker_uid|e'), 'Workers view must display an escaped worker UID');
expectWorkerApiV2Ui(str_contains($view, 'worker.app_version|e'), 'Workers view must display an escaped app version');
expectWorkerApiV2Ui(str_contains($view, 'worker.api_version|e'), 'Workers view must display an escaped API version');
expectWorkerApiV2Ui(str_contains($view, 'worker.last_seen'), 'Workers view must display last seen');
expectWorkerApiV2Ui(
    preg_match('/href="https:\/\/docs\.mikopbx\.com\/extensions\/ModuleLocalSpeechToText\/worker-update"/', $view) === 1,
    'Workers view must expose the fixed safe worker-update help URL'
);
expectWorkerApiV2Ui(
    preg_match('/https:\/\/docs\.mikopbx\.com\/extensions\/ModuleLocalSpeechToText\/worker-update[^"<]*/', $view) === 1,
    'Worker-update help URL must not contain a query or fragment'
);
expectWorkerApiV2Ui(
    preg_match('/(?:href|src|action)="[^"]*[?&](?:api[_-]?key|bearer|worker_uid|lease[_-]?token)=/i', $view) !== 1,
    'Workers view must not place credentials or worker UID in links or form URL queries'
);

$sourceMessages = include $root . '/Messages/ru.php';
$sourceKeys = array_keys($sourceMessages);
foreach ([
    'module_local_speech_to_text_Compatible' => 'Совместим',
    'module_local_speech_to_text_WorkerUpdateRequired' => 'Требуется обновить воркер',
    'module_local_speech_to_text_ApiVersion' => 'Версия API',
    'module_local_speech_to_text_AppVersion' => 'Версия приложения',
] as $key => $value) {
    expectWorkerApiV2Ui(($sourceMessages[$key] ?? null) === $value, "Russian catalog must contain {$key}");
}
foreach (glob($root . '/Messages/*.php') ?: [] as $messageFile) {
    $messages = include $messageFile;
    expectWorkerApiV2Ui(array_keys($messages) === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    foreach (['module_local_speech_to_text_Compatible', 'module_local_speech_to_text_WorkerUpdateRequired', 'module_local_speech_to_text_ApiVersion', 'module_local_speech_to_text_AppVersion'] as $key) {
        expectWorkerApiV2Ui(isset($messages[$key]) && trim((string)$messages[$key]) !== '', basename($messageFile) . " is missing {$key}");
    }
}

echo "Worker API v2 UI contract OK\n";
