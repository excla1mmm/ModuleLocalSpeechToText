<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;
use Throwable;

final class TranscriptSearchIndexService
{
    public function __construct(private readonly ModuleTransaction $transaction = new ModuleTransaction())
    {
    }

    public function ensureSchema(mixed $connection): void
    {
        $columns = $connection->fetchAll("PRAGMA table_info('m_STTResult')", PDO::FETCH_ASSOC);
        if (!in_array('search_text', array_column($columns, 'name'), true)) {
            $connection->execute("ALTER TABLE m_STTResult ADD COLUMN search_text TEXT NOT NULL DEFAULT ''");
        }
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTTranscriptSearchState (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                last_result_id INTEGER NOT NULL DEFAULT 0,
                completed_at INTEGER NOT NULL DEFAULT 0,
                available INTEGER NOT NULL DEFAULT 0,
                error_code TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $connection->execute(
            'INSERT OR IGNORE INTO m_STTTranscriptSearchState(id) VALUES (1)'
        );
    }

    public function prepare(mixed $connection): bool
    {
        try {
            $connection->execute(
                "CREATE VIRTUAL TABLE IF NOT EXISTS m_STTTranscriptSearch USING fts5(
                    search_text,
                    content='m_STTResult',
                    content_rowid='id',
                    tokenize='unicode61 remove_diacritics 0'
                )"
            );
            foreach ($this->triggerStatements() as $statement) {
                $connection->execute($statement);
            }
            $connection->execute(
                "UPDATE m_STTTranscriptSearchState SET available = 1, error_code = '' WHERE id = 1"
            );

            return true;
        } catch (Throwable) {
            $connection->execute(
                "UPDATE m_STTTranscriptSearchState
                    SET available = 0, completed_at = 0, error_code = 'fts5_unavailable'
                  WHERE id = 1"
            );

            return false;
        }
    }

    public function backfillBatch(mixed $connection, int $limit = 250): int
    {
        $limit = max(1, min(1000, $limit));

        return $this->transaction->run($connection, function (mixed $database) use ($limit): int {
            $state = $this->state($database);
            if ((int)($state['available'] ?? 0) !== 1 || (int)($state['completed_at'] ?? 0) > 0) {
                return 0;
            }

            $rows = $database->fetchAll(
                'SELECT id, transcript FROM m_STTResult
                  WHERE id > :last_result_id
               ORDER BY id
                  LIMIT ' . $limit,
                PDO::FETCH_ASSOC,
                ['last_result_id' => (int)($state['last_result_id'] ?? 0)]
            );
            if ($rows === []) {
                $this->verify($database);
                $database->execute(
                    'UPDATE m_STTTranscriptSearchState SET completed_at = :completed_at WHERE id = 1',
                    ['completed_at' => time()]
                );

                return 0;
            }

            $lastResultId = 0;
            foreach ($rows as $row) {
                $lastResultId = (int)$row['id'];
                $database->execute(
                    'UPDATE m_STTResult SET search_text = :search_text WHERE id = :id',
                    [
                        'search_text' => TranscriptSearchText::fromTranscript((string)$row['transcript']),
                        'id' => $lastResultId,
                    ]
                );
            }
            $database->execute(
                'UPDATE m_STTTranscriptSearchState SET last_result_id = :last_result_id WHERE id = 1',
                ['last_result_id' => $lastResultId]
            );

            return count($rows);
        });
    }

    public function isReady(mixed $connection): bool
    {
        try {
            $state = $this->state($connection);
        } catch (Throwable) {
            return false;
        }

        return (int)($state['available'] ?? 0) === 1 && (int)($state['completed_at'] ?? 0) > 0;
    }

    public function disable(mixed $connection): void
    {
        $connection->execute(
            "UPDATE m_STTTranscriptSearchState
                SET available = 0, completed_at = 0, error_code = 'index_failed'
              WHERE id = 1"
        );
    }

    /** @return array<string, mixed> */
    private function state(mixed $connection): array
    {
        $rows = $connection->fetchAll(
            'SELECT last_result_id, completed_at, available, error_code
               FROM m_STTTranscriptSearchState WHERE id = 1',
            PDO::FETCH_ASSOC
        );

        return $rows[0] ?? [];
    }

    private function verify(mixed $connection): void
    {
        $connection->execute("INSERT INTO m_STTTranscriptSearch(m_STTTranscriptSearch) VALUES ('integrity-check')");
    }

    /** @return list<string> */
    private function triggerStatements(): array
    {
        return [
            "CREATE TRIGGER IF NOT EXISTS tr_STTTranscriptSearch_ai
                AFTER INSERT ON m_STTResult WHEN new.search_text <> '' BEGIN
                    INSERT INTO m_STTTranscriptSearch(rowid, search_text) VALUES (new.id, new.search_text);
                END",
            "CREATE TRIGGER IF NOT EXISTS tr_STTTranscriptSearch_ad
                AFTER DELETE ON m_STTResult WHEN old.search_text <> '' BEGIN
                    INSERT INTO m_STTTranscriptSearch(m_STTTranscriptSearch, rowid, search_text)
                    VALUES ('delete', old.id, old.search_text);
                END",
            "CREATE TRIGGER IF NOT EXISTS tr_STTTranscriptSearch_au_delete
                AFTER UPDATE OF search_text ON m_STTResult WHEN old.search_text <> '' BEGIN
                    INSERT INTO m_STTTranscriptSearch(m_STTTranscriptSearch, rowid, search_text)
                    VALUES ('delete', old.id, old.search_text);
                END",
            "CREATE TRIGGER IF NOT EXISTS tr_STTTranscriptSearch_au_insert
                AFTER UPDATE OF search_text ON m_STTResult WHEN new.search_text <> '' BEGIN
                    INSERT INTO m_STTTranscriptSearch(rowid, search_text) VALUES (new.id, new.search_text);
                END",
        ];
    }
}
