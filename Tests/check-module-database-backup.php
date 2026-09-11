<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseBackup;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;

require_once __DIR__ . '/../Lib/Services/ModuleDatabasePragmas.php';
require_once __DIR__ . '/../Lib/Services/ModuleDatabaseBackup.php';

function expectModuleDatabaseBackup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeModuleDatabaseBackupFixture(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Unable to remove test file: ' . $path);
        }
        return;
    }

    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) {
            continue;
        }
        removeModuleDatabaseBackupFixture($entry->getPathname());
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Unable to remove test directory: ' . $path);
    }
}

if (!extension_loaded('sqlite3') || !method_exists(SQLite3::class, 'backup')) {
    fwrite(STDERR, "sqlite3 with SQLite3::backup is required for database backup tests\n");
    exit(1);
}

$fixtureRoot = sys_get_temp_dir() . '/module-stt-database-backup-' . bin2hex(random_bytes(6));
$sourceDirectory = $fixtureRoot . '/source';
$snapshotDirectory = $fixtureRoot . '/snapshot';
$restoreDirectory = $fixtureRoot . '/restore';
$corruptSnapshotDirectory = $fixtureRoot . '/corrupt';

if (!mkdir($sourceDirectory, 0750, true)) {
    throw new RuntimeException('Unable to create database backup fixture');
}

try {
    $sourcePath = $sourceDirectory . '/module.db';
    $source = new SQLite3($sourcePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    expectModuleDatabaseBackup(
        strtolower((string)$source->querySingle('PRAGMA journal_mode=WAL')) === 'wal',
        'WAL fixture could not be enabled'
    );
    $source->exec('PRAGMA wal_autocheckpoint=0');
    $source->exec('CREATE TABLE sample(id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $source->exec("INSERT INTO sample(value) VALUES ('first'), ('second')");
    $source->exec('CREATE TABLE m_STTSetting(id INTEGER PRIMARY KEY, key TEXT UNIQUE, value TEXT)');
    $source->exec("INSERT INTO m_STTSetting(key,value) VALUES ('source_instance_id','0123456789abcdef0123456789abcdef')");
    file_put_contents($sourceDirectory . '/state.json', '{"preserved":true}');
    file_put_contents($sourceDirectory . '/module.db-journal', '');
    file_put_contents($sourceDirectory . '/.module-lifecycle-state-v2.json', '{"state":"disabled"}');
    (new ModuleDatabasePragmas())->writeSafeBackupCapability($sourceDirectory);

    $reader = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
    $readerResult = $reader->query('SELECT * FROM sample ORDER BY id');
    expectModuleDatabaseBackup($readerResult instanceof SQLite3Result, 'WAL reader fixture was not opened');

    $backup = new ModuleDatabaseBackup();
    $backup->snapshot($sourceDirectory, $snapshotDirectory);

    expectModuleDatabaseBackup(is_file($snapshotDirectory . '/module.db'), 'Snapshot database is missing');
    expectModuleDatabaseBackup(!is_file($snapshotDirectory . '/module.db-wal'), 'WAL sidecar must not be copied');
    expectModuleDatabaseBackup(!is_file($snapshotDirectory . '/module.db-shm'), 'SHM sidecar must not be copied');
    expectModuleDatabaseBackup(!is_file($snapshotDirectory . '/module.db-journal'), 'Rollback journal must not be copied');
    expectModuleDatabaseBackup(
        !is_file($snapshotDirectory . '/.module-lifecycle-state-v2.json'),
        'Lifecycle runtime marker must not be copied'
    );
    expectModuleDatabaseBackup(
        file_get_contents($snapshotDirectory . '/state.json') === '{"preserved":true}',
        'Ordinary database-directory state must be copied'
    );
    expectModuleDatabaseBackup(
        is_file($snapshotDirectory . '/' . ModuleDatabasePragmas::SAFE_BACKUP_MARKER),
        'Verified backups must preserve the installed safe-backup capability'
    );

    $snapshot = new SQLite3($snapshotDirectory . '/module.db', SQLITE3_OPEN_READONLY);
    expectModuleDatabaseBackup(
        (int)$snapshot->querySingle('SELECT COUNT(*) FROM sample') === 2,
        'Snapshot lost committed WAL rows'
    );
    expectModuleDatabaseBackup(
        (string)$snapshot->querySingle("SELECT value FROM m_STTSetting WHERE key='source_instance_id'") === '0123456789abcdef0123456789abcdef',
        'Snapshot changed the STT source instance identity'
    );
    expectModuleDatabaseBackup(
        strtolower((string)$snapshot->querySingle('PRAGMA journal_mode')) === 'delete',
        'Snapshot must be normalized to DELETE journal mode'
    );
    $snapshot->close();

    $backup->restore($snapshotDirectory, $restoreDirectory);
    $restored = new SQLite3($restoreDirectory . '/module.db', SQLITE3_OPEN_READONLY);
    expectModuleDatabaseBackup(
        (int)$restored->querySingle('SELECT COUNT(*) FROM sample') === 2,
        'Restore lost committed rows'
    );
    expectModuleDatabaseBackup(
        (string)$restored->querySingle("SELECT value FROM m_STTSetting WHERE key='source_instance_id'") === '0123456789abcdef0123456789abcdef',
        'Restore changed the STT source instance identity'
    );
    $restored->close();

    if (!mkdir($corruptSnapshotDirectory, 0750, true)) {
        throw new RuntimeException('Unable to create corrupt snapshot fixture');
    }
    file_put_contents($corruptSnapshotDirectory . '/module.db', 'not a sqlite database');
    file_put_contents($restoreDirectory . '/sentinel.txt', 'old-destination');

    $restoreFailed = false;
    try {
        $backup->restore($corruptSnapshotDirectory, $restoreDirectory);
    } catch (Throwable) {
        $restoreFailed = true;
    }
    expectModuleDatabaseBackup($restoreFailed, 'Corrupt snapshot restore must fail');
    expectModuleDatabaseBackup(
        file_get_contents($restoreDirectory . '/sentinel.txt') === 'old-destination',
        'Failed restore must leave the old destination intact'
    );
    $stillRestored = new SQLite3($restoreDirectory . '/module.db', SQLITE3_OPEN_READONLY);
    expectModuleDatabaseBackup(
        (int)$stillRestored->querySingle('SELECT COUNT(*) FROM sample') === 2,
        'Failed restore damaged the old destination database'
    );
    $stillRestored->close();

    expectModuleDatabaseBackup(
        (int)$source->querySingle('SELECT COUNT(*) FROM sample') === 2,
        'Backup or corrupt verification modified the source database'
    );

    $readerResult->finalize();
    $readerResult = null;
    $reader->close();
    $reader = null;
    $source->close();
    $source = null;
} finally {
    if (isset($readerResult) && $readerResult instanceof SQLite3Result) {
        $readerResult->finalize();
    }
    if (isset($reader) && $reader instanceof SQLite3) {
        $reader->close();
    }
    if (isset($source) && $source instanceof SQLite3) {
        $source->close();
    }
    removeModuleDatabaseBackupFixture($fixtureRoot);
}

echo "Module database backup behavior OK\n";
