<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use PDO;
use RuntimeException;

final class WorkerBindingIntegrityMigration
{
    public function migrate(mixed $connection): void
    {
        if (!$connection->isUnderTransaction()) {
            throw new RuntimeException('Worker binding integrity migration requires an active transaction');
        }

        if ($connection->execute('DROP INDEX IF EXISTS i_m_STTWorker_api_key_id_unique') !== true) {
            throw new RuntimeException('Unable to remove obsolete worker API-key binding index');
        }
        if ($connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS i_m_STTWorker_api_key_id_v2_unique
                 ON m_STTWorker(api_key_id)
              WHERE api_key_id IS NOT NULL AND api_version = 2'
        ) !== true) {
            throw new RuntimeException('Unable to create exclusive v2 worker API-key binding index');
        }

        $history = [];
        foreach ($connection->fetchAll(
            'SELECT key, value FROM m_STTSetting
              WHERE key IN (:legacy_key, :current_key, :history_key)',
            PDO::FETCH_ASSOC,
            [
                'legacy_key' => STTSetting::WORKER_API_KEY_ID,
                'current_key' => STTSetting::WORKER_API_KEY_IDS,
                'history_key' => STTSetting::WORKER_API_KEY_HISTORY_IDS,
            ]
        ) as $row) {
            $value = (string)($row['value'] ?? '');
            if ((string)($row['key'] ?? '') === STTSetting::WORKER_API_KEY_ID) {
                $history[] = (int)$value;
                continue;
            }
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    $history[] = (int)$id;
                }
            }
        }
        foreach ($connection->fetchAll(
            'SELECT DISTINCT api_key_id FROM m_STTWorker WHERE api_key_id IS NOT NULL',
            PDO::FETCH_ASSOC
        ) as $row) {
            $history[] = (int)($row['api_key_id'] ?? 0);
        }
        $history = array_values(array_unique(array_filter($history, static fn (int $id): bool => $id > 0)));
        sort($history, SORT_NUMERIC);

        if ($connection->execute(
            'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [
                'key' => STTSetting::WORKER_API_KEY_HISTORY_IDS,
                'value' => json_encode($history) ?: '[]',
            ]
        ) !== true) {
            throw new RuntimeException('Unable to preserve worker API-key authorization history');
        }
    }
}
