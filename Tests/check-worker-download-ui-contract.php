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
$indexViewService = (string)file_get_contents($root . '/Lib/Services/IndexViewService.php');
$service = (string)file_get_contents($root . '/Lib/Services/WorkerDownloadService.php');

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

// The button always posts to the module download action; the licensing server
// decides on click whether the release is available for this installation.
expectWorkerDownloadUi(
    !str_contains($view, 'workerDownloadConfigured')
        && str_contains($view, 'module-local-speech-to-text/module-local-speech-to-text/downloadWorker')
        && str_contains($view, 'type="submit"')
        && str_contains($view, 'ui left labeled icon blue button'),
    'Worker download must be an always-active POST form targeting downloadWorker'
);
expectWorkerDownloadUi(
    !str_contains($view, 'module_local_speech_to_text_WorkerDownloadNoLicense')
        && !str_contains($view, 'module_local_speech_to_text_WorkerDownloadPending')
        && !str_contains($view, 'stt-worker-download-hint'),
    'Worker download view must not use retired placeholder copy'
);

$requiredKeys = [
    'module_local_speech_to_text_WorkerDownloadTitle',
    'module_local_speech_to_text_WorkerDownloadDescription',
    'module_local_speech_to_text_DownloadWorker',
];
foreach (array_merge($requiredKeys, ['module_local_speech_to_text_Source']) as $key) {
    expectWorkerDownloadUi(str_contains($view, $key), "Worker download view is missing {$key}");
}
expectWorkerDownloadUi(
    str_contains($view, 'macOS')
        && str_contains($view, 'Apple Silicon')
        && str_contains($view, 'releases.mikopbx.com'),
    'Worker download metadata must point at the release server'
);
expectWorkerDownloadUi(
    str_contains($css, '.stt-worker-download-action')
        && !str_contains($css, '.stt-worker-download-pending')
        && !str_contains($css, '.stt-worker-download-hint')
        && str_contains($css, '@media only screen and (max-width: 767px)'),
    'Worker download responsive module styles are missing'
);

expectWorkerDownloadUi(
    str_contains($controller, 'downloadWorkerAction')
        && str_contains($controller, 'resolveDownload')
        && str_contains($controller, 'WorkerDownloadAvailability::AVAILABLE'),
    'Controller must expose the download action built on resolveDownload'
);
expectWorkerDownloadUi(
    !str_contains($indexViewService, 'workerDownloadConfigured'),
    'The dashboard view must not gate the download button on license state'
);
expectWorkerDownloadUi(
    str_contains($service, '/appcast/downloadLatest/LocalSTTWorkerMac')
        && str_contains($service, "'arch' => 'arm64'")
        && str_contains($service, "'ext' => 'dmg'"),
    'Service must resolve the arm64 DMG through the public release endpoint'
);

$russian = include $root . '/Messages/ru.php';
$english = include $root . '/Messages/en.php';
$expectedRussian = [
    'module_local_speech_to_text_WorkerDownloadTitle' => 'Программа обработчика',
    'module_local_speech_to_text_WorkerDownloadDescription' => 'Установите macOS STT Worker для локального распознавания записей.',
    'module_local_speech_to_text_DownloadWorker' => 'Скачать для macOS',
];
$expectedEnglish = [
    'module_local_speech_to_text_WorkerDownloadTitle' => 'Worker application',
    'module_local_speech_to_text_WorkerDownloadDescription' => 'Install macOS STT Worker for local recording transcription.',
    'module_local_speech_to_text_DownloadWorker' => 'Download for macOS',
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
    expectWorkerDownloadUi(
        !array_key_exists('module_local_speech_to_text_WorkerDownloadNoLicense', $messages),
        basename($messageFile) . ' must not carry the retired WorkerDownloadNoLicense key'
    );
}

// Windows: a download button that stays disabled ("coming soon") until the one URL constant is filled.
$windowsDownload = (string)file_get_contents($root . '/Lib/Services/WindowsWorkerDownload.php');
expectWorkerDownloadUi(
    preg_match("/public const string URL = '[^']*';/", $windowsDownload) === 1
        && str_contains($indexViewService, '$view->windowsWorkerDownloadUrl = WindowsWorkerDownload::url();'),
    'The Windows worker download URL must live in WindowsWorkerDownload::URL'
);
$windowsPanelStart = strpos($view, '<div class="stt-worker-platform-panel" data-platform="windows"');
expectWorkerDownloadUi($windowsPanelStart !== false, 'The Workers tab must have a Windows panel');
$windowsPanel = substr($view, (int)$windowsPanelStart, (int)strpos($view, 'stt-worker-api-key', (int)$windowsPanelStart) - (int)$windowsPanelStart);
expectWorkerDownloadUi(
    str_contains($windowsPanel, "{% if windowsWorkerDownloadUrl !== '' %}")
        && str_contains($windowsPanel, 'href="{{ windowsWorkerDownloadUrl|e }}"')
        && str_contains($windowsPanel, 'disabled="disabled"')
        && str_contains($windowsPanel, 'module_local_speech_to_text_WorkerWindowsComingSoon')
        && substr_count($windowsPanel, 'module_local_speech_to_text_DownloadWindowsWorker') === 2,
    'The Windows download button must be disabled with a coming-soon note while the URL is empty'
);
foreach (['setup', 'install', 'status'] as $command) {
    expectWorkerDownloadUi(
        str_contains($windowsPanel, "<code>LocalSTTWorker.exe {$command}</code>"),
        "The Windows panel must explain LocalSTTWorker.exe {$command}"
    );
}
expectWorkerDownloadUi(
    str_contains($windowsPanel, "{% if generatedApiKey !== '' %}")
        && str_contains($windowsPanel, 'module_local_speech_to_text_WorkerWindowsKeyCreated'),
    'The Windows panel must point at a newly created key'
);
$windowsKeys = [
    'module_local_speech_to_text_PlatformWindows',
    'module_local_speech_to_text_DownloadWindowsWorker',
    'module_local_speech_to_text_WorkerWindowsComingSoon',
    'module_local_speech_to_text_WorkerWindowsDescription',
    'module_local_speech_to_text_WorkerWindowsRequirements',
    'module_local_speech_to_text_WorkerWindowsKeyHint',
    'module_local_speech_to_text_WorkerWindowsKeyCreated',
    'module_local_speech_to_text_WorkerWindowsRunAsAdministrator',
    'module_local_speech_to_text_WorkerWindowsStepSetup',
    'module_local_speech_to_text_WorkerWindowsStepInstall',
    'module_local_speech_to_text_WorkerWindowsStepStatus',
];
expectWorkerDownloadUi(($russian['module_local_speech_to_text_DownloadWindowsWorker'] ?? null) === 'Скачать обработчик для Windows', 'Russian Windows download label mismatch');
expectWorkerDownloadUi(($english['module_local_speech_to_text_DownloadWindowsWorker'] ?? null) === 'Download the Windows worker', 'English Windows download label mismatch');
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    foreach ($windowsKeys as $key) {
        expectWorkerDownloadUi(
            isset($messages[$key]) && trim((string)$messages[$key]) !== '',
            basename($messageFile) . " is missing {$key}"
        );
    }
    // The Docker worker is labelled just "Docker" everywhere; the old combined labels are retired.
    expectWorkerDownloadUi(
        ($messages['module_local_speech_to_text_PlatformLinux'] ?? null) === 'Docker'
            && !array_key_exists('module_local_speech_to_text_WorkerDownloadPlatformDocker', $messages),
        basename($messageFile) . ' must label the Docker platform "Docker"'
    );
}
expectWorkerDownloadUi(
    !str_contains($view, 'WorkerDownloadPlatformDocker'),
    'The Workers tab must use the shared Docker platform label'
);

echo "Worker download UI contract OK\n";
