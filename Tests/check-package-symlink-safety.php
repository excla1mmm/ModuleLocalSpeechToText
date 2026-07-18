<?php

declare(strict_types=1);

function expectPackageSymlinkSafety(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removePackageFixture(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            removePackageFixture($path);
        }
    }
    rmdir($directory);
}

$temporaryDirectory = sys_get_temp_dir() . '/local-stt-package-symlink-' . bin2hex(random_bytes(8));
$fixtureRoot = $temporaryDirectory . '/module';
$outputDirectory = $temporaryDirectory . '/output';
try {
    mkdir($fixtureRoot . '/scripts', 0755, true);
    mkdir($fixtureRoot . '/Lib', 0755, true);
    copy(__DIR__ . '/../scripts/package-module.sh', $fixtureRoot . '/scripts/package-module.sh');
    chmod($fixtureRoot . '/scripts/package-module.sh', 0755);
    file_put_contents(
        $fixtureRoot . '/module.json',
        json_encode([
            'moduleUniqueID' => 'ModuleLocalSpeechToTextFixture',
            'version' => '1.0',
        ], JSON_THROW_ON_ERROR)
    );
    $secretPath = $temporaryDirectory . '/outside-secret.txt';
    file_put_contents($secretPath, 'must-not-enter-package');
    expectPackageSymlinkSafety(
        symlink($secretPath, $fixtureRoot . '/Lib/linked-secret.txt'),
        'unable to create package symlink fixture'
    );

    $command = escapeshellarg($fixtureRoot . '/scripts/package-module.sh')
        . ' ' . escapeshellarg($outputDirectory) . ' 2>&1';
    exec($command, $output, $exitCode);
    expectPackageSymlinkSafety($exitCode !== 0, 'package build must fail closed when an allowlisted tree has a symlink');
    expectPackageSymlinkSafety(
        str_contains(strtolower(implode("\n", $output)), 'symlink'),
        'package rejection must explain that a symlink was found'
    );

    $source = (string)file_get_contents(__DIR__ . '/../scripts/package-module.sh');
    expectPackageSymlinkSafety(
        str_contains($source, 'getExternalAttributesIndex') && str_contains($source, '0120000'),
        'ZIP validation must reject symlink entries by Unix mode attributes'
    );
} finally {
    removePackageFixture($temporaryDirectory);
}

echo "Package symlink safety behavior OK\n";
