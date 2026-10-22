<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptRetentionService;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/TranscriptRetentionService.php';

final class RetentionSqliteConnection
{
    public ?string $failOnSqlContaining = null;
    public ?string $returnFalseOnSqlContaining = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql, array $bind = []): bool
    {
        if ($this->failOnSqlContaining !== null && str_contains($sql, $this->failOnSqlContaining)) {
            throw new RuntimeException('injected retention delete failure');
        }
        if ($this->returnFalseOnSqlContaining !== null && str_contains($sql, $this->returnFalseOnSqlContaining)) {
            return false;
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

function expectRetentionSqlite(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function retentionDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE m_STTJob (id INTEGER PRIMARY KEY, call_id TEXT NOT NULL, file_path TEXT NOT NULL UNIQUE);
         CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, job_id INTEGER NOT NULL UNIQUE, created_at INTEGER NOT NULL);
         CREATE TABLE m_STTTranscriptEvent (id INTEGER PRIMARY KEY, result_id INTEGER NOT NULL); CREATE TABLE m_STTSummary (id INTEGER PRIMARY KEY, result_id INTEGER NOT NULL UNIQUE);
         CREATE TABLE m_STTCallTranscript (id INTEGER PRIMARY KEY AUTOINCREMENT, call_id TEXT NOT NULL UNIQUE, current_revision INTEGER NOT NULL DEFAULT 1);
         CREATE TABLE m_STTCallTranscriptPart (id INTEGER PRIMARY KEY, call_transcript_id INTEGER NOT NULL, result_id INTEGER NOT NULL);
         CREATE TABLE m_STTCallTranscriptRevision (id INTEGER PRIMARY KEY, call_transcript_id INTEGER NOT NULL);
         CREATE TABLE m_STTCallTranscriptEvent (id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL DEFAULT \'call-transcript.completed\', event_key TEXT NOT NULL DEFAULT \'\', call_transcript_id INTEGER NOT NULL, call_id TEXT NOT NULL DEFAULT \'\', revision INTEGER NOT NULL DEFAULT 1, content_hash TEXT NOT NULL DEFAULT \'\', parts_hash TEXT NOT NULL DEFAULT \'\', completeness_status TEXT NOT NULL DEFAULT \'complete\', created_at INTEGER NOT NULL DEFAULT 0);
         CREATE UNIQUE INDEX i_m_STTCallTranscriptEvent_key ON m_STTCallTranscriptEvent (event_key);'
    );

    return $pdo;
}

function seedRetentionGraph(PDO $pdo, int $now): void
{
    $cutoff = $now - (365 * 86400);
    $insertJob = $pdo->prepare('INSERT INTO m_STTJob (id, call_id, file_path) VALUES (?, ?, ?)');
    $insertResult = $pdo->prepare('INSERT INTO m_STTResult (id, job_id, created_at) VALUES (?, ?, ?)');
    foreach ([
        [1, 'call-old', '/recordings/old.wav', 101, $cutoff - 100],
        [2, 'call-new', '/recordings/new.wav', 102, $cutoff + 100],
        [3, 'call-mixed', '/recordings/mixed-old.wav', 103, $cutoff - 200],
        [4, 'call-mixed', '/recordings/mixed-new.wav', 104, $cutoff + 200],
        [5, '', '/recordings/empty-call.wav', 105, $cutoff - 50],
    ] as [$jobId, $callId, $path, $resultId, $createdAt]) {
        $insertJob->execute([$jobId, $callId, $path]);
        $insertResult->execute([$resultId, $jobId, $createdAt]);
        $pdo->exec("INSERT INTO m_STTTranscriptEvent (id, result_id) VALUES ({$resultId}, {$resultId})");
        $pdo->exec("INSERT INTO m_STTSummary (id, result_id) VALUES ({$resultId}, {$resultId})");
    }
    foreach ([
        [201, 'call-old', [101]],
        [202, 'call-new', [102]],
        [203, 'call-mixed', [103, 104]],
    ] as [$callTranscriptId, $callId, $resultIds]) {
        $pdo->prepare('INSERT INTO m_STTCallTranscript (id, call_id) VALUES (?, ?)')
            ->execute([$callTranscriptId, $callId]);
        $pdo->exec("INSERT INTO m_STTCallTranscriptRevision (id, call_transcript_id) VALUES ({$callTranscriptId}, {$callTranscriptId})");
        $pdo->exec("INSERT INTO m_STTCallTranscriptEvent (id, event_key, call_transcript_id, created_at) VALUES ({$callTranscriptId}, 'completed:{$callTranscriptId}', {$callTranscriptId}, {$callTranscriptId})");
        foreach ($resultIds as $resultId) {
            $pdo->exec("INSERT INTO m_STTCallTranscriptPart (id, call_transcript_id, result_id) VALUES ({$resultId}, {$callTranscriptId}, {$resultId})");
        }
    }
}

function retentionCount(PDO $pdo, string $table): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for transcript retention tests\n");
    exit(1);
}

$now = 2_000_000_000;
$pdo = retentionDatabase();
seedRetentionGraph($pdo, $now);
$connection = new RetentionSqliteConnection($pdo);
$service = new TranscriptRetentionService($connection, new ModuleTransaction());
$summary = $service->purgeExpired(365, $now);
expectRetentionSqlite($summary['calls'] === 2, 'expired call count mismatch: ' . json_encode($summary));
expectRetentionSqlite($summary['results'] === 2, 'expired result count mismatch');
expectRetentionSqlite($summary['has_more'] === false, 'complete sweep must not request continuation');
expectRetentionSqlite($summary['cutoff'] === $now - (365 * 86400), 'retention cutoff mismatch');
expectRetentionSqlite(retentionCount($pdo, 'm_STTJob') === 5, 'job tombstones must remain');
expectRetentionSqlite(retentionCount($pdo, 'm_STTResult') === 3, 'recent and mixed-age calls must remain');
expectRetentionSqlite(retentionCount($pdo, 'm_STTTranscriptEvent') === 3, 'raw events must follow retained results');
expectRetentionSqlite(retentionCount($pdo, 'm_STTSummary') === 3, 'call summaries must follow retained results');
expectRetentionSqlite(retentionCount($pdo, 'm_STTCallTranscript') === 2, 'only expired call aggregate must be removed');
expectRetentionSqlite(retentionCount($pdo, 'm_STTCallTranscriptPart') === 3, 'mixed-age aggregate parts must remain atomic');
expectRetentionSqlite(
    (int)$pdo->query("SELECT COUNT(*) FROM m_STTResult WHERE id = 103")->fetchColumn() === 1,
    'old part of a recently processed call must remain'
);
$retentionTombstones = $pdo->query(
    "SELECT call_transcript_id, event_key, created_at FROM m_STTCallTranscriptEvent WHERE event_type = 'call-transcript.deleted'"
)->fetchAll(PDO::FETCH_ASSOC);
expectRetentionSqlite(
    count($retentionTombstones) === 1
        && (int)$retentionTombstones[0]['call_transcript_id'] === 201
        && $retentionTombstones[0]['event_key'] === 'call-transcript.deleted:201:retention'
        && (int)$retentionTombstones[0]['created_at'] === $now,
    'retention purge must publish one retention tombstone per purged aggregate: ' . json_encode($retentionTombstones)
);
expectRetentionSqlite(
    (int)$pdo->query('SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE id = 201')->fetchColumn() === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE id IN (202, 203)')->fetchColumn() === 2,
    'retention must remove only the purged aggregate events'
);

$unlimitedPdo = retentionDatabase();
seedRetentionGraph($unlimitedPdo, $now);
$unlimited = (new TranscriptRetentionService(new RetentionSqliteConnection($unlimitedPdo)))
    ->purgeExpired(0, $now);
expectRetentionSqlite($unlimited === ['calls' => 0, 'results' => 0, 'has_more' => false, 'cutoff' => 0], 'unlimited retention must be a no-op');
expectRetentionSqlite(retentionCount($unlimitedPdo, 'm_STTResult') === 5, 'unlimited retention deleted results');
expectRetentionSqlite(retentionCount($unlimitedPdo, 'm_STTCallTranscriptEvent') === 3, 'unlimited retention must not publish tombstones');

$batchPdo = retentionDatabase();
seedRetentionGraph($batchPdo, $now);
$batchService = new TranscriptRetentionService(new RetentionSqliteConnection($batchPdo));
$firstBatch = $batchService->purgeExpired(365, $now, 1);
expectRetentionSqlite($firstBatch['calls'] === 1 && $firstBatch['has_more'], 'bounded first batch must request continuation');
$secondBatch = $batchService->purgeExpired(365, $now, 1);
expectRetentionSqlite($secondBatch['calls'] === 1 && !$secondBatch['has_more'], 'second batch must drain remaining candidates');

$rollbackPdo = retentionDatabase();
seedRetentionGraph($rollbackPdo, $now);
$rollbackConnection = new RetentionSqliteConnection($rollbackPdo);
$rollbackConnection->failOnSqlContaining = 'DELETE FROM m_STTResult';
$rollbackFailed = false;
try {
    (new TranscriptRetentionService($rollbackConnection))->purgeExpired(365, $now);
} catch (RuntimeException $exception) {
    $rollbackFailed = $exception->getMessage() === 'injected retention delete failure';
}
expectRetentionSqlite($rollbackFailed, 'injected deletion failure must escape the service');
expectRetentionSqlite(retentionCount($rollbackPdo, 'm_STTResult') === 5, 'failed purge must roll back results');
expectRetentionSqlite(retentionCount($rollbackPdo, 'm_STTCallTranscript') === 3, 'failed purge must roll back aggregates');
expectRetentionSqlite(
    (int)$rollbackPdo->query("SELECT COUNT(*) FROM m_STTCallTranscriptEvent WHERE event_type = 'call-transcript.deleted'")->fetchColumn() === 0,
    'failed purge must roll back its tombstones'
);

$falsePdo = retentionDatabase();
seedRetentionGraph($falsePdo, $now);
$falseConnection = new RetentionSqliteConnection($falsePdo);
$falseConnection->returnFalseOnSqlContaining = 'DELETE FROM m_STTResult';
$falseRejected = false;
try {
    (new TranscriptRetentionService($falseConnection))->purgeExpired(365, $now);
} catch (RuntimeException $exception) {
    $falseRejected = str_contains($exception->getMessage(), 'm_STTResult');
}
expectRetentionSqlite($falseRejected, 'false deletion result must abort the retention transaction');
expectRetentionSqlite(retentionCount($falsePdo, 'm_STTResult') === 5, 'false deletion result must roll back results');
expectRetentionSqlite(retentionCount($falsePdo, 'm_STTCallTranscript') === 3, 'false deletion result must roll back aggregates');

echo "Transcript retention SQLite behavior OK\n";
