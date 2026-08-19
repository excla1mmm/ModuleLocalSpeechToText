<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = $root . '/Lib/Services/STTJobManagementService.php';
if (!is_file($service)) {
    fwrite(STDERR, "STTJobManagementService implementation is missing\n");
    exit(1);
}

$controller = file_get_contents($root . '/Lib/RestAPI/Jobs/Controller.php');
$processor = file_get_contents($root . '/Lib/RestAPI/Jobs/Processor.php');
$schema = file_get_contents($root . '/Lib/RestAPI/Jobs/DataStructure.php');
$serviceSource = file_get_contents($service);

$required = [
    "'GET' => ['getList', 'getRecord']",
    "'POST' => ['create']",
    "'PATCH' => ['patch', 'patchCollection']",
    "'DELETE' => ['delete', 'deleteCollection']",
    "idPattern: '[0-9]+'",
];
foreach ($required as $fragment) {
    if (!str_contains($controller, $fragment)) {
        throw new RuntimeException("Missing management route: {$fragment}");
    }
}
foreach (['GET_LIST', 'GET_RECORD', 'CREATE', 'PATCH', 'PATCH_COLLECTION', 'DELETE', 'DELETE_COLLECTION'] as $case) {
    if (!str_contains($processor, "case {$case}")) {
        throw new RuntimeException("Processor action {$case} is missing");
    }
}
foreach (['getList', 'getRecord', 'create', 'patch', 'patchCollection', 'delete', 'deleteCollection'] as $method) {
    if (!preg_match('/function ' . $method . '\\(/', $serviceSource)) {
        throw new RuntimeException("Management service method {$method} is missing");
    }
}
foreach (['call_id', 'file_path', 'priority', 'ids', 'action', 'status', 'limit', 'offset'] as $parameter) {
    if (!str_contains($schema, "'{$parameter}'")) {
        throw new RuntimeException("Management schema parameter {$parameter} is missing");
    }
}

foreach ([
    "'collection' => 'patchCollection'",
    "'collection' => 'deleteCollection'",
    "'_query_status_present'",
] as $routingContract) {
    if (!str_contains($controller, $routingContract)) {
        throw new RuntimeException("Core-shaped management routing/provenance contract is missing: {$routingContract}");
    }
}
foreach ([
    'CanonicalJobId::matchesRoute(',
    'm_STTTranscriptEvent',
    'm_STTCallTranscriptPart',
    'lstat(',
    'pagination',
] as $behaviorContract) {
    if (!str_contains($processor . $serviceSource, $behaviorContract)) {
        throw new RuntimeException("Management behavior contract is missing: {$behaviorContract}");
    }
}

echo "STT management Jobs v2 contract OK\n";
