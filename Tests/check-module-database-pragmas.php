<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;

require_once __DIR__ . '/../Lib/Services/ModuleDatabasePragmas.php';

function expectModuleDatabasePragmas(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ModuleDatabasePragmaConnection
{
    public function __construct(private readonly PDO $pdo, private readonly bool $rejectWal = false)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        if ($this->rejectWal && str_contains(strtoupper($sql), 'JOURNAL_MODE=WAL')) {
            return true;
        }
        $statement = $this->pdo->prepare($sql);

        return $statement->execute($bind);
    }

    public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
    {
        if ($this->rejectWal && str_contains(strtoupper($sql), 'JOURNAL_MODE=WAL')) {
            return [['delete']];
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetchAll($fetchMode);
    }
}

function moduleDatabasePragmaFixture(string $root, string $name): array
{
    $directory = $root . '/' . $name;
    if (!mkdir($directory, 0750, true)) {
        throw new RuntimeException('Unable to create pragma fixture');
    }
    $path = $directory . '/module.db';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE sample(id INTEGER PRIMARY KEY, value TEXT)');

    return [$directory, $path, $pdo];
}

function removeModuleDatabasePragmaFixture(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Unable to remove pragma fixture file');
        }
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            removeModuleDatabasePragmaFixture($entry->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Unable to remove pragma fixture directory');
    }
}

if (!extension_loaded('pdo_sqlite') || !extension_loaded('sqlite3')) {
    fwrite(STDERR, "pdo_sqlite and sqlite3 are required for database pragma tests\n");
    exit(1);
}

$fixtureRoot = sys_get_temp_dir() . '/module-stt-pragmas-' . bin2hex(random_bytes(6));
if (!mkdir($fixtureRoot, 0750, true)) {
    throw new RuntimeException('Unable to create pragma test root');
}

try {
    [$ext4Directory, $ext4Path, $ext4Pdo] = moduleDatabasePragmaFixture($fixtureRoot, 'ext4');
    $warnings = [];
    $ext4 = new ModuleDatabasePragmas(
        static fn (string $path): string => 'ext4',
        static function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        }
    );
    $ext4->writeSafeBackupCapability($ext4Directory);
    $ext4Connection = new ModuleDatabasePragmaConnection($ext4Pdo);
    expectModuleDatabasePragmas(
        $ext4->enableWalAfterVerifiedSetup($ext4Connection, $ext4Path),
        'Safe local filesystem with a capability marker must enable WAL'
    );
    expectModuleDatabasePragmas(
        strtolower((string)$ext4Pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal',
        'Journal mode must be WAL after verified setup'
    );
    expectModuleDatabasePragmas(
        (int)$ext4Pdo->query('PRAGMA busy_timeout')->fetchColumn() === 5000,
        'Runtime busy timeout must be bounded at five seconds'
    );
    expectModuleDatabasePragmas(
        (int)$ext4Pdo->query('PRAGMA synchronous')->fetchColumn() === 1,
        'WAL runtime must use synchronous NORMAL'
    );
    expectModuleDatabasePragmas($warnings === [], 'Healthy WAL activation must not warn');
    $ext4->checkpoint($ext4Connection);

    [$missingDirectory, $missingPath, $missingPdo] = moduleDatabasePragmaFixture($fixtureRoot, 'missing-marker');
    $missing = new ModuleDatabasePragmas(static fn (string $path): string => 'ext4');
    expectModuleDatabasePragmas(
        !$missing->enableWalAfterVerifiedSetup(new ModuleDatabasePragmaConnection($missingPdo), $missingPath),
        'WAL must remain disabled before safe-backup capability is recorded'
    );
    expectModuleDatabasePragmas(
        strtolower((string)$missingPdo->query('PRAGMA journal_mode')->fetchColumn()) === 'delete',
        'Missing capability marker must preserve DELETE mode'
    );

    foreach (['nfs4', 'cifs', 'fuse.sshfs', 'unknown'] as $filesystem) {
        [$unsafeDirectory, $unsafePath, $unsafePdo] = moduleDatabasePragmaFixture($fixtureRoot, $filesystem);
        $unsafe = new ModuleDatabasePragmas(
            static fn (string $path): string => $filesystem,
            static function (string $message): void {
            }
        );
        $unsafe->writeSafeBackupCapability($unsafeDirectory);
        expectModuleDatabasePragmas(
            !$unsafe->enableWalAfterVerifiedSetup(new ModuleDatabasePragmaConnection($unsafePdo), $unsafePath),
            "{$filesystem} must not enable WAL"
        );
        expectModuleDatabasePragmas(
            strtolower((string)$unsafePdo->query('PRAGMA journal_mode')->fetchColumn()) === 'delete',
            "{$filesystem} must retain DELETE journal mode"
        );
        $unsafePdo = null;
    }

    [$failedDirectory, $failedPath, $failedPdo] = moduleDatabasePragmaFixture($fixtureRoot, 'failed-transition');
    $failedWarnings = [];
    $failed = new ModuleDatabasePragmas(
        static fn (string $path): string => 'ext2/ext3',
        static function (string $message) use (&$failedWarnings): void {
            $failedWarnings[] = $message;
        }
    );
    $failed->writeSafeBackupCapability($failedDirectory);
    expectModuleDatabasePragmas(
        !$failed->enableWalAfterVerifiedSetup(
            new ModuleDatabasePragmaConnection($failedPdo, rejectWal: true),
            $failedPath
        ),
        'Unexpected journal-mode response must degrade without failing setup'
    );
    expectModuleDatabasePragmas(
        strtolower((string)$failedPdo->query('PRAGMA journal_mode')->fetchColumn()) === 'delete'
            && count($failedWarnings) >= 1,
        'Failed WAL transition must restore DELETE mode and log a warning'
    );

    [$backupDirectory, $backupPath, $backupPdo] = moduleDatabasePragmaFixture($fixtureRoot, 'backup-normalize');
    expectModuleDatabasePragmas(
        strtolower((string)$backupPdo->query('PRAGMA journal_mode=WAL')->fetchColumn()) === 'wal',
        'Backup normalization fixture could not enter WAL'
    );
    $backupPdo->exec("INSERT INTO sample(value) VALUES ('committed')");
    $backupPdo = null;
    $backupDatabase = new SQLite3($backupPath, SQLITE3_OPEN_READWRITE);
    $ext4->checkpointAndNormalizeBackup($backupDatabase);
    $backupDatabase->close();
    $normalizedBackup = new SQLite3($backupPath, SQLITE3_OPEN_READONLY);
    $normalizedJournalMode = strtolower((string)$normalizedBackup->querySingle('PRAGMA journal_mode'));
    $normalizedBackup->close();
    expectModuleDatabasePragmas(
        !is_file($backupPath . '-wal')
            && !is_file($backupPath . '-shm')
            && $normalizedJournalMode === 'delete',
        'Normalized backup must be self-contained in DELETE mode'
    );

    $setupSource = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
    $confSource = file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextConf.php');
    $scannerSource = file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
    $jobsProcessor = file_get_contents(__DIR__ . '/../Lib/RestAPI/Jobs/Processor.php');
    $workersProcessor = file_get_contents(__DIR__ . '/../Lib/RestAPI/Workers/Processor.php');
    $adminController = file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');
    $packageSource = file_get_contents(__DIR__ . '/../scripts/package-module.sh');
    expectModuleDatabasePragmas(
        $setupSource !== false && $confSource !== false && $scannerSource !== false
            && $jobsProcessor !== false && $workersProcessor !== false
            && $adminController !== false && $packageSource !== false,
        'Cannot inspect WAL runtime integration'
    );
    $verifyPosition = strpos($setupSource, '$databaseBackup->verify($databasePath)');
    $markerPosition = strpos($setupSource, 'writeSafeBackupCapability(');
    $walPosition = strpos($setupSource, 'enableWalAfterVerifiedSetup(');
    expectModuleDatabasePragmas(
        $verifyPosition !== false && $markerPosition !== false && $walPosition !== false
            && $verifyPosition < $markerPosition && $markerPosition < $walPosition,
        'Setup must verify the migrated database, publish capability, then attempt WAL'
    );
    expectModuleDatabasePragmas(
        str_contains($confSource, 'enableWalAfterVerifiedSetup(')
            && str_contains($scannerSource, 'configureRuntime(')
            && str_contains($jobsProcessor, 'configureRuntime(')
            && str_contains($workersProcessor, 'configureRuntime(')
            && str_contains($adminController, 'configureRuntime('),
        'Every STT process boundary must configure its SQLite runtime connection'
    );
    expectModuleDatabasePragmas(
        str_contains($packageSource, '.stt-safe-backup-'),
        'Runtime safe-backup capability must be excluded from release packages'
    );
} finally {
    $ext4Pdo = null;
    $missingPdo = null;
    $failedPdo = null;
    removeModuleDatabasePragmaFixture($fixtureRoot);
}

echo "Module database pragma behavior OK\n";
