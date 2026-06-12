<?php

declare(strict_types=1);

$tests = glob(__DIR__ . '/check-*.php') ?: [];
sort($tests);

if ($tests === []) {
    fwrite(STDERR, "No tests found\n");
    exit(1);
}

$failures = [];
foreach ($tests as $test) {
    $name = basename($test);
    echo "==> {$name}\n";

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test);
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        $failures[] = $name;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nFailed tests:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nAll module contract tests passed\n";
