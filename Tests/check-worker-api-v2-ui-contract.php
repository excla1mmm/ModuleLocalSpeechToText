<?php

declare(strict_types=1);

function expectWorkerApiV2Ui(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$indexViewService = (string)file_get_contents($root . '/Lib/Services/IndexViewService.php');
$workerViewService = (string)file_get_contents($root . '/Lib/Services/WorkerViewService.php');
$view = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');

expectWorkerApiV2Ui($indexViewService !== '', 'IndexViewService must exist');
expectWorkerApiV2Ui($workerViewService !== '', 'WorkerViewService must exist');
expectWorkerApiV2Ui(
    str_contains($indexViewService, '(new WorkerViewService())->prepare($view);'),
    'Dashboard preparation must use WorkerViewService'
);
expectWorkerApiV2Ui(
    str_contains($workerViewService, '$view->workerApiVersion = WorkerApiContract::API_VERSION;')
        && str_contains($workerViewService, '(int)$worker->api_version === WorkerApiContract::API_VERSION'),
    'Compatibility must require the exact WorkerApiContract API version'
);

foreach ([
    'module_local_speech_to_text_WorkerUid',
    'module_local_speech_to_text_AppVersion',
    'module_local_speech_to_text_WorkerUpdateHelp',
] as $key) {
    expectWorkerApiV2Ui(str_contains($view, "t._('{$key}')"), "Workers view is missing {$key}");
}
expectWorkerApiV2Ui(
    !str_contains($view, 'module_local_speech_to_text_ApiVersion'),
    'Workers view must not render the retired API version column header'
);
foreach ([
    'module_local_speech_to_text_Compatible',
    'module_local_speech_to_text_Incompatible',
    'module_local_speech_to_text_WorkerUpdateRequired',
] as $retiredKey) {
    expectWorkerApiV2Ui(
        !str_contains($view, "t._('{$retiredKey}')"),
        "Workers view must not render the retired compatibility column key {$retiredKey}"
    );
}

expectWorkerApiV2Ui(str_contains($view, 'workerCompatibility[worker.id]'), 'Workers view must render controller-calculated compatibility');
expectWorkerApiV2Ui(
    str_contains($view, "{% if workerCompatibility[worker.id] and worker.status === 'online' %}"),
    'Workers view must render online status only for compatible online workers'
);
expectWorkerApiV2Ui(
    str_contains($view, 'colspan="7"'),
    'Workers empty row must span all seven worker columns'
);
expectWorkerApiV2Ui(str_contains($view, 'worker.worker_uid|e'), 'Workers view must display an escaped worker UID');
expectWorkerApiV2Ui(str_contains($view, 'worker.app_version|e'), 'Workers view must display an escaped app version');
expectWorkerApiV2Ui(
    !str_contains($view, 'worker.api_version'),
    'Workers view must not display the API version column'
);
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
    'module_local_speech_to_text_ApiVersion' => 'Версия API',
    'module_local_speech_to_text_AppVersion' => 'Версия приложения',
] as $key => $value) {
    expectWorkerApiV2Ui(($sourceMessages[$key] ?? null) === $value, "Russian catalog must contain {$key}");
}
foreach ([
    'module_local_speech_to_text_Compatible',
    'module_local_speech_to_text_Incompatible',
    'module_local_speech_to_text_WorkerUpdateRequired',
] as $retiredKey) {
    expectWorkerApiV2Ui(!isset($sourceMessages[$retiredKey]), "Russian catalog must drop the retired {$retiredKey}");
}
foreach (glob($root . '/Messages/*.php') ?: [] as $messageFile) {
    $messages = include $messageFile;
    expectWorkerApiV2Ui(array_keys($messages) === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    foreach (['module_local_speech_to_text_ApiVersion', 'module_local_speech_to_text_AppVersion'] as $key) {
        expectWorkerApiV2Ui(isset($messages[$key]) && trim((string)$messages[$key]) !== '', basename($messageFile) . " is missing {$key}");
    }
}

echo "Worker API v2 UI contract OK\n";
