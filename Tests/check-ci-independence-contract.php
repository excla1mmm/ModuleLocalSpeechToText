<?php

declare(strict_types=1);

function expectCiIndependence(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$ci = (string)file_get_contents($root . '/.gitlab-ci.yml');
$permissionsTest = (string)file_get_contents(__DIR__ . '/check-worker-api-key-permissions.php');

expectCiIndependence(str_contains($ci, 'php Tests/run-all.php'), 'CI must run the complete standalone test suite');
expectCiIndependence(!str_contains($ci, 'SKIP external dependency'), 'CI must not hide tests behind external-dependency skips');
expectCiIndependence(str_contains($ci, "docker-php-ext-install pcntl"), 'CI must install pcntl for process-concurrency tests');
expectCiIndependence(!str_contains($permissionsTest, '/Users/'), 'Worker permission test must not use a workstation-specific Core path');
expectCiIndependence(!str_contains($permissionsTest, "'/src/PBXCoreREST/"), 'Worker permission test must not load an external Core checkout');

foreach (['ActionType.php', 'ApiKeyPermissionChecker.php'] as $fixture) {
    expectCiIndependence(
        is_file(__DIR__ . '/fixtures/core/' . $fixture),
        "Required portable Core fixture is missing: {$fixture}"
    );
}

echo "Standalone CI test contract OK\n";
