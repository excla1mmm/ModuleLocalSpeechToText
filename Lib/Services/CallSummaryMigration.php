<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\Services;
use RuntimeException;
final class CallSummaryMigration
{
    public function migrate(mixed $connection): void
    {
        if (!$connection->isUnderTransaction()) throw new RuntimeException('Call summary migration requires an active transaction');
        $sql = 'CREATE TABLE IF NOT EXISTS m_STTSummary (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            result_id INTEGER NOT NULL,
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
        if ($connection->execute($sql) !== true) throw new RuntimeException('Unable to create STT summary table');
        if ($connection->execute('CREATE UNIQUE INDEX IF NOT EXISTS i_m_STTSummary_result_id ON m_STTSummary(result_id)') !== true) throw new RuntimeException('Unable to index STT summaries by transcript');
        if ($connection->execute('CREATE INDEX IF NOT EXISTS i_m_STTSummary_status ON m_STTSummary(status, result_id)') !== true) throw new RuntimeException('Unable to index STT summaries by status');
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
}
