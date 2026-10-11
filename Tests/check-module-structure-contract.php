<?php

declare(strict_types=1);

function expectTrueModuleStructure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$controllerPath = $root . '/App/Controllers/ModuleLocalSpeechToTextController.php';
$workerPath = $root . '/bin/WorkerLocalSpeechToTextJobs.php';
$legacyWorkerPath = $root . '/Lib/WorkerCreateTranscriptionJobs.php';
$conf = file_get_contents($root . '/Lib/LocalSpeechToTextConf.php');
$baseController = file_get_contents($root . '/App/Controllers/LocalSpeechToTextBaseController.php');
$manifest = json_decode((string)file_get_contents($root . '/module.json'), true);

expectTrueModuleStructure(is_file($controllerPath), 'Cannot read ModuleLocalSpeechToTextController.php');
expectTrueModuleStructure(is_file($workerPath), 'Worker must live under bin/WorkerLocalSpeechToTextJobs.php');
expectTrueModuleStructure(!file_exists($legacyWorkerPath), 'Legacy Lib/WorkerCreateTranscriptionJobs.php must not exist');
expectTrueModuleStructure(is_array($manifest), 'module.json must be valid JSON');
expectTrueModuleStructure(($manifest['version'] ?? null) === '1.47', 'module.json version must be exactly 1.47');
expectTrueModuleStructure(($manifest['min_pbx_version'] ?? null) === '2025.1.1', 'module.json min_pbx_version must be exactly 2025.1.1');
expectTrueModuleStructure($conf !== false, 'Cannot read LocalSpeechToTextConf.php');
expectTrueModuleStructure(
    !str_contains((string)$baseController, 'logoImagePath') && !file_exists($root . '/public/assets/img/logo.svg'),
    'Module pages must not publish the microphone logo'
);

$controllerLineCount = count(file($controllerPath) ?: []);
expectTrueModuleStructure($controllerLineCount <= 500, 'Controller must stay under 500 lines');
expectTrueModuleStructure(
    str_contains((string)$conf, 'use Modules\\ModuleLocalSpeechToText\\bin\\WorkerLocalSpeechToTextJobs;')
        && str_contains((string)$conf, 'WorkerLocalSpeechToTextJobs::class'),
    'Module config must register the bin worker class'
);
expectTrueModuleStructure(
    ($manifest['developer'] ?? '') !== 'Local'
        && ($manifest['support_email'] ?? '') !== 'support@example.local',
    'module.json must not contain template developer/support placeholders'
);

$messageFiles = glob($root . '/Messages/*.php') ?: [];
expectTrueModuleStructure(count($messageFiles) >= 29, 'Messages directory must contain the standard 29 locale files');

$scanDirs = ['App', 'Lib', 'Models', 'Setup', 'bin'];
$violations = [];
foreach ($scanDirs as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string)file_get_contents($file->getPathname());
        if (preg_match('/@(filemtime|filesize|fopen|chmod)\\s*\\(/', $contents) === 1) {
            $violations[] = str_replace($root . '/', '', $file->getPathname()) . ': suppressed filesystem call';
        }
        if (str_contains($contents, 'exit;')) {
            $violations[] = str_replace($root . '/', '', $file->getPathname()) . ': exit statement';
        }
    }
}

expectTrueModuleStructure($violations === [], "Structure anti-patterns found:\n" . implode("\n", $violations));

echo "Module structure contract OK\n";
