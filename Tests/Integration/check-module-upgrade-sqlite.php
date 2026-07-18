<?php

declare(strict_types=1);

if (!extension_loaded('phalcon')) {
    echo "SKIP: ext-phalcon is required for the production-shaped STT upgrade rehearsal\n";
    exit(0);
}

$databasePath = (string)getenv('STT_UPGRADE_DATABASE');
$moduleRoot = rtrim((string)(getenv('MIKOPBX_MODULE_ROOT') ?: dirname(__DIR__, 2)), '/');
$coreRoot = rtrim((string)(getenv('MIKOPBX_CORE_ROOT') ?: '/usr/www'), '/');
$autoload = $coreRoot . '/vendor/autoload.php';

if ($databasePath === '') {
    echo "SKIP: STT_UPGRADE_DATABASE must explicitly point to an isolated database copy\n";
    exit(0);
}

function failSttUpgrade(string $message): never
{
    fwrite(STDERR, "STT upgrade rehearsal failed: {$message}\n");
    exit(1);
}

function expectSttUpgrade(bool $condition, string $message): void
{
    if (!$condition) {
        failSttUpgrade($message);
    }
}

expectSttUpgrade(is_file($databasePath), 'STT_UPGRADE_DATABASE must point to a copied database');
$resolvedDatabase = realpath($databasePath);
$temporaryRoot = rtrim((string)realpath(sys_get_temp_dir()), '/') . '/';
expectSttUpgrade(
    is_string($resolvedDatabase) && str_starts_with($resolvedDatabase, $temporaryRoot),
    'The rehearsal refuses to modify a database outside the system temporary directory'
);
expectSttUpgrade(is_file($autoload), 'MikoPBX Composer autoload is unavailable');
expectSttUpgrade(is_file($moduleRoot . '/Setup/PbxExtensionSetup.php'), 'STT module source is unavailable');

require $autoload;
require __DIR__ . '/Support/IsolatedModulesModelsBase.php';

spl_autoload_register(
    static function (string $class) use ($moduleRoot): void {
        $prefix = 'Modules\\ModuleLocalSpeechToText\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $moduleRoot . '/' . $relative . '.php';
        if (is_file($path)) {
            require $path;
        }
    },
    true,
    true
);

use MikoPBX\Modules\Models\ModulesModelsBase;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseBackup;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigrator;
use Modules\ModuleLocalSpeechToText\Setup\PbxExtensionSetup;
use Phalcon\Annotations\Adapter\Memory as AnnotationMemory;
use Phalcon\Config\Config;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use Phalcon\Di\Di;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Model\MetaData\Memory as MetadataMemory;
use Phalcon\Mvc\Model\MetaData\Strategy\Annotations;

/** @return array<int, array<string, int|string|null>> */
function sqliteRows(SQLite3 $database, string $sql): array
{
    $result = $database->query($sql);
    if (!$result instanceof SQLite3Result) {
        throw new RuntimeException('Unable to query upgrade state');
    }
    $rows = [];
    while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        ksort($row);
        $rows[] = $row;
    }
    $result->finalize();

    return $rows;
}

function sqliteTableExists(SQLite3 $database, string $table): bool
{
    $statement = $database->prepare(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    $statement->bindValue(':name', $table, SQLITE3_TEXT);
    $result = $statement->execute();
    $row = $result?->fetchArray(SQLITE3_NUM);
    $result?->finalize();

    return (int)($row[0] ?? 0) === 1;
}

/** @return array<string, mixed> */
function sttUpgradeState(string $path): array
{
    $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $database->enableExceptions(true);
    $tables = [
        'm_STTJob',
        'm_STTResult',
        'm_STTTranscriptEvent',
        'm_STTWorker',
        'm_STTSetting',
        'm_STTRetryState',
        'm_STTCallTranscript',
        'm_STTCallTranscriptPart',
        'm_STTCallTranscriptRevision',
        'm_STTCallTranscriptEvent',
    ];
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = sqliteTableExists($database, $table)
            ? (int)$database->querySingle('SELECT COUNT(*) FROM "' . $table . '"')
            : -1;
    }

    $hashQueries = [
        'jobs' => 'SELECT id, call_id, file_path, status, worker_id, attempts, created_at, updated_at,
                          started_at, lease_token, lease_expires_at, finished_at, error_msg
                     FROM m_STTJob ORDER BY id',
        'results' => 'SELECT id, job_id, transcript, language, duration, model_used, technical_info, created_at
                        FROM m_STTResult ORDER BY id',
        'workers' => 'SELECT id, worker_uid, name, ip, status, model, api_key_id, last_seen, created_at,
                            has_settings_changed
                       FROM m_STTWorker ORDER BY id',
        'settings' => 'SELECT key, value FROM m_STTSetting ORDER BY key',
        'events' => 'SELECT id, event_type, event_key, result_id, job_id, call_id, update_type, payload, created_at
                       FROM m_STTTranscriptEvent ORDER BY id',
        'call_transcripts' => 'SELECT * FROM m_STTCallTranscript ORDER BY id',
        'call_parts' => 'SELECT * FROM m_STTCallTranscriptPart ORDER BY id',
        'call_revisions' => 'SELECT * FROM m_STTCallTranscriptRevision ORDER BY id',
        'call_events' => 'SELECT * FROM m_STTCallTranscriptEvent ORDER BY id',
    ];
    $hashes = [];
    foreach ($hashQueries as $name => $sql) {
        $hashes[$name] = hash('sha256', json_encode(sqliteRows($database, $sql), JSON_THROW_ON_ERROR));
    }

    $schemaRows = sqliteRows(
        $database,
        "SELECT type, name, tbl_name, COALESCE(sql, '') AS sql
           FROM sqlite_master
          WHERE name NOT LIKE 'sqlite_%'
          ORDER BY type, name"
    );
    $migrations = sqliteTableExists($database, 'm_STTSchemaMigration')
        ? sqliteRows($database, 'SELECT version, name, checksum FROM m_STTSchemaMigration ORDER BY version')
        : [];
    $eventCount = (int)$database->querySingle('SELECT COUNT(*) FROM m_STTTranscriptEvent');
    $distinctEventKeys = (int)$database->querySingle(
        'SELECT COUNT(DISTINCT event_key) FROM m_STTTranscriptEvent'
    );
    $integrity = strtolower((string)$database->querySingle('PRAGMA integrity_check'));
    $journalMode = strtolower((string)$database->querySingle('PRAGMA journal_mode'));
    $database->close();

    return [
        'counts' => $counts,
        'hashes' => $hashes,
        'schema_hash' => hash('sha256', json_encode($schemaRows, JSON_THROW_ON_ERROR)),
        'migrations' => $migrations,
        'event_keys_unique' => $eventCount === $distinctEventKeys,
        'integrity' => $integrity,
        'journal_mode' => $journalMode,
        'indexes' => array_values(array_map(
            static fn (array $row): string => (string)$row['name'],
            array_filter($schemaRows, static fn (array $row): bool => $row['type'] === 'index')
        )),
    ];
}

/** @return array{0: FactoryDefault, 1: Sqlite, 2: MetadataMemory} */
function configureSttUpgradeDi(string $path): array
{
    Di::reset();
    $di = new FactoryDefault();
    Di::setDefault($di);

    $moduleDatabase = new Sqlite(['dbname' => $path]);
    $cdrDatabase = new Sqlite(['dbname' => ':memory:']);
    $cdrDatabase->execute(
        'CREATE TABLE cdr_general (
            id INTEGER PRIMARY KEY,
            linkedid TEXT,
            UNIQUEID TEXT,
            recordingfile TEXT,
            start TEXT,
            endtime TEXT
        )'
    );
    $metadata = new MetadataMemory();
    $metadata->setStrategy(new Annotations());

    $di->setShared(
        ModulesModelsBase::getConnectionServiceName('ModuleLocalSpeechToText'),
        $moduleDatabase
    );
    $di->setShared('dbCDR', $cdrDatabase);
    $di->setShared('db', $cdrDatabase);
    $di->setShared('modelsMetadata', $metadata);
    $di->setShared('annotations', new AnnotationMemory());
    $di->setShared('config', new Config([
        'core' => [
            'debugMode' => false,
            'tempDir' => sys_get_temp_dir(),
        ],
        'adminApplication' => ['baseUri' => '/'],
    ]));
    $di->setShared('logger', new class {
        public function log(int $level, string $message): void
        {
        }
    });

    return [$di, $moduleDatabase, $metadata];
}

/** @return array<int, ModuleSchemaMigration> */
function productionSttMigrations(): array
{
    $reflection = new ReflectionClass(PbxExtensionSetup::class);
    $setup = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('moduleSchemaMigrations');
    $method->setAccessible(true);
    $migrations = $method->invoke($setup);
    if (!is_array($migrations)) {
        throw new RuntimeException('Unable to resolve production STT migrations');
    }

    return $migrations;
}

function applyProductionSttMigrations(Sqlite $connection): array
{
    return (new ModuleSchemaMigrator(productionSttMigrations()))->migrate(
        $connection,
        static function (): never {
            throw new RuntimeException('Copied production database was unexpectedly empty');
        }
    );
}

$databaseDirectory = dirname($resolvedDatabase);
$before = sttUpgradeState($resolvedDatabase);
expectSttUpgrade($before['integrity'] === 'ok', 'source copy failed integrity check');

[, $moduleDatabase, $metadata] = configureSttUpgradeDi($resolvedDatabase);
$applied = applyProductionSttMigrations($moduleDatabase);
expectSttUpgrade($applied === [1, 2, 3, 4, 5, 6, 7], 'legacy database did not apply migrations 1-7');

$backup = new ModuleDatabaseBackup();
$backup->verify($resolvedDatabase);
$pragmas = new ModuleDatabasePragmas();
$pragmas->writeSafeBackupCapability($databaseDirectory);
expectSttUpgrade(
    $pragmas->enableWalAfterVerifiedSetup($moduleDatabase, $resolvedDatabase),
    'copied PBX database did not enable WAL on its temporary local filesystem'
);
$pragmas->checkpoint($moduleDatabase);
$metadata->reset();

$after = sttUpgradeState($resolvedDatabase);
expectSttUpgrade($after['integrity'] === 'ok', 'upgraded database failed integrity check');
expectSttUpgrade($after['journal_mode'] === 'wal', 'upgraded database is not in WAL mode');
expectSttUpgrade($after['event_keys_unique'], 'event keys lost uniqueness');
foreach ($before['counts'] as $table => $count) {
    expectSttUpgrade($after['counts'][$table] === $count, "row count changed for {$table}");
}
foreach ($before['hashes'] as $name => $hash) {
    expectSttUpgrade($after['hashes'][$name] === $hash, "existing {$name} data changed");
}
expectSttUpgrade(
    array_map(static fn (array $row): int => (int)$row['version'], $after['migrations']) === [1, 2, 3, 4, 5, 6, 7],
    'migration ledger is incomplete'
);
foreach ([
    'i_m_STTJob_file_path',
    'i_m_STTResult_job_id',
    'i_m_STTSetting_key',
    'i_m_STTWorker_worker_uid',
    'i_m_STTTranscriptEvent_event_key',
    'i_m_STTTranscriptEvent_event_cursor',
    'i_m_STTTranscriptEvent_result_id',
] as $redundantIndex) {
    expectSttUpgrade(!in_array($redundantIndex, $after['indexes'], true), "redundant index remains: {$redundantIndex}");
}

expectSttUpgrade(applyProductionSttMigrations($moduleDatabase) === [], 'same-version migration was not a no-op');
$pragmas->checkpoint($moduleDatabase);
$afterSecondRun = sttUpgradeState($resolvedDatabase);
expectSttUpgrade($afterSecondRun === $after, 'same-version reinstall changed schema or data');

$failingMigrations = productionSttMigrations();
$failingMigrations[] = new ModuleSchemaMigration(
    8,
    'injected rehearsal failure',
    static function (mixed $connection): void {
        $connection->execute('CREATE TABLE injected_partial_schema(id INTEGER PRIMARY KEY)');
        throw new RuntimeException('injected migration failure');
    },
    'integration-injected-failure'
);
$failureObserved = false;
try {
    (new ModuleSchemaMigrator($failingMigrations))->migrate(
        $moduleDatabase,
        static fn (): null => null
    );
} catch (RuntimeException $exception) {
    $failureObserved = $exception->getMessage() === 'injected migration failure';
}
expectSttUpgrade($failureObserved, 'injected migration failure was not observed');
expectSttUpgrade(
    sttUpgradeState($resolvedDatabase) === $after,
    'failed migration changed the copied database'
);

$verifiedSnapshot = dirname($databaseDirectory) . '/verified-after-upgrade';
$backup->snapshot($databaseDirectory, $verifiedSnapshot);
expectSttUpgrade($moduleDatabase->close() !== false, 'unable to close copied module database before restore rehearsal');

$damage = new SQLite3($resolvedDatabase, SQLITE3_OPEN_READWRITE);
$damage->exec('CREATE TABLE injected_setup_damage(id INTEGER PRIMARY KEY)');
$damage->close();
$backup->restore($verifiedSnapshot, $databaseDirectory);
$backup->verify($resolvedDatabase);

[, $restoredConnection] = configureSttUpgradeDi($resolvedDatabase);
expectSttUpgrade(applyProductionSttMigrations($restoredConnection) === [], 'restored snapshot reapplied completed migrations');
$pragmas->enableWalAfterVerifiedSetup($restoredConnection, $resolvedDatabase);
$pragmas->checkpoint($restoredConnection);
$restoredState = sttUpgradeState($resolvedDatabase);
expectSttUpgrade($restoredState === $after, 'verified snapshot restore did not reproduce upgraded state');
expectSttUpgrade($restoredConnection->close() !== false, 'unable to close restored copied database');

echo json_encode([
    'applied_migrations' => $applied,
    'counts' => $after['counts'],
    'journal_mode' => $after['journal_mode'],
    'integrity' => $after['integrity'],
    'same_version_noop' => true,
    'rollback_verified' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
