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
        api_key_id INTEGER NULL
    )');
    $pdo->exec('CREATE TABLE m_STTSetting (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT NOT NULL UNIQUE,
        value TEXT NULL
    )');
    $pdo->exec("INSERT INTO m_STTSetting(key, value) VALUES
        ('worker_api_key_id', '77'),
        ('worker_api_key_ids', '[77,42]')");
    $pdo->exec("INSERT INTO m_STTWorker(worker_uid, api_key_id) VALUES ('worker-a', 41), ('worker-b', 42)");
    if ($duplicates) {
        $pdo->exec("INSERT INTO m_STTWorker(worker_uid, api_key_id) VALUES ('worker-duplicate', 41)");
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
    workerBindingMigrator()->migrate($connection, static fn (): null => null) === [12],
    'Production binding migration version 12 did not apply'
);
$index = $connection->pdo->query(
    "SELECT sql FROM sqlite_master WHERE type='index' AND name='i_m_STTWorker_api_key_id_unique'"
)->fetchColumn();
expectWorkerBindingIntegrity(
    is_string($index)
        && str_contains(strtoupper($index), 'UNIQUE INDEX')
        && str_contains($index, 'WHERE api_key_id IS NOT NULL'),
    'api_key_id must have a partial unique non-null database invariant'
);
$history = json_decode((string)$connection->pdo->query(
    "SELECT value FROM m_STTSetting WHERE key='worker_api_key_history_ids'"
)->fetchColumn(), true);
sort($history);
expectWorkerBindingIntegrity($history === [41, 42, 77], 'Migration must preserve current and row-bound worker key history');

$beforeRows = (int)$connection->pdo->query('SELECT COUNT(*) FROM m_STTWorker')->fetchColumn();
$collisionRejected = false;
try {
    $connection->pdo->exec("INSERT INTO m_STTWorker(worker_uid, api_key_id) VALUES ('worker-race-loser', 41)");
} catch (PDOException) {
    $collisionRejected = true;
}
expectWorkerBindingIntegrity($collisionRejected, 'Database boundary accepted two worker UIDs for one API key');
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
    'SELECT id, worker_uid, api_key_id FROM m_STTWorker ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$duplicateRejected = false;
try {
    workerBindingMigrator()->migrate($duplicateConnection, static fn (): null => null);
} catch (RuntimeException $exception) {
    $duplicateRejected = str_contains($exception->getMessage(), 'duplicate worker API-key binding');
}
expectWorkerBindingIntegrity($duplicateRejected, 'Legacy duplicate bindings must fail migration explicitly');
expectWorkerBindingIntegrity(
    $duplicateConnection->pdo->query('SELECT id, worker_uid, api_key_id FROM m_STTWorker ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)
        === $duplicateRows,
    'Duplicate-binding migration failure changed legacy worker rows'
);
expectWorkerBindingIntegrity(
    (int)$duplicateConnection->pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='i_m_STTWorker_api_key_id_unique'"
    )->fetchColumn() === 0,
    'Duplicate-binding failure left a partial unique index behind'
);
expectWorkerBindingIntegrity(
    (int)$duplicateConnection->pdo->query(
        "SELECT COUNT(*) FROM m_STTSchemaMigration WHERE version=12"
    )->fetchColumn() === 0,
    'Duplicate-binding failure recorded schema version 12'
);

$rollbackConnection = workerBindingDatabase();
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
        "SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name='i_m_STTWorker_api_key_id_unique'"
    )->fetchColumn() === 0
        && (int)$rollbackConnection->pdo->query(
            "SELECT COUNT(*) FROM m_STTSetting WHERE key='worker_api_key_history_ids'"
        )->fetchColumn() === 0,
    'Migration ledger failure did not roll back schema and history changes'
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
    str_contains($model, "[name='api_key_id_unique', columns=['api_key_id'], type='UNIQUE']"),
    'Fresh annotation schema must declare the same unique API-key invariant'
);

echo "STT worker binding integrity SQLite behavior OK\n";
