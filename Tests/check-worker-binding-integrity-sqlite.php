<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigrator;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerBindingIntegrityMigration;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigration.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigrator.php';
if (!class_exists('MikoPBX\\Modules\\Models\\ModulesModelsBase')) {
    eval('namespace MikoPBX\\Modules\\Models; abstract class ModulesModelsBase {}');
}
require_once __DIR__ . '/../Models/STTSetting.php';

function expectWorkerBindingIntegrity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class WorkerBindingSqliteConnection
{
    public bool $failLedger = false;

    public function __construct(public readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        if ($this->failLedger
            && str_starts_with(ltrim($sql), 'INSERT INTO m_STTSchemaMigration')
            && (int)($bind['version'] ?? 0) === 12) {
            $this->failLedger = false;
            throw new RuntimeException('injected worker binding ledger failure');
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

    public function begin(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return $this->pdo->rollBack(); }
    public function isUnderTransaction(): bool { return $this->pdo->inTransaction(); }
}

function workerBindingDatabase(bool $duplicates = false): WorkerBindingSqliteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE m_STTWorker (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        worker_uid TEXT NOT NULL UNIQUE,
        api_key_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT \'offline\',
        api_version INTEGER NOT NULL DEFAULT 1,
        app_version TEXT NOT NULL DEFAULT \'\',
        updated_at INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE m_STTSetting (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT NOT NULL UNIQUE,
        value TEXT NULL
    )');
    $pdo->exec("INSERT INTO m_STTSetting(key, value) VALUES
        ('worker_api_key_id', '77'),
        ('worker_api_key_ids', '[77,42]'),
        ('worker_api_contract_migration_v2', '1')");
    $pdo->exec("INSERT INTO m_STTWorker(worker_uid, api_key_id, status, api_version, app_version, updated_at)
        VALUES ('worker-a', 41, 'offline', 1, '', 1700000001),
               ('worker-b', 42, 'offline', 1, '', 1700000002)");
    if ($duplicates) {
        $pdo->exec("INSERT INTO m_STTWorker(worker_uid, api_key_id, status, api_version, app_version, updated_at)
            VALUES ('worker-duplicate', 41, 'offline', 1, '', 1700000003)");
    }

    return new WorkerBindingSqliteConnection($pdo);
}

function workerBindingMigrator(): ModuleSchemaMigrator
{
    return new ModuleSchemaMigrator([
        new ModuleSchemaMigration(
            12,
            'exclusive worker API-key bindings',
            static function (mixed $connection): void {
                (new WorkerBindingIntegrityMigration())->migrate($connection);
            },
            'stt-schema-012-exclusive-worker-api-key-bindings'
        ),
        new ModuleSchemaMigration(
            13,
            'repair v2-only worker API-key binding index',
            static function (mixed $connection): void {
                (new WorkerBindingIntegrityMigration())->migrate($connection);
            },
            'stt-schema-013-repair-v2-worker-api-key-index'
        ),
    ]);
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for worker binding integrity tests\n");
    exit(1);
}

$migrationPath = __DIR__ . '/../Lib/Services/WorkerBindingIntegrityMigration.php';
expectWorkerBindingIntegrity(is_file($migrationPath), 'WorkerBindingIntegrityMigration implementation is missing');
require_once $migrationPath;

$connection = workerBindingDatabase();
expectWorkerBindingIntegrity(
    workerBindingMigrator()->migrate($connection, static fn (): null => null) === [12, 13],
    'Production binding migrations 12-13 did not apply'
);
$index = $connection->pdo->query(
    "SELECT sql FROM sqlite_master WHERE type='index' AND name='m_STTWorker_sqlite_autoindex_api_key_id_v2_unique'"
)->fetchColumn();
expectWorkerBindingIntegrity(
    is_string($index)
        && str_contains(strtoupper($index), 'UNIQUE INDEX')
        && preg_match('/WHERE\s+api_key_id\s+IS\s+NOT\s+NULL\s+AND\s+api_version\s*=\s*2/i', $index) === 1,
    'api_key_id must have a partial unique v2-only database invariant'
);
$history = json_decode((string)$connection->pdo->query(
    "SELECT value FROM m_STTSetting WHERE key='worker_api_key_history_ids'"
)->fetchColumn(), true);
sort($history);
expectWorkerBindingIntegrity($history === [41, 42, 77], 'Migration must preserve current and row-bound worker key history');

$connection->pdo->exec(
    "INSERT INTO m_STTWorker(worker_uid, api_key_id, status, api_version)
     VALUES ('worker-v2-owner', 91, 'online', 2)"
);
$beforeRows = (int)$connection->pdo->query('SELECT COUNT(*) FROM m_STTWorker')->fetchColumn();
$collisionRejected = false;
try {
    $connection->pdo->exec(
        "INSERT INTO m_STTWorker(worker_uid, api_key_id, status, api_version)
         VALUES ('worker-race-loser', 91, 'online', 2)"
    );
} catch (PDOException) {
    $collisionRejected = true;
}
expectWorkerBindingIntegrity($collisionRejected, 'Database boundary accepted two v2 worker UIDs for one API key');
expectWorkerBindingIntegrity(
    (int)$connection->pdo->query('SELECT COUNT(*) FROM m_STTWorker')->fetchColumn() === $beforeRows,
    'Rejected binding collision mutated worker rows'
);
$hashBeforeRepeat = hash('sha256', serialize($connection->pdo->query(
    "SELECT type, name, sql FROM sqlite_master UNION ALL SELECT 'setting', key, value FROM m_STTSetting ORDER BY 1, 2"
)->fetchAll(PDO::FETCH_ASSOC)));
expectWorkerBindingIntegrity(
    workerBindingMigrator()->migrate($connection, static fn (): null => null) === [],
    'Repeated binding migration must be a no-op'
);
$hashAfterRepeat = hash('sha256', serialize($connection->pdo->query(
    "SELECT type, name, sql FROM sqlite_master UNION ALL SELECT 'setting', key, value FROM m_STTSetting ORDER BY 1, 2"
)->fetchAll(PDO::FETCH_ASSOC)));
expectWorkerBindingIntegrity($hashBeforeRepeat === $hashAfterRepeat, 'Repeated migration changed schema or settings');

$duplicateConnection = workerBindingDatabase(true);
$duplicateRows = $duplicateConnection->pdo->query(
    'SELECT id, worker_uid, api_key_id, status, api_version, app_version, updated_at
       FROM m_STTWorker ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
expectWorkerBindingIntegrity(
    workerBindingMigrator()->migrate($duplicateConnection, static fn (): null => null) === [12, 13],
    'Duplicate legacy v1 bindings must migrate successfully'
);
expectWorkerBindingIntegrity(
    $duplicateConnection->pdo->query(
        'SELECT id, worker_uid, api_key_id, status, api_version, app_version, updated_at
           FROM m_STTWorker ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC)
        === $duplicateRows,
    'Migration changed duplicate legacy IDs, UIDs, bindings, v1/offline state, or metadata'
);
expectWorkerBindingIntegrity(
    json_decode((string)$duplicateConnection->pdo->query(
        "SELECT value FROM m_STTSetting WHERE key='worker_api_key_history_ids'"
    )->fetchColumn(), true) === [41, 42, 77],
    'Duplicate legacy migration did not seed complete worker API-key history'
);
expectWorkerBindingIntegrity(
    (string)$duplicateConnection->pdo->query(
        "SELECT value FROM m_STTSetting WHERE key='worker_api_contract_migration_v2'"
    )->fetchColumn() === '1',
    'Duplicate legacy migration changed the worker API v2 marker'
);
expectWorkerBindingIntegrity(
    (int)$duplicateConnection->pdo->query(
        "SELECT COUNT(*) FROM m_STTSchemaMigration WHERE version IN (12, 13)"
    )->fetchColumn() === 2,
    'Duplicate legacy migration did not record both schema ledger entries'
);
$duplicateV2Rejected = false;
try {
    $duplicateConnection->pdo->exec(
        "INSERT INTO m_STTWorker(worker_uid, api_key_id, status, api_version)
         VALUES ('v2-first', 100, 'online', 2), ('v2-second', 100, 'online', 2)"
    );
} catch (PDOException) {
    $duplicateV2Rejected = true;
}
expectWorkerBindingIntegrity($duplicateV2Rejected, 'Unique v2 duplicate insertion must fail');
$duplicateHash = hash('sha256', serialize($duplicateConnection->pdo->query(
    'SELECT * FROM m_STTWorker ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC)));
expectWorkerBindingIntegrity(
    workerBindingMigrator()->migrate($duplicateConnection, static fn (): null => null) === [],
    'Repeated duplicate-legacy migration must be a no-op'
);
expectWorkerBindingIntegrity(
    hash('sha256', serialize($duplicateConnection->pdo->query(
        'SELECT * FROM m_STTWorker ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC))) === $duplicateHash,
    'Repeated setup changed preserved duplicate legacy workers'
);

$rollbackConnection = workerBindingDatabase();
$rollbackRows = $rollbackConnection->pdo->query(
    'SELECT * FROM m_STTWorker ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$rollbackConnection->failLedger = true;
$rollbackObserved = false;
try {
    workerBindingMigrator()->migrate($rollbackConnection, static fn (): null => null);
} catch (RuntimeException $exception) {
    $rollbackObserved = $exception->getMessage() === 'injected worker binding ledger failure';
}
expectWorkerBindingIntegrity($rollbackObserved, 'Injected migration ledger failure was not observed');
expectWorkerBindingIntegrity(
    (int)$rollbackConnection->pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='m_STTWorker_sqlite_autoindex_api_key_id_v2_unique'"
    )->fetchColumn() === 0
        && (int)$rollbackConnection->pdo->query(
            "SELECT COUNT(*) FROM m_STTSetting WHERE key='worker_api_key_history_ids'"
        )->fetchColumn() === 0
        && $rollbackConnection->pdo->query(
            'SELECT * FROM m_STTWorker ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) === $rollbackRows
        && (int)$rollbackConnection->pdo->query(
            'SELECT COUNT(*) FROM m_STTSchemaMigration'
        )->fetchColumn() === 0,
    'Migration ledger failure did not roll back schema and history changes'
);

$staleConnection = workerBindingDatabase();
$staleConnection->pdo->exec(
    'CREATE UNIQUE INDEX i_m_STTWorker_api_key_id_unique
         ON m_STTWorker(api_key_id) WHERE api_key_id IS NOT NULL'
);
$staleConnection->pdo->exec('CREATE TABLE m_STTSchemaMigration (
    version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL, applied_at INTEGER NOT NULL
)');
$staleStatement = $staleConnection->pdo->prepare(
    'INSERT INTO m_STTSchemaMigration(version, name, checksum, applied_at) VALUES (12, :name, :checksum, 1)'
);
$staleStatement->execute([
    'name' => 'exclusive worker API-key bindings',
    'checksum' => hash('sha256', 'stt-schema-012-exclusive-worker-api-key-bindings'),
]);
expectWorkerBindingIntegrity(
    workerBindingMigrator()->migrate($staleConnection, static fn (): null => null) === [13],
    'Already-applied unshipped v12 index must be repaired deterministically by migration 13'
);
expectWorkerBindingIntegrity(
    (int)$staleConnection->pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='i_m_STTWorker_api_key_id_unique'"
    )->fetchColumn() === 0
        && (int)$staleConnection->pdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='m_STTWorker_sqlite_autoindex_api_key_id_v2_unique'"
        )->fetchColumn() === 1,
    'Stale non-null unique index was not replaced by the v2-only index'
);

$setup = (string)file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
$model = (string)file_get_contents(__DIR__ . '/../Models/STTWorker.php');
expectWorkerBindingIntegrity(
    str_contains($setup, "12,\n                'exclusive worker API-key bindings'")
        && str_contains($setup, 'applyWorkerBindingIntegrityMigration')
        && str_contains($setup, "'stt-schema-012-exclusive-worker-api-key-bindings'"),
    'Production setup must register migration 12 with stable identity'
);
expectWorkerBindingIntegrity(
    str_contains($setup, "13,\n                'repair v2-only worker API-key binding index'")
        && str_contains($setup, "'stt-schema-013-repair-v2-worker-api-key-index'"),
    'Production setup must register deterministic repair migration 13'
);
expectWorkerBindingIntegrity(
    !str_contains($model, "columns=['api_key_id'], type='UNIQUE'"),
    'Model annotations must not recreate an all-version unique API-key constraint'
);

echo "STT worker binding integrity SQLite behavior OK\n";
