<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\Services;
use PDO;
use RuntimeException;
final class CallSummaryMigration
{
    private const string TABLE_SQL = 'CREATE TABLE IF NOT EXISTS %s (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            call_transcript_id INTEGER NOT NULL,
            revision INTEGER NOT NULL DEFAULT 0,
            content_hash TEXT NOT NULL DEFAULT \'\',
            call_endtime INTEGER,
            status TEXT NOT NULL DEFAULT \'pending\',
            topic TEXT,
            summary TEXT,
            model_used TEXT,
            worker_id INTEGER,
            lease_token TEXT,
            lease_expires_at INTEGER,
            attempts INTEGER NOT NULL DEFAULT 0,
            error_msg TEXT,
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0,
            finished_at INTEGER
        )';

    public function migrate(mixed $connection): void
    {
        if (!$connection->isUnderTransaction()) throw new RuntimeException('Call summary migration requires an active transaction');
        if ($connection->execute(sprintf(self::TABLE_SQL, 'm_STTSummary')) !== true) throw new RuntimeException('Unable to create STT summary table');
        if ($this->hasColumn($connection, 'call_transcript_id')) {
            $this->createCallIndexes($connection);
        }
    }

    /**
     * Indexes of the integration reads: recently finished summaries (the
     * call-summary.completed scan), call parts by transcript and by leg
     * UNIQUEID (the call-dialogs/call-summaries lookup).
     */
    public function migrateIndexes(mixed $connection): void
    {
        if (!$connection->isUnderTransaction()) throw new RuntimeException('Call summary index migration requires an active transaction');
        foreach ([
            'CREATE INDEX IF NOT EXISTS i_m_STTSummary_finished ON m_STTSummary(status, finished_at)',
            'CREATE INDEX IF NOT EXISTS i_m_STTCallTranscriptPart_result ON m_STTCallTranscriptPart(result_id)',
            'CREATE INDEX IF NOT EXISTS i_m_STTCallTranscriptPart_cdr_uniqueid ON m_STTCallTranscriptPart(cdr_uniqueid)',
        ] as $sql) {
            if ($connection->execute($sql) !== true) throw new RuntimeException('Unable to create a call summary integration index');
        }
    }

    /**
     * One summary per call instead of one per leg. A call with a single leg
     * keeps its finished summary, now bound to the call's current revision;
     * every other summary row is dropped and the call is queued again from
     * its published revision with all legs.
     *
     * @return int kept summaries
     */
    public function migrateToCalls(mixed $connection): int
    {
        if (!$connection->isUnderTransaction()) throw new RuntimeException('Call summary migration requires an active transaction');
        if (!$this->hasColumn($connection, 'result_id')) {
            $this->createCallIndexes($connection);
            return 0;
        }

        $steps = [
            'DROP TABLE IF EXISTS m_STTSummary_calls',
            sprintf(self::TABLE_SQL, 'm_STTSummary_calls'),
            'INSERT INTO m_STTSummary_calls
                (call_transcript_id, revision, content_hash, call_endtime, status, topic, summary, model_used,
                 attempts, error_msg, created_at, updated_at, finished_at)
             SELECT c.id, c.current_revision, c.current_content_hash, CAST(j.cdr_endtime AS INTEGER),
                    s.status, s.topic, s.summary, s.model_used, s.attempts, s.error_msg, s.created_at, s.updated_at, s.finished_at
               FROM m_STTCallTranscript c
               JOIN m_STTCallTranscriptPart p ON p.call_transcript_id = c.id AND p.is_current = 1
               JOIN m_STTSummary s ON s.result_id = p.result_id
          LEFT JOIN m_STTJob j ON j.id = p.job_id
              WHERE CAST(c.current_revision AS INTEGER) > 0
                AND s.status IN (\'done\', \'skipped\', \'error\')
                AND (SELECT COUNT(*) FROM m_STTCallTranscriptPart sibling
                      WHERE sibling.call_transcript_id = c.id AND sibling.is_current = 1) = 1',
            'DROP TABLE m_STTSummary',
            'ALTER TABLE m_STTSummary_calls RENAME TO m_STTSummary',
        ];
        foreach ($steps as $sql) {
            if ($connection->execute($sql) !== true) throw new RuntimeException('Unable to move STT summaries to calls');
        }
        $this->createCallIndexes($connection);
        if ($connection->execute('CREATE INDEX IF NOT EXISTS i_m_STTSummary_finished ON m_STTSummary(status, finished_at)') !== true) {
            throw new RuntimeException('Unable to index finished STT summaries');
        }
        $kept = $connection->fetchAll('SELECT COUNT(*) AS kept FROM m_STTSummary', PDO::FETCH_ASSOC);

        return (int)($kept[0]['kept'] ?? 0);
    }

    private function createCallIndexes(mixed $connection): void
    {
        foreach ([
            'CREATE UNIQUE INDEX IF NOT EXISTS i_m_STTSummary_call_transcript_id ON m_STTSummary(call_transcript_id)',
            'CREATE INDEX IF NOT EXISTS i_m_STTSummary_queue ON m_STTSummary(status, call_endtime)',
            // The queue looks only at calls created since summarization was turned on.
            'CREATE INDEX IF NOT EXISTS i_m_STTCallTranscript_created_at ON m_STTCallTranscript(created_at)',
        ] as $sql) {
            if ($connection->execute($sql) !== true) throw new RuntimeException('Unable to index STT summaries by call');
        }
    }

    private function hasColumn(mixed $connection, string $column): bool
    {
        foreach ($connection->fetchAll('PRAGMA table_info(m_STTSummary)', PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }
}
