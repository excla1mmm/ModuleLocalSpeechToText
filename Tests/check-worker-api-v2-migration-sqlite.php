<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigrator;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiV2Migration;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigration.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigrator.php';
if (!class_exists('MikoPBX\\Modules\\Models\\ModulesModelsBase')) {
    eval('namespace MikoPBX\\Modules\\Models; abstract class ModulesModelsBase {}');
}
require_once __DIR__ . '/../Models/STTSetting.php';

function expectWorkerApiV2Migration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class WorkerApiV2SqliteConnection
{
    public bool $failBeforeMarker = false;
    public bool $workerUpdateExecuted = false;
    public bool $jobUpdateExecuted = false;
    public bool $markerAttempted = false;

    public function __construct(public readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        $normalizedSql = ltrim($sql);
        if (str_starts_with($normalizedSql, 'UPDATE m_STTWorker')) {
            $this->workerUpdateExecuted = true;
        }
        if (str_starts_with($normalizedSql, 'UPDATE m_STTJob')) {
            $this->jobUpdateExecuted = true;
        }
        if (
            $this->failBeforeMarker
            && ($bind['key'] ?? null) === STTSetting::WORKER_API_CONTRACT_MIGRATION_V2
        ) {
            if (!$this->workerUpdateExecuted || !$this->jobUpdateExecuted) {
                throw new RuntimeException('marker attempted before worker and job migration');
            }
            $this->markerAttempted = true;
            $this->failBeforeMarker = false;
            throw new RuntimeException('injected pre-marker failure');
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
}

function workerApiV2Database(): PDO
{
    $fixture = file_get_contents(__DIR__ . '/fixtures/schema/legacy-current.sql');
    expectWorkerApiV2Migration($fixture !== false, 'Cannot read the legacy schema fixture');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec($fixture);

    return $pdo;
}

/** @return array<int, array<string, mixed>> */
function workerApiV2Rows(PDO $pdo, string $sql): array
{
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        ksort($row);
    }
    unset($row);

    return $rows;
}

/** @return list<string> */
function workerApiV2Columns(PDO $pdo): array
{
    return array_values(array_map(
        static fn (array $row): string => (string)$row['name'],
        workerApiV2Rows($pdo, 'PRAGMA table_info("m_STTWorker")')
    ));
}

function workerApiV2PreservationHash(PDO $pdo): string
{
    $queries = [
        'results' => 'SELECT * FROM m_STTResult ORDER BY id',
        'transcript_events' => 'SELECT * FROM m_STTTranscriptEvent ORDER BY id',
        'call_transcripts' => 'SELECT * FROM m_STTCallTranscript ORDER BY id',
        'call_parts' => 'SELECT * FROM m_STTCallTranscriptPart ORDER BY id',
        'call_revisions' => 'SELECT * FROM m_STTCallTranscriptRevision ORDER BY id',
        'call_events' => 'SELECT * FROM m_STTCallTranscriptEvent ORDER BY id',
        'event_cursors' => "SELECT key, value FROM m_STTSetting WHERE key LIKE '%cursor%' ORDER BY key",
    ];
    $state = [];
    foreach ($queries as $name => $sql) {
        $state[$name] = workerApiV2Rows($pdo, $sql);
    }

    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}

function workerApiV2ModuleHash(PDO $pdo): string
{
    $tables = array_map(
        static fn (array $row): string => (string)$row['name'],
        workerApiV2Rows(
            $pdo,
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'm_STT%' ORDER BY name"
        )
    );
    $state = [];
    foreach ($tables as $table) {
        $quoted = str_replace('"', '""', $table);
        $state[$table] = workerApiV2Rows($pdo, 'SELECT * FROM "' . $quoted . '" ORDER BY rowid');
    }

    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}

function workerApiV2Migrator(): ModuleSchemaMigrator
{
    return new ModuleSchemaMigrator([
        new ModuleSchemaMigration(
            11,
            'worker API v2 contract',
            static function (mixed $connection): void {
                (new WorkerApiV2Migration())->migrate($connection);
            },
            'stt-schema-011-worker-api-v2'
        ),
    ]);
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for worker API v2 migration tests\n");
    exit(1);
}

$migrationPath = __DIR__ . '/../Lib/Services/WorkerApiV2Migration.php';
expectWorkerApiV2Migration(is_file($migrationPath), 'WorkerApiV2Migration implementation is missing');
require_once $migrationPath;

$rollbackPdo = workerApiV2Database();
$rollbackConnection = new WorkerApiV2SqliteConnection($rollbackPdo);
$columnsBeforeFailure = workerApiV2Columns($rollbackPdo);
$workerBeforeFailure = workerApiV2Rows($rollbackPdo, 'SELECT * FROM m_STTWorker WHERE id = 1');
$jobsBeforeFailure = workerApiV2Rows($rollbackPdo, 'SELECT * FROM m_STTJob WHERE id IN (10, 11) ORDER BY id');
$preservationBeforeFailure = workerApiV2PreservationHash($rollbackPdo);
$rollbackConnection->failBeforeMarker = true;
$failureObserved = false;
try {
    workerApiV2Migrator()->migrate($rollbackConnection, static fn (): null => null);
} catch (RuntimeException $exception) {
    $failureObserved = $exception->getMessage() === 'injected pre-marker failure';
}
expectWorkerApiV2Migration($failureObserved, 'The forced pre-marker failure was not observed');
expectWorkerApiV2Migration(
    $rollbackConnection->workerUpdateExecuted
        && $rollbackConnection->jobUpdateExecuted
        && $rollbackConnection->markerAttempted,
    'Marker was not the final data-migration operation'
);
expectWorkerApiV2Migration(
    workerApiV2Columns($rollbackPdo) === $columnsBeforeFailure,
    'Failed migration did not roll back worker schema additions'
);
expectWorkerApiV2Migration(
    workerApiV2Rows($rollbackPdo, 'SELECT * FROM m_STTWorker WHERE id = 1') === $workerBeforeFailure,
    'Failed migration changed the legacy worker'
);
expectWorkerApiV2Migration(
    workerApiV2Rows($rollbackPdo, 'SELECT * FROM m_STTJob WHERE id IN (10, 11) ORDER BY id') === $jobsBeforeFailure,
    'Failed migration changed job ownership or lease data'
);
expectWorkerApiV2Migration(
    workerApiV2PreservationHash($rollbackPdo) === $preservationBeforeFailure,
    'Failed migration changed preserved result, transcript, event, or cursor data'
);
expectWorkerApiV2Migration(
    (int)$rollbackPdo->query('SELECT COUNT(*) FROM m_STTSchemaMigration WHERE version = 11')->fetchColumn() === 0,
    'Failed migration recorded schema version 11'
);

$terminalJobBefore = workerApiV2Rows($rollbackPdo, 'SELECT * FROM m_STTJob WHERE id = 11');
$preservationBeforeSuccess = workerApiV2PreservationHash($rollbackPdo);
$applied = workerApiV2Migrator()->migrate($rollbackConnection, static fn (): null => null);
expectWorkerApiV2Migration($applied === [11], 'Schema migration 11 was not applied');
foreach (['api_version', 'app_version', 'updated_at'] as $column) {
    expectWorkerApiV2Migration(
        in_array($column, workerApiV2Columns($rollbackPdo), true),
        "Worker column {$column} is missing"
    );
}
$legacyWorker = workerApiV2Rows(
    $rollbackPdo,
    'SELECT api_version, app_version, status, has_settings_changed, api_key_id, updated_at
       FROM m_STTWorker WHERE id = 1'
)[0] ?? [];
expectWorkerApiV2Migration((int)($legacyWorker['api_version'] ?? 0) === 1, 'Legacy worker API version changed incorrectly');
expectWorkerApiV2Migration(($legacyWorker['app_version'] ?? null) === '', 'Legacy worker app version must default to empty');
expectWorkerApiV2Migration(($legacyWorker['status'] ?? null) === 'offline', 'Legacy worker was not marked offline');
expectWorkerApiV2Migration((int)($legacyWorker['has_settings_changed'] ?? 0) === 1, 'Legacy settings flag was not raised');
expectWorkerApiV2Migration((int)($legacyWorker['api_key_id'] ?? 0) === 41, 'Legacy API-key binding changed');
expectWorkerApiV2Migration((int)($legacyWorker['updated_at'] ?? 0) > 0, 'Legacy worker update timestamp was not populated');

$requeuedJob = workerApiV2Rows($rollbackPdo, 'SELECT * FROM m_STTJob WHERE id = 10')[0] ?? [];
expectWorkerApiV2Migration(($requeuedJob['status'] ?? null) === 'pending', 'Legacy processing job was not requeued');
expectWorkerApiV2Migration(
    array_key_exists('worker_id', $requeuedJob) && $requeuedJob['worker_id'] === null,
    'Legacy job ownership was not cleared'
);
expectWorkerApiV2Migration(
    array_key_exists('started_at', $requeuedJob) && $requeuedJob['started_at'] === null,
    'Legacy job start time was not cleared'
);
expectWorkerApiV2Migration(
    array_key_exists('lease_token', $requeuedJob) && $requeuedJob['lease_token'] === null,
    'Legacy lease token was not cleared'
);
expectWorkerApiV2Migration(
    array_key_exists('lease_expires_at', $requeuedJob) && $requeuedJob['lease_expires_at'] === null,
    'Legacy lease expiry was not cleared'
);
expectWorkerApiV2Migration((int)($requeuedJob['attempts'] ?? 0) === 1, 'Legacy job attempts changed');
expectWorkerApiV2Migration(($requeuedJob['error_msg'] ?? null) === 'worker_api_migration', 'Migration reason was not recorded');
expectWorkerApiV2Migration(
    workerApiV2Rows($rollbackPdo, 'SELECT * FROM m_STTJob WHERE id = 11') === $terminalJobBefore,
    'Terminal job changed byte-for-byte'
);
expectWorkerApiV2Migration(
    workerApiV2PreservationHash($rollbackPdo) === $preservationBeforeSuccess,
    'Result, transcript, event, or cursor data changed'
);
expectWorkerApiV2Migration(
    (string)$rollbackPdo->query(
        "SELECT value FROM m_STTSetting WHERE key = 'worker_api_contract_migration_v2'"
    )->fetchColumn() === '1',
    'Worker API v2 migration marker is missing'
);

$firstModuleHash = workerApiV2ModuleHash($rollbackPdo);
expectWorkerApiV2Migration(
    workerApiV2Migrator()->migrate($rollbackConnection, static fn (): null => null) === [],
    'Repeated schema migration was not a no-op'
);
expectWorkerApiV2Migration(
    workerApiV2ModuleHash($rollbackPdo) === $firstModuleHash,
    'Repeated setup changed module schema or data after the marker'
);
(new WorkerApiV2Migration())->migrate($rollbackConnection);
expectWorkerApiV2Migration(
    workerApiV2ModuleHash($rollbackPdo) === $firstModuleHash,
    'Direct data migration changed module state after the marker'
);

$v2Pdo = workerApiV2Database();
$v2Pdo->exec('ALTER TABLE m_STTWorker ADD COLUMN api_version INTEGER NOT NULL DEFAULT 1');
$v2Pdo->exec("ALTER TABLE m_STTWorker ADD COLUMN app_version TEXT NOT NULL DEFAULT ''");
$v2Pdo->exec('ALTER TABLE m_STTWorker ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0');
$v2Pdo->exec(
    "INSERT INTO m_STTWorker(
        id, worker_uid, name, status, api_key_id, has_settings_changed, last_seen, created_at,
        api_version, app_version, updated_at
     ) VALUES (2, 'v2-worker', 'Current Mac', 'online', 42, 0, 1700000500, 1690000500, 2, '2.5.0', 1700000500)"
);
$v2Pdo->exec(
    "INSERT INTO m_STTJob(
        id, call_id, file_path, status, worker_id, attempts, created_at, updated_at,
        started_at, lease_token, lease_expires_at, error_msg
     ) VALUES (
        12, 'v2-active', '/tmp/v2-active.wav', 'processing', 2, 3, 1690000500, 1700000500,
        1700000500, 'v2-secret-lease', 1800000500, ''
     )"
);
$v2WorkerBefore = workerApiV2Rows($v2Pdo, 'SELECT * FROM m_STTWorker WHERE id = 2');
$v2JobBefore = workerApiV2Rows($v2Pdo, 'SELECT * FROM m_STTJob WHERE id = 12');
$v2Connection = new WorkerApiV2SqliteConnection($v2Pdo);
expectWorkerApiV2Migration(
    workerApiV2Migrator()->migrate($v2Connection, static fn (): null => null) === [11],
    'Partial-schema migration 11 was not applied'
);
expectWorkerApiV2Migration(
    workerApiV2Rows($v2Pdo, 'SELECT * FROM m_STTWorker WHERE id = 2') === $v2WorkerBefore,
    'Already-v2 worker changed byte-for-byte'
);
expectWorkerApiV2Migration(
    workerApiV2Rows($v2Pdo, 'SELECT * FROM m_STTJob WHERE id = 12') === $v2JobBefore,
    'Active v2 lease changed byte-for-byte'
);

echo json_encode([
    'migration' => 11,
    'rollback_verified' => true,
    'idempotency_hash' => $firstModuleHash,
    'preservation_hash' => $preservationBeforeSuccess,
    'legacy_api_key_binding' => (int)$legacyWorker['api_key_id'],
    'v2_lease_preserved' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
