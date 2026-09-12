<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptSearchIndexService;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptSearchText;

require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
require_once __DIR__ . '/../Lib/Services/TranscriptSearchText.php';
require_once __DIR__ . '/../Lib/Services/TranscriptSearchIndexService.php';

final class TranscriptSearchSqliteConnection
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

function expectTranscriptSearchIndex(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('pdo_sqlite is required for transcript search tests');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, transcript TEXT NOT NULL)');
$insert = $pdo->prepare('INSERT INTO m_STTResult(id, transcript) VALUES (?, ?)');
$insert->execute([1, json_encode([['text' => 'Ёлка для клиента']], JSON_UNESCAPED_UNICODE)]);
$insert->execute([2, json_encode([['text' => 'Статус заказа готов']], JSON_UNESCAPED_UNICODE)]);
$insert->execute([3, json_encode([['text' => '']], JSON_UNESCAPED_UNICODE)]);

$connection = new TranscriptSearchSqliteConnection($pdo);
$service = new TranscriptSearchIndexService();
$service->ensureSchema($connection);
expectTranscriptSearchIndex($service->prepare($connection), 'FTS5 index must be available');
expectTranscriptSearchIndex($service->backfillBatch($connection, 1) === 1, 'first bounded batch must index one result');
expectTranscriptSearchIndex(!$service->isReady($connection), 'partial backfill must not expose incomplete search');

$resumed = new TranscriptSearchIndexService();
expectTranscriptSearchIndex($resumed->backfillBatch($connection, 1) === 1, 'resumed backfill must continue after the stored cursor');
expectTranscriptSearchIndex($resumed->backfillBatch($connection, 1) === 1, 'empty transcripts must advance the persistent cursor');
expectTranscriptSearchIndex($resumed->backfillBatch($connection, 1) === 0, 'exhausted backfill must terminate');
expectTranscriptSearchIndex($resumed->isReady($connection), 'completed backfill must enable search');

$findIds = static function (string $query) use ($pdo): array {
    $statement = $pdo->prepare(
        'SELECT rowid FROM m_STTTranscriptSearch WHERE m_STTTranscriptSearch MATCH ? ORDER BY rowid'
    );
    $statement->execute([TranscriptSearchText::query($query)]);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
};
expectTranscriptSearchIndex($findIds('елка') === [1], 'normalized Russian text must be searchable after backfill');
expectTranscriptSearchIndex($findIds('стат заказ') === [2], 'all prefix tokens must match the same transcript');

$pdo->prepare('UPDATE m_STTResult SET transcript = ?, search_text = ? WHERE id = 2')->execute([
    json_encode([['text' => 'Новый ответ']], JSON_UNESCAPED_UNICODE),
    TranscriptSearchText::fromTranscript(json_encode([['text' => 'Новый ответ']], JSON_UNESCAPED_UNICODE)),
]);
expectTranscriptSearchIndex($findIds('статус') === [], 'updated text must leave the index');
expectTranscriptSearchIndex($findIds('новый') === [2], 'updated text must enter the index');

$pdo->exec('DELETE FROM m_STTResult WHERE id = 1');
expectTranscriptSearchIndex($findIds('елка') === [], 'retention deletion must remove the FTS row through the trigger');

$legacyPdo = new PDO('sqlite::memory:');
$legacyPdo->exec('CREATE TABLE m_STTResult (id INTEGER PRIMARY KEY, transcript TEXT NOT NULL)');
expectTranscriptSearchIndex(
    !(new TranscriptSearchIndexService())->isReady(new TranscriptSearchSqliteConnection($legacyPdo)),
    'missing optional FTS schema must leave ordinary transcript listing available'
);

echo "Transcript search SQLite index checks passed\n";
