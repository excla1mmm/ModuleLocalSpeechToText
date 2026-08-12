<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\Services;
use RuntimeException;
final class JobSubmissionReceiptMigration
{
    public function migrate(mixed $connection): void
    {
        if (!$connection->isUnderTransaction()) throw new RuntimeException('Submission receipt migration requires an active transaction');
        $sql = 'CREATE TABLE IF NOT EXISTS m_STTJobSubmissionReceipt (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id INTEGER NOT NULL,
            worker_id INTEGER NOT NULL,
            lease_token_hash TEXT NOT NULL,
            operation TEXT NOT NULL CHECK(operation IN (\'result\', \'failure\')),
            payload_digest TEXT NOT NULL,
            response_json TEXT NOT NULL,
            http_code INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            UNIQUE(job_id, lease_token_hash)
        )';
        if ($connection->execute($sql) !== true) throw new RuntimeException('Unable to create STT submission receipt table');
        if ($connection->execute('CREATE INDEX IF NOT EXISTS i_m_STTJobSubmissionReceipt_worker ON m_STTJobSubmissionReceipt(worker_id, created_at)') !== true) throw new RuntimeException('Unable to index STT submission receipts');
    }
}
