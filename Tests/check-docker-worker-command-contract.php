<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\DockerWorkerCommand;

function expectDockerWorkerCommand(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
require_once $root . '/Lib/Services/DockerWorkerCommand.php';

$command = DockerWorkerCommand::build('http://172.16.33.103', 'abc123');
$image = DockerWorkerCommand::IMAGE;

// The Linux worker reads PBX_URL and WORKER_TOKEN (LocalSTTWorkerDocker config.py), not other names.
expectDockerWorkerCommand(str_starts_with($command, "docker pull {$image}\n"), 'The command must pull the image first');
expectDockerWorkerCommand(str_contains($command, '-e PBX_URL=http://172.16.33.103 \\'), 'The command must pass PBX_URL');
expectDockerWorkerCommand(str_contains($command, '-e WORKER_TOKEN=abc123 \\'), 'The command must pass WORKER_TOKEN');
expectDockerWorkerCommand(str_contains($command, '--restart unless-stopped'), 'The worker must restart with the host');
expectDockerWorkerCommand(str_contains($command, ':/models \\'), 'Models must live in a volume');
// The PBX binds a key to one worker UID; the data volume keeps that UID across container re-creation.
expectDockerWorkerCommand(str_contains($command, ':/data \\'), 'Worker identity must live in a volume');
expectDockerWorkerCommand(str_ends_with($command, "\n  {$image}"), 'The run command must end with the image');
// latest is the stable multi-arch CPU release, so a re-run docker pull also updates the worker.
expectDockerWorkerCommand(str_ends_with($image, ':latest'), 'The image must be the latest stable release');
// Administrators pull from their own servers: the image must come from the public Docker Hub, not the internal Nexus.
expectDockerWorkerCommand(str_starts_with($image, 'excla1m/mikopbx-stt-worker:'), 'The image must be the public Docker Hub one');

$view = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');
expectDockerWorkerCommand(
    str_contains($view, 'stt-worker-platform-switch')
        && str_contains($view, 'data-platform="macos"')
        && str_contains($view, 'data-platform="linux"')
        && str_contains($view, 'data-platform="windows"'),
    'The worker download segment must offer every platform'
);
expectDockerWorkerCommand(
    substr_count($view, '{{ dockerWorkerCommand|e }}') === 2,
    'The Docker command must be escaped both in the copy button and in the shown block'
);
expectDockerWorkerCommand(
    !str_contains($view, 'pbxWorkerUrl') && !str_contains($view, 'WorkersOnboardingLinux'),
    'The retired Linux .env onboarding must not come back'
);

// The command is not enough: the administrator must see the new key on its own, with a copy button, on both platforms.
$css = (string)file_get_contents($root . '/public/assets/css/module-local-speech-to-text.css');
expectDockerWorkerCommand(
    !preg_match('/stt-api-key-message\s*\{[^}]*display:\s*none/', $css),
    'The new key message must not be hidden on the Docker platform'
);

$indexViewService = (string)file_get_contents($root . '/Lib/Services/IndexViewService.php');
expectDockerWorkerCommand(
    str_contains($indexViewService, 'DockerWorkerCommand::build(')
        && str_contains($indexViewService, 'module_local_speech_to_text_WorkerDockerKeyPlaceholder'),
    'The view must fill the command with the new key or the placeholder'
);

// The one-time key reaches the redirected page only if the session is saved before the redirect is sent.
$controller = (string)file_get_contents($root . '/App/Controllers/ModuleLocalSpeechToTextController.php');
$generateAction = substr($controller, (int)strpos($controller, 'function generateWorkerApiKeyAction'));
$generateAction = substr($generateAction, 0, (int)strpos($generateAction, 'function deleteWorkerApiKeyAction'));
expectDockerWorkerCommand(
    preg_match('/session_write_close\(\);\s*\$this->response->redirect\(/', $generateAction) === 1,
    'The new key must be saved to the session before the redirect'
);

// Creating or deleting a key reloads the page; it must reopen on the platform the administrator chose.
expectDockerWorkerCommand(
    substr_count($view, 'name="worker_platform" value="{{ workerDownloadPlatform|e }}"') === 2,
    'Key create and delete forms must carry the shown worker platform'
);
expectDockerWorkerCommand(
    substr_count($generateAction, '$this->workersTabPath()') === 1
        && str_contains($controller, "'&platform=' . \$platform"),
    'Key actions must redirect back to the chosen worker platform'
);
expectDockerWorkerCommand(
    str_contains($indexViewService, "getQuery('platform'") && str_contains($indexViewService, '$view->workerDownloadPlatform'),
    'The view must open the download block on the requested platform'
);
expectDockerWorkerCommand(
    str_contains($indexViewService, 'in_array($downloadPlatform, STTSetting::WORKER_PLATFORMS, true)')
        && str_contains($controller, 'in_array($platform, STTSetting::WORKER_PLATFORMS, true)'),
    'The Workers tab must reopen on any worker platform, Windows included'
);
expectDockerWorkerCommand(
    str_contains($view, 'data-worker-platform="{{ workerDownloadPlatform|e }}"'),
    'The Workers tab must carry the shown platform, Windows included'
);

echo "Docker worker command contract OK\n";
