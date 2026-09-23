<?php

declare(strict_types=1);

/**
 * Incremental auto-vacuum lifecycle of the module database: the one-time
 * conversion is postponed without enough free disk space, shrinks the file
 * when performed, and later deletions are returned to the file system by
 * releaseFreePages(). Never throws into setup or the retention worker.
 */

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;

function expectAutoVacuum(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for the auto-vacuum test\n");
    exit(1);
}

final class AutoVacuumConnection
{
    public function __construct(public readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        return $this->pdo->prepare($sql)->execute($bind);
    }

    public function fetchAll(string $sql, int $fetchMode = PDO::FETCH_ASSOC, array $bind = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetchAll($fetchMode);
    }
}

require_once dirname(__DIR__) . '/Lib/Services/ModuleDatabasePragmas.php';

$directory = sys_get_temp_dir() . '/stt-auto-vacuum-' . bin2hex(random_bytes(6));
mkdir($directory);
$path = $directory . '/module.db';
register_shutdown_function(static function () use ($directory): void {
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
});

$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = WAL');
$db = new AutoVacuumConnection($pdo);
$pdo->exec('CREATE TABLE payloads (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
$insert = $pdo->prepare('INSERT INTO payloads (body) VALUES (:body)');
$pdo->beginTransaction();
for ($i = 0; $i < 400; $i++) {
    $insert->execute(['body' => str_repeat(bin2hex(random_bytes(16)), 64)]);
}
$pdo->commit();
$pdo->exec('DELETE FROM payloads WHERE id % 2 = 0');
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$scalar = static fn (string $sql): int => (int)$pdo->query($sql)->fetchColumn();
expectAutoVacuum($scalar('PRAGMA auto_vacuum') === 0 && $scalar('PRAGMA freelist_count') > 0, 'fixture must start without auto-vacuum and with free pages');

$warnings = [];
$logger = static function (string $message) use (&$warnings): void {
    $warnings[] = $message;
};

// Not enough free space: conversion is postponed, database untouched.
$starved = new ModuleDatabasePragmas(null, $logger, static fn (string $directory): int => 1024);
expectAutoVacuum($starved->enableIncrementalAutoVacuum($db, $path) === false, 'conversion must be postponed without free space');
expectAutoVacuum($scalar('PRAGMA auto_vacuum') === 0, 'postponed conversion must not change auto_vacuum');
expectAutoVacuum(count($warnings) === 1 && str_contains($warnings[0], 'postponed'), 'postponed conversion must be logged');
expectAutoVacuum($starved->releaseFreePages($db) === 0, 'releasing pages is a no-op before the conversion');

// Enough space: VACUUM converts the database and shrinks the file.
$pages = $scalar('PRAGMA page_count');
$pragmas = new ModuleDatabasePragmas(null, $logger, static fn (string $directory): float => 1e15);
expectAutoVacuum($pragmas->enableIncrementalAutoVacuum($db, $path) === true, 'conversion must succeed with enough space');
expectAutoVacuum($scalar('PRAGMA auto_vacuum') === 2, 'database must run with incremental auto-vacuum');
expectAutoVacuum($scalar('PRAGMA freelist_count') === 0 && $scalar('PRAGMA page_count') < $pages, 'VACUUM must drop free pages');
expectAutoVacuum($pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'database must stay consistent');
expectAutoVacuum((int)$pdo->query('SELECT COUNT(*) FROM payloads')->fetchColumn() === 200, 'data must survive the conversion');

// Later deletions are returned by releaseFreePages().
$pdo->exec('DELETE FROM payloads WHERE id % 3 = 0');
$freed = $scalar('PRAGMA freelist_count');
$pages = $scalar('PRAGMA page_count');
expectAutoVacuum($freed > 0, 'deletion must free pages');
expectAutoVacuum($pragmas->releaseFreePages($db) === $freed, 'all free pages must be released');
expectAutoVacuum($scalar('PRAGMA freelist_count') === 0 && $scalar('PRAGMA page_count') === $pages - $freed, 'released pages must leave the file');

// Re-running setup on a converted database only releases pages.
expectAutoVacuum($pragmas->enableIncrementalAutoVacuum($db, $path) === true, 'repeated setup must be a no-op success');
expectAutoVacuum(count($warnings) === 1, 'successful runs must not warn: ' . json_encode($warnings));

echo "Module database incremental auto-vacuum OK\n";
