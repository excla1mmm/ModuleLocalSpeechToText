<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptDeletionService;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\TranscriptDeletionBlockedException;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Exceptions/TranscriptDeletionBlockedException.php';
require_once __DIR__ . '/../Lib/Services/TranscriptDeletionService.php';

final class TranscriptDeletionConnection
{
    public ?string $failOnSqlContaining = null;
    /** @var array<int, string> */
    public array $executedSql = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        $this->executedSql[] = $sql;
        if ($this->failOnSqlContaining !== null && str_contains($sql, $this->failOnSqlContaining)) {
            throw new RuntimeException('injected deletion failure');
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

    public function fetchOne(string $sql, int $fetchMode, array $bind = []): array|false
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);

        return $statement->fetch($fetchMode);
    }

    public function begin(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return $this->pdo->rollBack(); }
    public function isUnderTransaction(): bool { return $this->pdo->inTransaction(); }
}

function deletionDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE m_STTJob (id INTEGER PRIMARY KEY, call_id TEXT NOT NULL, status TEXT NOT NULL, updated_at INTEGER NOT NULL DEFAULT 0);
         CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL);
         CREATE TABLE m_STTTranscriptEvent (id INTEGER PRIMARY KEY, result_id INTEGER NOT NULL);
         CREATE TABLE m_STTSummary (id INTEGER PRIMARY KEY, result_id INTEGER NOT NULL UNIQUE);
         CREATE TABLE m_STTCallTranscript (id INTEGER PRIMARY KEY AUTOINCREMENT, call_id TEXT NOT NULL UNIQUE, current_revision INTEGER NOT NULL DEFAULT 0);
         CREATE TABLE m_STTCallTranscriptPart (id INTEGER PRIMARY KEY, call_transcript_id INTEGER NOT NULL, result_id INTEGER NOT NULL);
         CREATE TABLE m_STTCallTranscriptRevision (id INTEGER PRIMARY KEY, call_transcript_id INTEGER NOT NULL);
         CREATE TABLE m_STTCallTranscriptEvent (id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL DEFAULT \'call-transcript.completed\', event_key TEXT NOT NULL DEFAULT \'\', call_transcript_id INTEGER NOT NULL, call_id TEXT NOT NULL DEFAULT \'\', revision INTEGER NOT NULL DEFAULT 1, content_hash TEXT NOT NULL DEFAULT \'\', parts_hash TEXT NOT NULL DEFAULT \'\', completeness_status TEXT NOT NULL DEFAULT \'complete\', created_at INTEGER NOT NULL DEFAULT 0);
         CREATE UNIQUE INDEX i_m_STTCallTranscriptEvent_key ON m_STTCallTranscriptEvent (event_key);'
    );
    $pdo->exec(
        "INSERT INTO m_STTJob (id, call_id, status) VALUES
            (1, 'call-a', 'done'), (2, 'call-a', 'done'), (3, 'call-b', 'done');
         INSERT INTO m_STTResult VALUES (11, 1), (12, 2), (13, 3);
         INSERT INTO m_STTTranscriptEvent VALUES (21, 11), (22, 12), (23, 13);
         INSERT INTO m_STTSummary VALUES (71, 11), (72, 13);
         INSERT INTO m_STTCallTranscript VALUES (31, 'call-a', 3), (32, 'call-b', 1);
         INSERT INTO m_STTCallTranscriptPart VALUES (41, 31, 11), (42, 31, 12), (43, 32, 13);
         INSERT INTO m_STTCallTranscriptRevision VALUES (51, 31), (52, 32);
         INSERT INTO m_STTCallTranscriptEvent (id, event_key, call_transcript_id, call_id, created_at) VALUES
            (61, 'completed:31', 31, 'call-a', 4000000000), (62, 'completed:32', 32, 'call-b', 1700000000);"
    );

    return $pdo;
}

function deletionCount(PDO $pdo, string $table): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

function expectDeletion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for transcript deletion tests\n");
    exit(1);
}

$pdo = deletionDatabase();
$deletionConnection = new TranscriptDeletionConnection($pdo);
$result = (new TranscriptDeletionService($deletionConnection, new ModuleTransaction()))->deleteByResultId(11);
expectDeletion($result === ['call_id' => 'call-a', 'results' => 2], 'Deletion summary mismatch');
expectDeletion(
    str_starts_with($deletionConnection->executedSql[0] ?? '', 'UPDATE m_STTJob SET updated_at = updated_at'),
    'Deletion must acquire the SQLite writer lock before checking active jobs'
);
expectDeletion(deletionCount($pdo, 'm_STTJob') === 3, 'Jobs and their CDR identity must remain');
expectDeletion(deletionCount($pdo, 'm_STTResult') === 1, 'All result parts of the selected call must be deleted');
expectDeletion(deletionCount($pdo, 'm_STTTranscriptEvent') === 1, 'Result events must follow deleted results');
expectDeletion(deletionCount($pdo, 'm_STTSummary') === 1, 'Call summaries must follow deleted results');
expectDeletion(deletionCount($pdo, 'm_STTCallTranscript') === 1, 'Selected call aggregate must be deleted');
expectDeletion(deletionCount($pdo, 'm_STTCallTranscriptPart') === 1, 'Selected call parts must be deleted');
expectDeletion(deletionCount($pdo, 'm_STTCallTranscriptRevision') === 1, 'Selected call revisions must be deleted');
expectDeletion(
    deletionCount($pdo, 'm_STTCallTranscriptEvent') === 2
        && (int)$pdo->query('SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE id = 61')->fetchColumn() === 0,
    'Selected call events must be deleted and replaced by one tombstone'
);
$tombstone = $pdo->query("SELECT * FROM m_STTCallTranscriptEvent WHERE event_type = 'call-transcript.deleted'")->fetchAll(PDO::FETCH_ASSOC);
expectDeletion(count($tombstone) === 1, 'Manual deletion must publish exactly one tombstone');
expectDeletion(
    (int)$tombstone[0]['call_transcript_id'] === 31
        && (int)$tombstone[0]['revision'] === 3
        && $tombstone[0]['event_key'] === 'call-transcript.deleted:31:manual'
        && $tombstone[0]['call_id'] === ''
        && $tombstone[0]['content_hash'] === '',
    'Tombstone must name the deleted aggregate, its last revision and the manual reason only'
);
expectDeletion(
    (int)$tombstone[0]['created_at'] === 4000000000 && (int)$tombstone[0]['id'] > 62,
    'Tombstone must sort after the pre-deletion cursor frontier, including the deleted events'
);
$pdo->exec(
    "INSERT INTO m_STTJob (id, call_id, status) VALUES (4, 'call-c', 'done');
     INSERT INTO m_STTResult VALUES (14, 4);
     INSERT INTO m_STTCallTranscript VALUES (33, 'call-c', 1);
     INSERT INTO m_STTCallTranscriptEvent (event_type, event_key, call_transcript_id, created_at)
        VALUES ('call-transcript.deleted', 'call-transcript.deleted:9:manual', 9, 1000)"
);
(new TranscriptDeletionService(new TranscriptDeletionConnection($pdo), new ModuleTransaction()))->deleteByResultId(14);
expectDeletion(
    (int)$pdo->query("SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE event_key = 'call-transcript.deleted:9:manual'")->fetchColumn() === 0,
    'Tombstones older than the TTL must be pruned on the next deletion'
);
expectDeletion(
    (int)$pdo->query("SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE event_key = 'call-transcript.deleted:33:manual'")->fetchColumn() === 1,
    'Second deletion must publish its own tombstone'
);

$rollbackPdo = deletionDatabase();
$connection = new TranscriptDeletionConnection($rollbackPdo);
$connection->failOnSqlContaining = 'DELETE FROM m_STTResult';
$failed = false;
try {
    (new TranscriptDeletionService($connection, new ModuleTransaction()))->deleteByResultId(11);
} catch (RuntimeException $exception) {
    $failed = $exception->getMessage() === 'injected deletion failure';
}
expectDeletion($failed, 'Injected deletion failure must escape');
expectDeletion(deletionCount($rollbackPdo, 'm_STTResult') === 3, 'Failed deletion must roll back results');
expectDeletion(deletionCount($rollbackPdo, 'm_STTCallTranscript') === 2, 'Failed deletion must roll back aggregate data');
expectDeletion(
    (int)$rollbackPdo->query("SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE event_type = 'call-transcript.deleted'")->fetchColumn() === 0,
    'Failed deletion must roll back its tombstone'
);

$activePdo = deletionDatabase();
$activePdo->exec("UPDATE m_STTJob SET status = 'processing' WHERE id = 2");
$blocked = false;
try {
    (new TranscriptDeletionService(new TranscriptDeletionConnection($activePdo), new ModuleTransaction()))
        ->deleteByResultId(11);
} catch (TranscriptDeletionBlockedException) {
    $blocked = true;
}
expectDeletion($blocked, 'Deletion must be blocked while another call job is active');
expectDeletion(deletionCount($activePdo, 'm_STTResult') === 3, 'Blocked deletion must preserve all results');
expectDeletion(
    (int)$activePdo->query("SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE event_type = 'call-transcript.deleted'")->fetchColumn() === 0,
    'Blocked deletion must not publish a tombstone'
);

echo "Transcript deletion SQLite behavior OK\n";
