<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleOwnedIndexService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigrator;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigration.php';
require_once __DIR__ . '/../Lib/Services/ModuleSchemaMigrator.php';

function expectModuleOwnedIndexes(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ModuleOwnedIndexSqliteConnection
{
    public function __construct(public readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        return $this->pdo->prepare($sql)->execute($bind);
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

/**
 * Shape of a database installed by the 2026-09-13 build after a Core boot
 * pass: legacy partial indexes are gone, so duplicates could slip in.
 */
function moduleOwnedIndexDatabase(bool $keepLegacyIndexes = false): ModuleOwnedIndexSqliteConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE m_STTCallTranscriptPart (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        call_transcript_id INTEGER NOT NULL,
        result_id INTEGER NOT NULL,
        is_current INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE m_STTWorker (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        worker_uid VARCHAR NOT NULL UNIQUE,
        api_key_id INTEGER NULL,
        last_seen INTEGER NULL,
        api_version INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE m_STTSchemaMigration (
        version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL, applied_at INTEGER NOT NULL
    )');
    $pdo->exec("INSERT INTO m_STTSchemaMigration VALUES (16, 'baseline', 'baseline', 1)");

    if ($keepLegacyIndexes) {
        $pdo->exec('CREATE UNIQUE INDEX m_STTCallTranscriptPart_current_result
            ON m_STTCallTranscriptPart (call_transcript_id, result_id) WHERE is_current = 1');
        $pdo->exec('CREATE UNIQUE INDEX i_m_STTWorker_api_key_id_v2_unique
            ON m_STTWorker (api_key_id) WHERE api_key_id IS NOT NULL AND api_version = 2');
        $pdo->exec("INSERT INTO m_STTCallTranscriptPart (call_transcript_id, result_id, is_current)
            VALUES (7, 101, 0), (7, 101, 1)");
        $pdo->exec("INSERT INTO m_STTWorker (worker_uid, api_key_id, last_seen, api_version)
            VALUES ('worker-a', 41, '1700000000', 2)");

        return new ModuleOwnedIndexSqliteConnection($pdo);
    }

    $pdo->exec("INSERT INTO m_STTCallTranscriptPart (call_transcript_id, result_id, is_current) VALUES
        (7, 101, 1),
        (7, 101, 1),
        (7, 101, 1),
        (7, 102, 1),
        (8, 101, 0),
        (8, 101, 1)");
    $pdo->exec("INSERT INTO m_STTWorker (worker_uid, api_key_id, last_seen, api_version) VALUES
        ('v2-stale', 41, '1700000100', 2),
        ('v2-fresh', 41, '1700000900', 2),
        ('v2-tie-low', 42, '1700000500', 2),
        ('v2-tie-high', 42, '1700000500', 2),
        ('v2-sole', 43, NULL, 2),
        ('v1-a', 50, '1700000000', 1),
        ('v1-b', 50, '1700000000', 1)");

    return new ModuleOwnedIndexSqliteConnection($pdo);
}

function moduleOwnedIndexMigrator(): ModuleSchemaMigrator
{
    return new ModuleSchemaMigrator([
        new ModuleSchemaMigration(16, 'baseline', static fn (): null => null, 'baseline-seed'),
        new ModuleSchemaMigration(
            17,
            'module-owned partial unique indexes',
            static function (mixed $connection): void {
                (new ModuleOwnedIndexService())->ensure($connection);
            },
            'stt-schema-017-module-owned-partial-indexes'
        ),
    ]);
}

/** @return array<string, string> */
function moduleOwnedIndexSql(ModuleOwnedIndexSqliteConnection $connection): array
{
    $indexes = [];
    foreach ($connection->pdo->query(
        "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $indexes[(string)$row['name']] = preg_replace('/\s+/', ' ', (string)$row['sql']) ?? '';
    }

    return $indexes;
}

$servicePath = __DIR__ . '/../Lib/Services/ModuleOwnedIndexService.php';
expectModuleOwnedIndexes(is_file($servicePath), 'ModuleOwnedIndexService implementation is missing');
require_once $servicePath;

$connection = moduleOwnedIndexDatabase();
// ModuleSchemaMigrator stores the checksum of its seed, not the raw seed.
$connection->pdo->exec(
    "UPDATE m_STTSchemaMigration SET checksum = '" . hash('sha256', 'baseline-seed') . "' WHERE version = 16"
);
expectModuleOwnedIndexes(
    moduleOwnedIndexMigrator()->migrate($connection, static fn (): null => null) === [17],
    'Upgraded database must apply only migration 17'
);

$parts = $connection->pdo->query(
    'SELECT id, call_transcript_id, result_id, is_current FROM m_STTCallTranscriptPart ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
expectModuleOwnedIndexes(
    array_map(static fn (array $row): int => (int)$row['is_current'], $parts) === [0, 0, 1, 1, 0, 1],
    'Only the newest current part of each (call transcript, result) pair may stay current'
);
expectModuleOwnedIndexes((int)$connection->pdo->query('SELECT COUNT(*) FROM m_STTCallTranscriptPart')->fetchColumn() === 6, 'Parts must be demoted, never deleted');

$workers = [];
foreach ($connection->pdo->query('SELECT worker_uid, api_key_id FROM m_STTWorker')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $workers[(string)$row['worker_uid']] = $row['api_key_id'] === null ? null : (int)$row['api_key_id'];
}
expectModuleOwnedIndexes(
    $workers === [
        'v2-stale' => null,
        'v2-fresh' => 41,
        'v2-tie-low' => null,
        'v2-tie-high' => 42,
        'v2-sole' => 43,
        'v1-a' => 50,
        'v1-b' => 50,
    ],
    'Only the most recently seen v2 worker may keep a shared key; v1 bindings stay untouched'
);

$indexes = moduleOwnedIndexSql($connection);
expectModuleOwnedIndexes(
    str_contains($indexes[ModuleOwnedIndexService::CURRENT_PART_INDEX] ?? '', 'CREATE UNIQUE INDEX')
        && str_contains($indexes[ModuleOwnedIndexService::CURRENT_PART_INDEX], 'WHERE is_current = 1'),
    'Current-part partial unique index was not created'
);
expectModuleOwnedIndexes(
    str_contains($indexes[ModuleOwnedIndexService::WORKER_API_KEY_INDEX] ?? '', 'CREATE UNIQUE INDEX')
        && str_contains($indexes[ModuleOwnedIndexService::WORKER_API_KEY_INDEX], 'WHERE api_key_id IS NOT NULL AND api_version = 2'),
    'Worker API-key partial unique index was not created'
);

$rejected = false;
try {
    $connection->pdo->exec('INSERT INTO m_STTCallTranscriptPart (call_transcript_id, result_id, is_current) VALUES (7, 101, 1)');
} catch (PDOException) {
    $rejected = true;
}
expectModuleOwnedIndexes($rejected, 'A second current part must be rejected after repair');

$snapshot = serialize([
    moduleOwnedIndexSql($connection),
    $connection->pdo->query('SELECT * FROM m_STTCallTranscriptPart ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    $connection->pdo->query('SELECT * FROM m_STTWorker ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
]);
expectModuleOwnedIndexes(
    moduleOwnedIndexMigrator()->migrate($connection, static fn (): null => null) === [],
    'Repeated setup must not re-run migration 17'
);
$connection->pdo->beginTransaction();
$repeat = (new ModuleOwnedIndexService())->ensure($connection);
$connection->pdo->commit();
expectModuleOwnedIndexes(
    $repeat === ['demoted_parts' => 0, 'unbound_workers' => 0],
    'Re-ensuring after a Core pass must find nothing to repair'
);
expectModuleOwnedIndexes(
    serialize([
        moduleOwnedIndexSql($connection),
        $connection->pdo->query('SELECT * FROM m_STTCallTranscriptPart ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        $connection->pdo->query('SELECT * FROM m_STTWorker ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ]) === $snapshot,
    'Re-ensuring must not change schema or rows'
);

// Simulate a later Core pass dropping the indexes: ensure() must restore them.
$connection->pdo->exec('DROP INDEX ' . ModuleOwnedIndexService::CURRENT_PART_INDEX);
$connection->pdo->exec('DROP INDEX ' . ModuleOwnedIndexService::WORKER_API_KEY_INDEX);
$connection->pdo->beginTransaction();
(new ModuleOwnedIndexService())->ensure($connection);
$connection->pdo->commit();
expectModuleOwnedIndexes(moduleOwnedIndexSql($connection) === $indexes, 'Dropped module-owned indexes were not restored');

// Upgrade without an intervening reboot: legacy names still exist and must be replaced.
$legacy = moduleOwnedIndexDatabase(true);
$legacy->pdo->beginTransaction();
$legacyRepair = (new ModuleOwnedIndexService())->ensure($legacy);
$legacy->pdo->commit();
$legacyIndexes = moduleOwnedIndexSql($legacy);
expectModuleOwnedIndexes(
    $legacyRepair === ['demoted_parts' => 0, 'unbound_workers' => 0]
        && !isset($legacyIndexes['m_STTCallTranscriptPart_current_result'])
        && !isset($legacyIndexes['i_m_STTWorker_api_key_id_v2_unique'])
        && isset($legacyIndexes[ModuleOwnedIndexService::CURRENT_PART_INDEX], $legacyIndexes[ModuleOwnedIndexService::WORKER_API_KEY_INDEX]),
    'Legacy partial index names were not replaced by Core-preserved names'
);

$outside = moduleOwnedIndexDatabase();
$refused = false;
try {
    (new ModuleOwnedIndexService())->ensure($outside);
} catch (RuntimeException) {
    $refused = true;
}
expectModuleOwnedIndexes($refused, 'Repair outside a transaction must be refused');

$setup = (string)file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
$installStart = strpos($setup, 'public function installDB');
$installBody = substr($setup, (int)$installStart, (int)strpos($setup, 'private function migrateLegacyModuleLogs') - (int)$installStart);
expectModuleOwnedIndexes(
    str_contains($setup, "17,\n                'module-owned partial unique indexes'")
        && str_contains($setup, "'stt-schema-017-module-owned-partial-indexes'"),
    'Production setup must register migration 17 with stable identity'
);
expectModuleOwnedIndexes(
    preg_match('/\$migrator->migrate\(.*?alignSchemaWithCoreAnnotations\(\).*?ensureModuleOwnedIndexes.*?prepareTranscriptSearchIndex/s', $installBody) === 1,
    'installDB must align with Core annotations after migrations and then restore module-owned indexes'
);
expectModuleOwnedIndexes(
    str_contains($setup, 'createUpdateDbTableByAnnotations($modelClass, true)'),
    'Core alignment must preserve module-added columns like the boot pass does'
);

echo "STT module-owned partial index repair OK\n";
