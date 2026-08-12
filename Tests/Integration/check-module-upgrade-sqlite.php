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
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseBackup;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigrator;
use Modules\ModuleLocalSpeechToText\Setup\PbxExtensionSetup;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTTranscriptEvent;
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

function sqliteColumnExists(SQLite3 $database, string $table, string $column): bool
{
    foreach (sqliteRows($database, 'PRAGMA table_info("' . str_replace('"', '""', $table) . '")') as $row) {
        if (($row['name'] ?? null) === $column) {
            return true;
        }
    }

    return false;
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

    $workerVersionFields = sqliteColumnExists($database, 'm_STTWorker', 'api_version')
        ? 'api_version, app_version, updated_at'
        : "1 AS api_version, '' AS app_version, 0 AS updated_at";
    $hashQueries = [
        'jobs' => 'SELECT id, call_id, file_path, status, worker_id, attempts, created_at, updated_at,
                          started_at, lease_token, lease_expires_at, finished_at, error_msg
                     FROM m_STTJob ORDER BY id',
        'results' => 'SELECT id, job_id, transcript, language, duration, model_used, technical_info, created_at
                        FROM m_STTResult ORDER BY id',
        'workers' => 'SELECT id, worker_uid, name, ip, status, model, api_key_id, last_seen, created_at,
                            has_settings_changed, ' . $workerVersionFields . '
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
    $dataRows = [
        'jobs' => sqliteRows($database, $hashQueries['jobs']),
        'workers' => sqliteRows($database, $hashQueries['workers']),
    ];

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
        'rows' => $dataRows,
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
expectSttUpgrade($applied === [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 'legacy database did not apply migrations 1-12');

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
    $expectedCount = $table === 'm_STTSetting' ? $count + 4 : $count;
    expectSttUpgrade($after['counts'][$table] === $expectedCount, "unexpected row count for {$table}");
}
foreach ($before['hashes'] as $name => $hash) {
    if (in_array($name, ['settings', 'workers', 'jobs'], true)) {
        continue;
    }
    expectSttUpgrade($after['hashes'][$name] === $hash, "existing {$name} data changed");
}

$beforeWorkers = [];
foreach ($before['rows']['workers'] as $worker) {
    $beforeWorkers[(int)$worker['id']] = $worker;
}
$afterWorkers = [];
foreach ($after['rows']['workers'] as $worker) {
    $afterWorkers[(int)$worker['id']] = $worker;
}
foreach ($beforeWorkers as $workerId => $workerBefore) {
    $workerAfter = $afterWorkers[$workerId] ?? null;
    expectSttUpgrade(is_array($workerAfter), "worker {$workerId} disappeared during migration");
    if ((int)$workerBefore['api_version'] >= 2) {
        expectSttUpgrade($workerAfter === $workerBefore, "already-v2 worker {$workerId} changed");
        continue;
    }

    $expectedWorker = $workerBefore;
    $expectedWorker['api_version'] = 1;
    $expectedWorker['status'] = 'offline';
    $expectedWorker['has_settings_changed'] = 1;
    if ((int)$workerBefore['updated_at'] === 0) {
        expectSttUpgrade((int)$workerAfter['updated_at'] > 0, "legacy worker {$workerId} timestamp was not populated");
        $expectedWorker['updated_at'] = $workerAfter['updated_at'];
    }
    expectSttUpgrade($workerAfter === $expectedWorker, "legacy worker {$workerId} changed outside the v2 contract");
}

$afterJobs = [];
foreach ($after['rows']['jobs'] as $job) {
    $afterJobs[(int)$job['id']] = $job;
}
foreach ($before['rows']['jobs'] as $jobBefore) {
    $jobId = (int)$jobBefore['id'];
    $jobAfter = $afterJobs[$jobId] ?? null;
    expectSttUpgrade(is_array($jobAfter), "job {$jobId} disappeared during migration");
    $workerId = $jobBefore['worker_id'] === null ? null : (int)$jobBefore['worker_id'];
    $legacyLease = $workerId === null
        || (isset($beforeWorkers[$workerId]) && (int)$beforeWorkers[$workerId]['api_version'] < 2);
    if ($jobBefore['status'] !== 'processing' || !$legacyLease) {
        expectSttUpgrade($jobAfter === $jobBefore, "terminal or v2-owned job {$jobId} changed");
        continue;
    }

    $expectedJob = $jobBefore;
    $expectedJob['status'] = 'pending';
    $expectedJob['worker_id'] = null;
    $expectedJob['started_at'] = null;
    $expectedJob['lease_token'] = null;
    $expectedJob['lease_expires_at'] = null;
    $expectedJob['error_msg'] = 'worker_api_migration';
    $expectedJob['updated_at'] = $jobAfter['updated_at'];
    expectSttUpgrade((int)$jobAfter['updated_at'] > 0, "legacy job {$jobId} timestamp was not populated");
    expectSttUpgrade($jobAfter === $expectedJob, "legacy processing job {$jobId} changed outside requeue fields");
}
expectSttUpgrade(
    array_map(static fn (array $row): int => (int)$row['version'], $after['migrations']) === [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
    'migration ledger is incomplete'
);
expectSttUpgrade(
    json_decode((string)sqliteRows(
        new SQLite3($resolvedDatabase, SQLITE3_OPEN_READONLY),
        "SELECT value FROM m_STTSetting WHERE key='worker_api_key_history_ids'"
    )[0]['value'], true) === [41],
    'worker API-key history was not preserved'
);
expectSttUpgrade(
    sqliteRows(
        new SQLite3($resolvedDatabase, SQLITE3_OPEN_READONLY),
        "SELECT key, value FROM m_STTSetting
          WHERE key IN ('cdr_history_days', 'transcript_retention_days', 'transcript_retention_last_cleanup_at')
          ORDER BY key"
    ) === [
        ['key' => 'cdr_history_days', 'value' => '365'],
        ['key' => 'transcript_retention_days', 'value' => '365'],
        ['key' => 'transcript_retention_last_cleanup_at', 'value' => '0'],
    ],
    'transcript retention settings were not installed and normalized'
);
expectSttUpgrade(
    in_array('m_STTResult_created_at', $after['indexes'], true),
    'result processing timestamp index is missing after upgrade'
);
expectSttUpgrade(
    in_array('i_m_STTWorker_api_key_id_unique', $after['indexes'], true),
    'exclusive worker API-key index is missing after upgrade'
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

$publicationCandidates = $moduleDatabase->fetchAll(
    'SELECT c.id
       FROM m_STTCallTranscript c
      WHERE NOT EXISTS (
          SELECT 1 FROM m_STTJob j
           WHERE j.call_id = c.call_id
             AND (j.status = :pending OR j.status = :processing)
      )
      ORDER BY c.id
      LIMIT 1',
    PDO::FETCH_ASSOC,
    ['pending' => 'pending', 'processing' => 'processing']
);
expectSttUpgrade($publicationCandidates !== [], 'no terminal call transcript is available for cursor rehearsal');
$publicationCallId = (int)$publicationCandidates[0]['id'];
$publicationHash = hash('sha256', 'stt-upgrade-cursor-rehearsal-' . $publicationCallId);
$callEventFrontier = time() + 3600;
expectSttUpgrade($moduleDatabase->begin(), 'unable to start call event cursor rehearsal');
try {
    expectSttUpgrade(
        $moduleDatabase->execute(
            "UPDATE m_STTCallTranscript
                SET pending_content_hash = '', pending_parts_hash = '', pending_payload = '', stable_after = 0"
        ),
        'unable to isolate runtime publication candidate'
    );
    expectSttUpgrade(
        $moduleDatabase->execute(
            'UPDATE m_STTCallTranscriptEvent
                SET created_at = :frontier
              WHERE id = (
                  SELECT id FROM m_STTCallTranscriptEvent
                   ORDER BY CAST(created_at AS INTEGER) DESC, CAST(id AS INTEGER) DESC LIMIT 1
              )',
            ['frontier' => $callEventFrontier]
        ),
        'unable to create future call event cursor frontier'
    );
    expectSttUpgrade(
        $moduleDatabase->execute(
            'UPDATE m_STTCallTranscript
                SET pending_content_hash = :content_hash,
                    pending_parts_hash = :parts_hash,
                    pending_payload = :payload,
                    completeness_status = :status,
                    stable_after = 0
              WHERE id = :id',
            [
                'content_hash' => $publicationHash,
                'parts_hash' => hash('sha256', 'parts-' . $publicationCallId),
                'payload' => '{}',
                'status' => 'complete',
                'id' => $publicationCallId,
            ]
        ),
        'unable to stage call event cursor rehearsal'
    );
    $publicationSummary = (new CallTranscriptRevisionService())->publishReady();
    expectSttUpgrade($publicationSummary['published'] >= 1, 'eligible call publications were not drained');
    $cursorRows = $moduleDatabase->fetchAll(
        'SELECT created_at FROM m_STTCallTranscriptEvent WHERE content_hash = :content_hash',
        PDO::FETCH_ASSOC,
        ['content_hash' => $publicationHash]
    );
    expectSttUpgrade($cursorRows !== [], 'cursor rehearsal event was not published');
    expectSttUpgrade(
        (int)$cursorRows[0]['created_at'] >= $callEventFrontier,
        'new call event was published behind the existing cursor frontier'
    );
} finally {
    expectSttUpgrade($moduleDatabase->rollback(), 'unable to roll back call event cursor rehearsal');
}
$metadata->reset();
expectSttUpgrade(sttUpgradeState($resolvedDatabase) === $after, 'call event cursor rehearsal did not roll back');

$resultEventCandidate = (int)$moduleDatabase->fetchColumn(
    'SELECT MIN(result_id) FROM m_STTTranscriptEvent WHERE result_id IS NOT NULL'
);
expectSttUpgrade($resultEventCandidate > 0, 'no result event is available for cursor rehearsal');
$resultEventFrontier = time() + 3600;
expectSttUpgrade($moduleDatabase->begin(), 'unable to start result event cursor rehearsal');
try {
    expectSttUpgrade(
        $moduleDatabase->execute(
            'DELETE FROM m_STTTranscriptEvent WHERE result_id = :result_id',
            ['result_id' => $resultEventCandidate]
        ),
        'unable to remove result event cursor rehearsal candidate'
    );
    expectSttUpgrade(
        $moduleDatabase->execute(
            'UPDATE m_STTTranscriptEvent
                SET created_at = :frontier
              WHERE id = (
                  SELECT id FROM m_STTTranscriptEvent
                   ORDER BY CAST(created_at AS INTEGER) DESC, CAST(id AS INTEGER) DESC LIMIT 1
              )',
            ['frontier' => $resultEventFrontier]
        ),
        'unable to create future result event cursor frontier'
    );
    $result = STTResult::findFirstById($resultEventCandidate);
    expectSttUpgrade($result instanceof STTResult, 'unable to load result event cursor rehearsal candidate');
    $event = STTTranscriptEvent::ensureCompletedForResult($result);
    expectSttUpgrade(
        (int)$event->created_at >= $resultEventFrontier,
        'new result event was published behind the existing cursor frontier'
    );
} finally {
    expectSttUpgrade($moduleDatabase->rollback(), 'unable to roll back result event cursor rehearsal');
}
$metadata->reset();
expectSttUpgrade(sttUpgradeState($resolvedDatabase) === $after, 'result event cursor rehearsal did not roll back');

expectSttUpgrade(applyProductionSttMigrations($moduleDatabase) === [], 'same-version migration was not a no-op');
$pragmas->checkpoint($moduleDatabase);
$afterSecondRun = sttUpgradeState($resolvedDatabase);
expectSttUpgrade($afterSecondRun === $after, 'same-version reinstall changed schema or data');

$failingMigrations = productionSttMigrations();
$failingMigrations[] = new ModuleSchemaMigration(
    12,
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
