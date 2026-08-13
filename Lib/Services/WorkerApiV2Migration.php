<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use PDO;
use RuntimeException;

final class WorkerApiV2Migration
{
    public function migrate(mixed $connection): void
    {
        if ($this->markerApplied($connection)) {
            return;
        }
        if (!$connection->isUnderTransaction()) {
            throw new RuntimeException('Worker API v2 migration requires an active transaction');
        }

        $columns = $this->workerColumns($connection);
        if (!isset($columns['api_version'])) {
            $this->execute(
                $connection,
                'ALTER TABLE m_STTWorker ADD COLUMN api_version INTEGER NOT NULL DEFAULT 1'
            );
        }
        if (!isset($columns['app_version'])) {
            $this->execute(
                $connection,
                "ALTER TABLE m_STTWorker ADD COLUMN app_version TEXT NOT NULL DEFAULT ''"
            );
        }
        if (!isset($columns['updated_at'])) {
            $this->execute(
                $connection,
                'ALTER TABLE m_STTWorker ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0'
            );
        }

        $this->execute(
            $connection,
            "UPDATE m_STTWorker
                SET api_version = 1,
                    status = 'offline',
                    has_settings_changed = 1,
                    updated_at = CASE
                        WHEN updated_at = 0 THEN CAST(strftime('%s','now') AS INTEGER)
                        ELSE updated_at
                    END
              WHERE api_version < 2"
        );
        $this->execute(
            $connection,
            "UPDATE m_STTJob
                SET status = 'pending',
                    worker_id = NULL,
                    started_at = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    updated_at = CAST(strftime('%s','now') AS INTEGER),
                    error_msg = 'worker_api_migration'
              WHERE status = 'processing'
                AND (
                    worker_id IS NULL
                    OR worker_id IN (SELECT id FROM m_STTWorker WHERE api_version < 2)
                )"
        );

        $this->execute(
            $connection,
            'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [
                'key' => STTSetting::WORKER_API_CONTRACT_MIGRATION_V2,
                'value' => '1',
            ]
        );
    }

    private function markerApplied(mixed $connection): bool
    {
        $rows = $connection->fetchAll(
            'SELECT value FROM m_STTSetting WHERE key = :key LIMIT 1',
            PDO::FETCH_ASSOC,
            ['key' => STTSetting::WORKER_API_CONTRACT_MIGRATION_V2]
        );

        return (string)($rows[0]['value'] ?? '') === '1';
    }

    /** @return array<string, true> */
    private function workerColumns(mixed $connection): array
    {
        $columns = [];
        foreach ($connection->fetchAll('PRAGMA table_info(m_STTWorker)', PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    /** @param array<string, mixed> $bind */
    private function execute(mixed $connection, string $sql, array $bind = []): void
    {
        if ($connection->execute($sql, $bind) !== true) {
            throw new RuntimeException('Worker API v2 migration statement failed');
        }
    }
}
