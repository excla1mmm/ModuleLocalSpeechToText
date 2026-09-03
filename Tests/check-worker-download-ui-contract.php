<?php

declare(strict_types=1);

function expectWorkerDownloadUi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');
$css = (string)file_get_contents($root . '/public/assets/css/module-local-speech-to-text.css');
$controller = (string)file_get_contents($root . '/App/Controllers/ModuleLocalSpeechToTextController.php');

$downloadPosition = strpos($view, 'stt-worker-download');
$apiKeyPosition = strpos($view, 'stt-worker-api-key');
expectWorkerDownloadUi($downloadPosition !== false, 'Worker download segment is missing');
expectWorkerDownloadUi($apiKeyPosition !== false, 'Worker API-key segment is missing');
expectWorkerDownloadUi(
    $downloadPosition < $apiKeyPosition,
    'Worker download segment must appear before API-key management'
);
expectWorkerDownloadUi(
    str_contains($view, 'ui segment stt-worker-download')
        && str_contains($view, 'ui middle aligned stackable grid')
        && str_contains($view, 'download icon'),
    'Worker download segment must reuse Semantic UI module patterns'
);

// With a valid license key the button posts to the module download action.
expectWorkerDownloadUi(
    str_contains($view, '{% if workerDownloadConfigured %}')
        && str_contains($view, 'module-local-speech-to-text/module-local-speech-to-text/downloadWorker')
        && str_contains($view, 'type="submit"'),
    'Licensed worker download must be a POST form targeting downloadWorker'
);
expectWorkerDownloadUi(
    str_contains($view, '{% else %}')
        && str_contains($view, 'disabled="disabled"')
        && str_contains($view, 'aria-disabled="true"'),
    'Unlicensed worker download must stay a disabled button'
);
expectWorkerDownloadUi(
    str_contains($view, 'stt-worker-download-hint')
        && str_contains($view, 'module_local_speech_to_text_WorkerDownloadNoLicense'),
    'Unlicensed worker download must explain the missing license key'
);

$requiredKeys = [
    'module_local_speech_to_text_WorkerDownloadTitle',
    'module_local_speech_to_text_WorkerDownloadDescription',
    'module_local_speech_to_text_DownloadWorker',
    'module_local_speech_to_text_WorkerDownloadNoLicense',
];
foreach (array_merge($requiredKeys, ['module_local_speech_to_text_Source']) as $key) {
    expectWorkerDownloadUi(str_contains($view, $key), "Worker download view is missing {$key}");
}
expectWorkerDownloadUi(
    !str_contains($view, 'module_local_speech_to_text_WorkerDownloadPending'),
    'Worker download view must not use the retired WorkerDownloadPending copy'
);
expectWorkerDownloadUi(
    str_contains($view, 'macOS')
        && str_contains($view, 'Apple Silicon')
        && str_contains($view, 'lm.miko.ru')
        && !str_contains($view, 'files.miko.ru'),
    'Worker download metadata must point at the licensing server'
);
expectWorkerDownloadUi(
    str_contains($css, '.stt-worker-download-action')
        && str_contains($css, '.stt-worker-download-hint')
        && !str_contains($css, '.stt-worker-download-pending')
        && str_contains($css, '@media only screen and (max-width: 767px)'),
    'Worker download responsive module styles are missing'
);

expectWorkerDownloadUi(
    str_contains($controller, 'downloadWorkerAction')
        && str_contains($controller, 'workerDownloadConfigured')
        && str_contains($controller, 'WorkerDownloadAvailability::AVAILABLE'),
    'Controller must expose the download action and the configured view state'
);

$russian = include $root . '/Messages/ru.php';
$english = include $root . '/Messages/en.php';
$expectedRussian = [
    'module_local_speech_to_text_WorkerDownloadTitle' => 'Программа обработчика',
    'module_local_speech_to_text_WorkerDownloadDescription' => 'Установите macOS STT Worker для локального распознавания записей.',
    'module_local_speech_to_text_DownloadWorker' => 'Скачать для macOS',
    'module_local_speech_to_text_WorkerDownloadNoLicense' => 'Для загрузки обработчика укажите действующий лицензионный ключ MikoPBX на вкладке лицензирования.',
];
$expectedEnglish = [
    'module_local_speech_to_text_WorkerDownloadTitle' => 'Worker application',
    'module_local_speech_to_text_WorkerDownloadDescription' => 'Install macOS STT Worker for local recording transcription.',
    'module_local_speech_to_text_DownloadWorker' => 'Download for macOS',
    'module_local_speech_to_text_WorkerDownloadNoLicense' => 'A valid MikoPBX license key is required. Set it on the licensing tab.',
];
foreach ($expectedRussian as $key => $value) {
    expectWorkerDownloadUi(($russian[$key] ?? null) === $value, "Russian worker download translation mismatch: {$key}");
}
foreach ($expectedEnglish as $key => $value) {
    expectWorkerDownloadUi(($english[$key] ?? null) === $value, "English worker download translation mismatch: {$key}");
}

$sourceKeys = array_keys($russian);
sort($sourceKeys);
$messageFiles = glob($root . '/Messages/*.php') ?: [];
expectWorkerDownloadUi(count($messageFiles) === 29, 'Expected all 29 module translation catalogs');
$flashKeys = [
    'module_local_speech_to_text_WorkerDownloadUnavailable',
    'module_local_speech_to_text_WorkerDownloadServerError',
];
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    $messageKeys = array_keys($messages);
    sort($messageKeys);
    expectWorkerDownloadUi($messageKeys === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    foreach (array_merge($requiredKeys, $flashKeys) as $key) {
        expectWorkerDownloadUi(
            isset($messages[$key]) && trim((string)$messages[$key]) !== '',
            basename($messageFile) . " is missing {$key}"
        );
    }
}

echo "Worker download UI contract OK\n";
