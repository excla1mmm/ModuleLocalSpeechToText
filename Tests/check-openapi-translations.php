<?php

declare(strict_types=1);

function expectOpenApiTranslation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Extracts the raw argument bodies of every #[Attribute(...)] occurrence.
 *
 * @return list<string>
 */
function openApiAttributeBodies(string $source, string $attribute): array
{
    $bodies = [];
    $needle = '#[' . $attribute;
    $offset = 0;
    while (($pos = strpos($source, $needle, $offset)) !== false) {
        $open = strpos($source, '(', $pos + strlen($needle));
        $between = $open === false ? '?' : substr($source, $pos + strlen($needle), $open - $pos - strlen($needle));
        if ($open === false || trim($between) !== '') {
            $offset = $pos + strlen($needle);
            continue;
        }
        $depth = 0;
        $end = strlen($source);
        for ($i = $open; $i < $end; $i++) {
            $char = $source[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                $bodies[] = substr($source, $open + 1, $i - $open - 1);
                break;
            }
        }
        $offset = $i + 1;
    }
    return $bodies;
}

/** @return list<string> */
function openApiQuotedLiterals(string $body): array
{
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $body, $matches);
    return array_map(
        static fn(string $literal): string => stripcslashes($literal),
        $matches[1] ?? []
    );
}

/** @return array<string, string> */
function openApiNamedArguments(string $body): array
{
    preg_match_all("/(?:^|,)\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/m", $body, $matches);
    $arguments = [];
    foreach ($matches[1] ?? [] as $index => $name) {
        $arguments[$name] = stripcslashes($matches[2][$index]);
    }
    return $arguments;
}

function isOpenApiTranslationKey(string $value): bool
{
    return (bool)preg_match('/^(?:rest_response_|rest_tag_|module_local_speech_to_text_)[A-Za-z0-9_]+$/', $value);
}

$expectedRussian = [
    'rest_tag_LocalSTTWorkers' => 'STT-воркеры',
    'rest_tag_LocalSTTJobs' => 'Задания STT',
    'rest_tag_LocalSTTTranscripts' => 'Транскрипции STT',
    'rest_tag_LocalSTTCallTranscripts' => 'Транскрипции звонков STT',
];
$expectedEnglish = [
    'rest_tag_LocalSTTWorkers' => 'STT Workers',
    'rest_tag_LocalSTTJobs' => 'STT Jobs',
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
// A non-English catalog may reuse the English text only when the value is purely technical.
$technicalOnlyValues = [
    'API' => true, 'CDR' => true, 'HTTP' => true, 'JSON' => true, 'PBX' => true,
    'RMS' => true, 'SHA-256' => true, 'STT' => true, 'VAD' => true, 'null' => true,
];

// The scan must cover every schema-provider file shape, including
// non-standard *DataStructure.php classes, or retired copy can hide there.
foreach (glob($root . '/Lib/RestAPI/*/{Controller,*DataStructure}.php', GLOB_BRACE) ?: [] as $openApiFile) {
    $relative = basename(dirname($openApiFile)) . '/' . basename($openApiFile);
    $source = file_get_contents($openApiFile);
    expectOpenApiTranslation(is_string($source), 'Unable to read OpenAPI source: ' . $relative);

    foreach (openApiAttributeBodies($source, 'ApiResource') as $body) {
        $arguments = openApiNamedArguments($body);
        expectOpenApiTranslation(isset($arguments['description']), "{$relative} ApiResource must declare a named description translation key");
        expectOpenApiTranslation(isOpenApiTranslationKey($arguments['description']), "{$relative} ApiResource description uses a literal string instead of a translation key");
    }
    foreach (openApiAttributeBodies($source, 'ApiOperation') as $body) {
        $arguments = openApiNamedArguments($body);
        foreach (['summary', 'description'] as $argument) {
            expectOpenApiTranslation(isset($arguments[$argument]) && trim($arguments[$argument]) !== '', "{$relative} ApiOperation must declare a non-empty {$argument} translation key");
            expectOpenApiTranslation(isOpenApiTranslationKey($arguments[$argument] ?? ''), "{$relative} ApiOperation {$argument} uses a literal string instead of a translation key");
        }
        $withoutOperationId = (string)preg_replace("/operationId\s*:\s*'(?:[^'\\\\]|\\\\.)*'/", '', $body);
        foreach (openApiQuotedLiterals($withoutOperationId) as $literal) {
            expectOpenApiTranslation(isOpenApiTranslationKey($literal), "{$relative} ApiOperation uses the literal string '{$literal}' instead of a translation key");
        }
    }
    foreach (openApiAttributeBodies($source, 'ApiResponse') as $body) {
        $literals = openApiQuotedLiterals($body);
        expectOpenApiTranslation($literals !== [], "{$relative} ApiResponse must reference a translation key");
        expectOpenApiTranslation(isOpenApiTranslationKey($literals[0]), "{$relative} ApiResponse description uses the literal string '{$literals[0]}' instead of a translation key");
    }

    preg_match_all('/(?:module_local_speech_to_text_[A-Z][A-Za-z0-9_]+|rest_tag_[A-Za-z0-9_]+)/', $source, $matches);
    foreach ($matches[0] ?? [] as $key) {
        $openApiKeys[$key] = true;
    }
    preg_match_all("/tags:\s*\[\s*'([^']+)'\s*\]/", $source, $tagMatches);
    foreach ($tagMatches[1] ?? [] as $tag) {
        expectOpenApiTranslation(!str_starts_with($tag, 'rest_tag_'), basename(dirname($openApiFile)) . ' exposes a raw rest_tag label');
    }
}

foreach (glob($root . '/Messages/*.php') ?: [] as $messageFile) {
    $catalog = basename($messageFile);
    $messages = include $messageFile;
    expectOpenApiTranslation(is_array($messages), 'Invalid translation catalog: ' . $catalog);
    expectOpenApiTranslation(array_keys($messages) === $sourceKeys, $catalog . ' translation keys differ from ru.php');
    foreach (array_keys($openApiKeys) as $key) {
        expectOpenApiTranslation(isset($messages[$key]) && trim((string)$messages[$key]) !== '', $catalog . " has an empty {$key}");
        if (!str_ends_with($messageFile, '/en.php')
            && ($messages[$key] ?? null) === ($english[$key] ?? null)
            && !isset($technicalOnlyValues[$english[$key] ?? ''])
        ) {
            expectOpenApiTranslation(false, $catalog . " uses an English fallback for {$key}");
        }
    }
}

foreach (array_keys($openApiKeys) as $key) {
    expectOpenApiTranslation(isset($russian[$key], $english[$key]), "OpenAPI key is missing from the source catalogs: {$key}");
}

/*
 * Reverse coverage: a catalog key that only looks like OpenAPI copy but is
 * referenced nowhere is retired worker-API v1 residue. It must either be
 * supplied by an annotation/resource reference collected above or be listed
 * here with a justification.
 */
$rendererOwnedCatalogKeys = [];
foreach (array_keys($english) as $key) {
    $isOpenApiShaped = (bool)preg_match(
        '/^(?:module_local_speech_to_text_Api[A-Za-z0-9_]*|module_local_speech_to_text_[A-Za-z0-9_]*ApiDescription)$/',
        $key
    );
    if ($isOpenApiShaped && !isset($openApiKeys[$key]) && !isset($rendererOwnedCatalogKeys[$key])) {
        expectOpenApiTranslation(false, "Catalog OpenAPI key is referenced by no annotation or template: {$key}");
    }
}

echo "OpenAPI translations contract OK\n";
