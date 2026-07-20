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
expectWorkerDownloadUi(
    str_contains($view, 'ui left labeled icon blue disabled button')
        && str_contains($view, 'disabled="disabled"')
        && str_contains($view, 'aria-disabled="true"'),
    'Unpublished worker download action must be a disabled blue labeled button'
);

$requiredKeys = [
    'module_local_speech_to_text_WorkerDownloadTitle',
    'module_local_speech_to_text_WorkerDownloadDescription',
    'module_local_speech_to_text_DownloadWorker',
    'module_local_speech_to_text_WorkerDownloadPending',
];
foreach (array_merge($requiredKeys, ['module_local_speech_to_text_Source']) as $key) {
    expectWorkerDownloadUi(str_contains($view, $key), "Worker download view is missing {$key}");
}
expectWorkerDownloadUi(
    str_contains($view, 'macOS')
        && str_contains($view, 'Apple Silicon')
        && str_contains($view, 'files.miko.ru'),
    'Worker download metadata is incomplete'
);
expectWorkerDownloadUi(
    str_contains($css, '.stt-worker-download-action')
        && str_contains($css, '.stt-worker-download-pending')
        && str_contains($css, '@media only screen and (max-width: 767px)'),
    'Worker download responsive module styles are missing'
);

$russian = include $root . '/Messages/ru.php';
$english = include $root . '/Messages/en.php';
$expectedRussian = [
    'module_local_speech_to_text_WorkerDownloadTitle' => 'Программа обработчика',
    'module_local_speech_to_text_WorkerDownloadDescription' => 'Установите macOS STT Worker для локального распознавания записей.',
    'module_local_speech_to_text_DownloadWorker' => 'Скачать для macOS',
    'module_local_speech_to_text_WorkerDownloadPending' => 'Ссылка появится после публикации',
];
$expectedEnglish = [
    'module_local_speech_to_text_WorkerDownloadTitle' => 'Worker application',
    'module_local_speech_to_text_WorkerDownloadDescription' => 'Install macOS STT Worker for local recording transcription.',
    'module_local_speech_to_text_DownloadWorker' => 'Download for macOS',
    'module_local_speech_to_text_WorkerDownloadPending' => 'The link will appear after publication',
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
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    $messageKeys = array_keys($messages);
    sort($messageKeys);
    expectWorkerDownloadUi($messageKeys === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    foreach ($requiredKeys as $key) {
        expectWorkerDownloadUi(
            isset($messages[$key]) && trim((string)$messages[$key]) !== '',
            basename($messageFile) . " is missing {$key}"
        );
    }
}

echo "Worker download UI contract OK\n";
