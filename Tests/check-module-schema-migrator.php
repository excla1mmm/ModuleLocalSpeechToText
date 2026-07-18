<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigrator;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigration.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigrator.php';

function expectModuleSchemaMigrator(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ModuleSchemaMigratorConnection
{
    private bool $failLedgerInsert = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        if ($this->failLedgerInsert && str_starts_with(ltrim($sql), 'INSERT INTO m_STTSchemaMigration')) {
            $this->failLedgerInsert = false;
            throw new RuntimeException('simulated ledger write failure');
        }
        $statement = $this->pdo->prepare($sql);

        return $statement->execute($bind);
    }

    public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetchAll($fetchMode);
    }

    public function begin(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function isUnderTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function failNextLedgerInsert(): void
    {
        $this->failLedgerInsert = true;
    }
}

function newModuleSchemaMigratorConnection(): ModuleSchemaMigratorConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return new ModuleSchemaMigratorConnection($pdo);
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for schema migration tests\n");
    exit(1);
}

$connection = newModuleSchemaMigratorConnection();
$freshCalls = 0;
$migrator = new ModuleSchemaMigrator([
    new ModuleSchemaMigration(
        2,
        'add sample value',
        static fn (mixed $database): bool => $database->execute(
            "ALTER TABLE sample ADD COLUMN value TEXT NOT NULL DEFAULT ''"
        ),
        'sample-value-v1'
    ),
    new ModuleSchemaMigration(
        1,
        'create sample',
        static fn (mixed $database): bool => $database->execute(
            'CREATE TABLE sample(id INTEGER PRIMARY KEY)'
        ),
        'sample-base-v1'
    ),
]);

$applied = $migrator->migrate(
    $connection,
    static function (mixed $database) use (&$freshCalls): void {
        $freshCalls++;
        $database->execute('CREATE TABLE bootstrap(id INTEGER PRIMARY KEY)');
    }
);
expectModuleSchemaMigrator($applied === [1, 2], 'Migrations must apply in numeric order');
expectModuleSchemaMigrator($freshCalls === 1, 'Fresh schema callback must run exactly once');
expectModuleSchemaMigrator(
    $migrator->migrate($connection, static function () use (&$freshCalls): void {
        $freshCalls++;
    }) === [],
    'Repeated migration must be a no-op'
);
expectModuleSchemaMigrator($freshCalls === 1, 'Fresh schema callback must not run on an existing database');

$ledgerRows = $connection->fetchAll(
    'SELECT version, name, checksum FROM m_STTSchemaMigration ORDER BY version',
    PDO::FETCH_ASSOC
);
expectModuleSchemaMigrator(
    array_map(static fn (array $row): int => (int)$row['version'], $ledgerRows) === [1, 2],
    'Ledger must contain every completed migration exactly once'
);

$driftRejected = false;
try {
    (new ModuleSchemaMigrator([
        new ModuleSchemaMigration(1, 'renamed sample', static fn (): bool => true, 'sample-base-v1'),
        new ModuleSchemaMigration(2, 'add sample value', static fn (): bool => true, 'changed-checksum'),
    ]))->migrate($connection, static fn (): null => null);
} catch (Throwable) {
    $driftRejected = true;
}
expectModuleSchemaMigrator($driftRejected, 'Applied migration name/checksum drift must be rejected');

$atomicConnection = newModuleSchemaMigratorConnection();
$atomicConnection->execute('CREATE TABLE existing(id INTEGER PRIMARY KEY)');
$atomicConnection->failNextLedgerInsert();
$atomicFailed = false;
try {
    (new ModuleSchemaMigrator([
        new ModuleSchemaMigration(
            1,
            'atomic schema and ledger',
            static fn (mixed $database): bool => $database->execute('CREATE TABLE atomic_fragment(id INTEGER)'),
            'atomic-v1'
        ),
    ]))->migrate($atomicConnection, static fn (): null => null);
} catch (Throwable) {
    $atomicFailed = true;
}
expectModuleSchemaMigrator($atomicFailed, 'Ledger insertion failure must fail the migration');
expectModuleSchemaMigrator(
    $atomicConnection->fetchAll(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='atomic_fragment'",
        PDO::FETCH_ASSOC
    ) === [],
    'Schema operation must roll back when its ledger insertion fails'
);

$interruptedConnection = newModuleSchemaMigratorConnection();
$interruptedConnection->execute('CREATE TABLE legacy(id INTEGER PRIMARY KEY)');
$failOnce = true;
$resumable = new ModuleSchemaMigrator([
    new ModuleSchemaMigration(
        1,
        'first completed step',
        static fn (mixed $database): bool => $database->execute('CREATE TABLE first_step(id INTEGER)'),
        'first-v1'
    ),
    new ModuleSchemaMigration(
        2,
        'retry interrupted step',
        static function (mixed $database) use (&$failOnce): void {
            $database->execute('CREATE TABLE interrupted_step(id INTEGER)');
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('simulated interruption');
            }
        },
        'interrupted-v1'
    ),
]);

try {
    $resumable->migrate($interruptedConnection, static fn (): null => null);
} catch (RuntimeException $exception) {
    expectModuleSchemaMigrator(
        $exception->getMessage() === 'simulated interruption',
        'Unexpected interruption failure'
    );
}
expectModuleSchemaMigrator(
    $interruptedConnection->fetchAll(
        "SELECT name FROM sqlite_master WHERE type='table' AND name='interrupted_step'",
        PDO::FETCH_ASSOC
    ) === [],
    'Interrupted migration schema must roll back'
);
expectModuleSchemaMigrator(
    $resumable->migrate($interruptedConnection, static fn (): null => null) === [2],
    'Rerun must resume at the first incomplete migration'
);

$legacyFixture = file_get_contents(__DIR__ . '/fixtures/schema/legacy-current.sql');
expectModuleSchemaMigrator($legacyFixture !== false, 'Cannot read current unversioned schema fixture');
$legacyPdo = new PDO('sqlite::memory:');
$legacyPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacyPdo->exec($legacyFixture);
$legacyConnection = new ModuleSchemaMigratorConnection($legacyPdo);
$legacyFreshCalls = 0;
$legacyMigrator = new ModuleSchemaMigrator([
    new ModuleSchemaMigration(
        1,
        'adopt unversioned database',
        static fn (mixed $database): bool => $database->execute(
            'CREATE TABLE migration_canary(id INTEGER PRIMARY KEY)'
        ),
        'legacy-adoption-v1'
    ),
]);
expectModuleSchemaMigrator(
    $legacyMigrator->migrate(
        $legacyConnection,
        static function () use (&$legacyFreshCalls): void {
            $legacyFreshCalls++;
        }
    ) === [1],
    'Current unversioned schema must be adopted by the migration ledger'
);
expectModuleSchemaMigrator($legacyFreshCalls === 0, 'Legacy current schema must not run fresh annotation bootstrap');
expectModuleSchemaMigrator(
    (int)$legacyPdo->query('SELECT COUNT(*) FROM m_STTResult')->fetchColumn() === 1
        && (string)$legacyPdo->query("SELECT value FROM m_STTSetting WHERE key='poll_interval_sec'")->fetchColumn() === '17',
    'Migration ledger adoption must preserve legacy rows and custom settings'
);

$setupSource = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
expectModuleSchemaMigrator($setupSource !== false, 'Cannot inspect setup migration integration');
expectModuleSchemaMigrator(
    str_contains($setupSource, 'new ModuleSchemaMigrator(')
        && str_contains($setupSource, 'new ModuleSchemaMigration(')
        && str_contains($setupSource, 'moduleSchemaMigrations()'),
    'Setup must apply an explicit ordered STT migration list'
);
expectModuleSchemaMigrator(
    !str_contains($setupSource, 'parent::installDB()'),
    'Existing STT databases must not be mutated by generic Core annotation migration'
);
expectModuleSchemaMigrator(
    str_contains($setupSource, 'createSettingsTableByModelsAnnotations()')
        && str_contains($setupSource, 'isFreshModuleDatabase('),
    'Annotation bootstrap must be reserved for a fresh STT database'
);

echo "Module schema migrator behavior OK\n";
