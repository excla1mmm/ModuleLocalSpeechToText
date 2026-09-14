<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptEventIndexService;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/TranscriptEventIndexService.php';

function expectTranscriptEventIndexes(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class TranscriptEventIndexConnection
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

function newTranscriptEventIndexConnection(string $schema): TranscriptEventIndexConnection
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec($schema);

    return new TranscriptEventIndexConnection($pdo);
}

/**
 * @return array{event_key_unique: int, event_cursor: int, result_id: int, names: array<int, string>}
 */
function transcriptEventIndexCoverage(TranscriptEventIndexConnection $connection): array
{
    $coverage = ['event_key_unique' => 0, 'event_cursor' => 0, 'result_id' => 0, 'names' => []];
    foreach ($connection->fetchAll("PRAGMA index_list('m_STTTranscriptEvent')", PDO::FETCH_ASSOC) as $index) {
        $name = (string)$index['name'];
        $coverage['names'][] = $name;
        $escaped = str_replace("'", "''", $name);
        $columns = array_map(
            static fn (array $row): string => (string)$row['name'],
            $connection->fetchAll("PRAGMA index_info('{$escaped}')", PDO::FETCH_ASSOC)
        );
        $unique = (int)($index['unique'] ?? 0) === 1;
        $partial = (int)($index['partial'] ?? 0) === 1;
        if ($unique && !$partial && $columns === ['event_key']) {
            $coverage['event_key_unique']++;
        }
        if (!$unique && !$partial && $columns === ['created_at', 'id']) {
            $coverage['event_cursor']++;
        }
        if (!$unique && !$partial && $columns === ['result_id']) {
            $coverage['result_id']++;
        }
    }
    sort($coverage['names']);

    return $coverage;
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for transcript index tests\n");
    exit(1);
}

$baseTable = 'CREATE TABLE m_STTTranscriptEvent (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type TEXT NOT NULL,
    event_key TEXT NOT NULL,
    result_id INTEGER NOT NULL,
    job_id INTEGER,
    call_id TEXT,
    update_type TEXT,
    payload TEXT,
    created_at INTEGER NOT NULL
);';

$fresh = newTranscriptEventIndexConnection($baseTable);
$service = new TranscriptEventIndexService();
$service->ensure($fresh);
$freshCoverage = transcriptEventIndexCoverage($fresh);
expectTranscriptEventIndexes($freshCoverage['event_key_unique'] === 1, 'Fresh schema needs one unique event_key index');
expectTranscriptEventIndexes($freshCoverage['event_cursor'] === 1, 'Fresh schema needs one cursor index');
expectTranscriptEventIndexes($freshCoverage['result_id'] === 1, 'Fresh schema needs one result index');
$freshSnapshot = $freshCoverage;
$service->ensure($fresh);
expectTranscriptEventIndexes(
    transcriptEventIndexCoverage($fresh) === $freshSnapshot,
    'Canonical index migration must be idempotent'
);

$duplicated = newTranscriptEventIndexConnection(
    str_replace('event_key TEXT NOT NULL', 'event_key TEXT NOT NULL UNIQUE', $baseTable) . '
    CREATE UNIQUE INDEX i_m_STTTranscriptEvent_event_key ON m_STTTranscriptEvent(event_key);
    CREATE UNIQUE INDEX m_STTTranscriptEvent_event_key ON m_STTTranscriptEvent(event_key);
    CREATE INDEX i_m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(created_at, id);
    CREATE INDEX m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(created_at, id);
    CREATE INDEX i_m_STTTranscriptEvent_result_id ON m_STTTranscriptEvent(result_id);
    CREATE INDEX m_STTTranscriptEvent_result_id ON m_STTTranscriptEvent(result_id);'
);
$service->ensure($duplicated);
$duplicateCoverage = transcriptEventIndexCoverage($duplicated);
expectTranscriptEventIndexes($duplicateCoverage['event_key_unique'] === 2, 'Core index and table constraint must remain stable');
expectTranscriptEventIndexes($duplicateCoverage['event_cursor'] === 1, 'Duplicate cursor coverage must be removed');
expectTranscriptEventIndexes($duplicateCoverage['result_id'] === 1, 'Duplicate result coverage must be removed');
expectTranscriptEventIndexes(
    in_array('i_m_STTTranscriptEvent_event_key', $duplicateCoverage['names'], true)
        && in_array('i_m_STTTranscriptEvent_event_cursor', $duplicateCoverage['names'], true)
        && in_array('i_m_STTTranscriptEvent_result_id', $duplicateCoverage['names'], true),
    'Core-managed transcript indexes must remain canonical'
);

$malformed = newTranscriptEventIndexConnection(
    str_replace('event_key TEXT NOT NULL', 'event_key TEXT NOT NULL UNIQUE', $baseTable) . '
    CREATE INDEX i_m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(result_id);
    CREATE INDEX m_STTTranscriptEvent_event_cursor ON m_STTTranscriptEvent(created_at, id);
    CREATE INDEX i_m_STTTranscriptEvent_result_id ON m_STTTranscriptEvent(result_id);'
);
$beforeMalformed = transcriptEventIndexCoverage($malformed);
$malformedRejected = false;
try {
    $service->ensure($malformed);
} catch (Throwable) {
    $malformedRejected = true;
}
expectTranscriptEventIndexes($malformedRejected, 'Malformed canonical index must be rejected');
expectTranscriptEventIndexes(
    transcriptEventIndexCoverage($malformed) === $beforeMalformed,
    'Malformed layout rejection must not drop or replace any index'
);

$eventModel = file_get_contents(__DIR__ . '/../Models/STTTranscriptEvent.php');
$setupSource = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
expectTranscriptEventIndexes($eventModel !== false && $setupSource !== false, 'Cannot inspect index integration');
expectTranscriptEventIndexes(
    str_contains($eventModel, '@Indexes(')
        && str_contains($eventModel, "name='event_key'")
        && str_contains($eventModel, "name='event_cursor'")
        && str_contains($eventModel, "name='result_id'"),
    'Fresh schema annotations must own transcript event indexes'
);
expectTranscriptEventIndexes(
    str_contains($setupSource, "'stt-schema-007-module-indexes'")
        && str_contains($setupSource, 'new ModuleDatabaseIndexService()'),
    'Canonical transcript indexes must be an explicit versioned migration'
);

echo "Transcript event index behavior OK\n";
