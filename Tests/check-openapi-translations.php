<?php

declare(strict_types=1);

function expectOpenApiTranslation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expectedRussian = [
    'rest_tag_LocalSTTWorkers' => 'STT-воркеры',
    'rest_tag_LocalSTTJobs' => 'Задания STT',
    'rest_tag_LocalSTTRecordings' => 'Записи STT',
    'rest_tag_LocalSTTTranscripts' => 'Транскрипции STT',
    'rest_tag_LocalSTTCallTranscripts' => 'Транскрипции звонков STT',
];
$expectedEnglish = [
    'rest_tag_LocalSTTWorkers' => 'STT Workers',
    'rest_tag_LocalSTTJobs' => 'STT Jobs',
    'rest_tag_LocalSTTRecordings' => 'STT Recordings',
    'rest_tag_LocalSTTTranscripts' => 'STT Transcripts',
    'rest_tag_LocalSTTCallTranscripts' => 'STT Call Transcripts',
];

$root = dirname(__DIR__);
$russian = include $root . '/Messages/ru.php';
$english = include $root . '/Messages/en.php';
foreach ($expectedRussian as $key => $value) {
    expectOpenApiTranslation(($russian[$key] ?? null) === $value, "Russian OpenAPI tag mismatch: {$key}");
}
foreach ($expectedEnglish as $key => $value) {
    expectOpenApiTranslation(($english[$key] ?? null) === $value, "English OpenAPI tag mismatch: {$key}");
}

$sourceKeys = array_keys($russian);
$openApiKeys = array_fill_keys(array_keys($expectedEnglish), true);
$intentionalOpenApiLabels = [
    // Binary response descriptions are deliberately supplied by the REST response renderer.
    'module_local_speech_to_text_ApiResponseRecordingDownloaded' => true,
];
foreach (glob($root . '/Lib/RestAPI/*/{Controller,DataStructure,ListDataStructure}.php', GLOB_BRACE) ?: [] as $openApiFile) {
    $source = file_get_contents($openApiFile);
    expectOpenApiTranslation(is_string($source), 'Unable to read OpenAPI source: ' . basename($openApiFile));
    preg_match_all('/(?:module_local_speech_to_text_[A-Z][A-Za-z0-9_]+|rest_tag_[A-Za-z0-9_]+)/', $source, $matches);
    foreach ($matches[0] ?? [] as $key) {
        if (!isset($intentionalOpenApiLabels[$key])) {
            $openApiKeys[$key] = true;
        }
    }
    preg_match_all("/tags:\s*\[\s*'([^']+)'\s*\]/", $source, $tagMatches);
    foreach ($tagMatches[1] ?? [] as $tag) {
        expectOpenApiTranslation(!str_starts_with($tag, 'rest_tag_'), basename(dirname($openApiFile)) . ' exposes a raw rest_tag label');
    }
}
foreach (glob($root . '/Messages/*.php') ?: [] as $messageFile) {
    $messages = include $messageFile;
    expectOpenApiTranslation(is_array($messages), 'Invalid translation catalog: ' . basename($messageFile));
    expectOpenApiTranslation(array_keys($messages) === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    if (!str_ends_with($messageFile, '/ru.php') && !str_ends_with($messageFile, '/en.php')) {
        foreach (array_keys($openApiKeys) as $key) {
            expectOpenApiTranslation(isset($messages[$key]) && trim((string)$messages[$key]) !== '', basename($messageFile) . " has an empty {$key}");
            if (isset($expectedEnglish[$key])) {
                expectOpenApiTranslation($messages[$key] !== $english[$key], basename($messageFile) . " uses an English fallback for {$key}");
            }
        }
    }
}

foreach (array_keys($openApiKeys) as $key) {
    expectOpenApiTranslation(isset($russian[$key], $english[$key]), "OpenAPI key is missing from the source catalogs: {$key}");
}

echo "OpenAPI translations contract OK\n";
