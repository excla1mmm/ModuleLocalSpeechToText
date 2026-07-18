<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseIndexService;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/TranscriptEventIndexService.php';
require_once __DIR__ . '/../Lib/Services/ModuleDatabaseIndexService.php';

function expectModuleIndexes(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ModuleDatabaseIndexConnection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
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

function moduleIndexNames(ModuleDatabaseIndexConnection $connection, string $table): array
{
    return array_map(
        static fn (array $row): string => (string)$row['name'],
        $connection->fetchAll("PRAGMA index_list('{$table}')", PDO::FETCH_ASSOC)
    );
}

$fixture = file_get_contents(__DIR__ . '/fixtures/schema/legacy-current.sql');
expectModuleIndexes($fixture !== false, 'Cannot read legacy index fixture');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec($fixture);
$connection = new ModuleDatabaseIndexConnection($pdo);
$service = new ModuleDatabaseIndexService();
$service->ensure($connection);

foreach ([
    'm_STTJob' => 'i_m_STTJob_file_path',
    'm_STTResult' => 'i_m_STTResult_job_id',
    'm_STTSetting' => 'i_m_STTSetting_key',
    'm_STTWorker' => 'i_m_STTWorker_worker_uid',
] as $table => $redundantIndex) {
    $names = moduleIndexNames($connection, $table);
    expectModuleIndexes(!in_array($redundantIndex, $names, true), "{$redundantIndex} must be removed");
    expectModuleIndexes(
        count(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'sqlite_autoindex_'))) >= 1,
        "{$table} unique constraint autoindex must remain"
    );
}
expectModuleIndexes(
    (int)$pdo->query('SELECT COUNT(*) FROM m_STTResult')->fetchColumn() === 1,
    'Index normalization must preserve module rows'
);
$schemaAfterFirstRun = (string)$pdo->query(
    "SELECT group_concat(name || ':' || COALESCE(sql, ''), '|')
       FROM (SELECT name, sql FROM sqlite_master WHERE type='index' ORDER BY name)"
)->fetchColumn();
$service->ensure($connection);
expectModuleIndexes(
    (string)$pdo->query(
        "SELECT group_concat(name || ':' || COALESCE(sql, ''), '|')
           FROM (SELECT name, sql FROM sqlite_master WHERE type='index' ORDER BY name)"
    )->fetchColumn() === $schemaAfterFirstRun,
    'Complete module index normalization must be idempotent'
);

$malformedPdo = new PDO('sqlite::memory:');
$malformedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$malformedPdo->exec(
    'CREATE TABLE m_STTJob(id INTEGER PRIMARY KEY, file_path TEXT UNIQUE, status TEXT);
     CREATE INDEX i_m_STTJob_file_path ON m_STTJob(status);
     CREATE TABLE m_STTResult(id INTEGER PRIMARY KEY, job_id INTEGER UNIQUE);
     CREATE TABLE m_STTSetting(id INTEGER PRIMARY KEY, key TEXT UNIQUE);
     CREATE TABLE m_STTWorker(id INTEGER PRIMARY KEY, worker_uid TEXT UNIQUE);
     CREATE TABLE m_STTTranscriptEvent(
        id INTEGER PRIMARY KEY, event_key TEXT UNIQUE, result_id INTEGER, created_at INTEGER
     );
     CREATE INDEX i_m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(created_at, id);
     CREATE INDEX i_m_STTTranscriptEvent_result_id ON m_STTTranscriptEvent(result_id);'
);
$malformedConnection = new ModuleDatabaseIndexConnection($malformedPdo);
$malformedBefore = (string)$malformedPdo->query(
    "SELECT group_concat(name || ':' || COALESCE(sql, ''), '|')
       FROM (SELECT name, sql FROM sqlite_master WHERE type='index' ORDER BY name)"
)->fetchColumn();
$malformedRejected = false;
try {
    $service->ensure($malformedConnection);
} catch (Throwable) {
    $malformedRejected = true;
}
expectModuleIndexes($malformedRejected, 'Malformed known redundant index must be rejected');
expectModuleIndexes(
    (string)$malformedPdo->query(
        "SELECT group_concat(name || ':' || COALESCE(sql, ''), '|')
           FROM (SELECT name, sql FROM sqlite_master WHERE type='index' ORDER BY name)"
    )->fetchColumn() === $malformedBefore,
    'Malformed base index must abort before any transcript index is changed'
);

echo "Module database index behavior OK\n";
