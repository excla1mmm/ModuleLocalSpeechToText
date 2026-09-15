<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;
use RuntimeException;

/**
 * Owns the partial unique indexes that model annotations cannot express.
 *
 * Core's annotation pass drops every index that is neither annotated nor named
 * like an SQLite auto-index, and a Core table rebuild drops all of them. Builds
 * that created these indexes under their earlier names therefore lost the
 * invariants on the first boot after install. ensure() demotes rows that would
 * violate them, recreates both indexes under names Core preserves, and removes
 * the legacy names. It is idempotent and safe to run after every Core pass.
 */
final class ModuleOwnedIndexService
{
    public const string CURRENT_PART_INDEX = 'm_STTCallTranscriptPart_sqlite_autoindex_current_result';
    public const string WORKER_API_KEY_INDEX = 'm_STTWorker_sqlite_autoindex_api_key_id_v2_unique';

    private const array LEGACY_INDEXES = [
        'm_STTCallTranscriptPart_current_result',
        'i_m_STTWorker_api_key_id_v2_unique',
    ];

    // The newest part wins: CallTranscriptRevisionService demotes the previous
    // current part before inserting its replacement.
    private const string SHADOWED_CURRENT_PART = 'is_current = 1 AND EXISTS (
            SELECT 1 FROM m_STTCallTranscriptPart newer
             WHERE newer.call_transcript_id = m_STTCallTranscriptPart.call_transcript_id
               AND newer.result_id = m_STTCallTranscriptPart.result_id
               AND newer.is_current = 1
               AND newer.id > m_STTCallTranscriptPart.id
        )';

    // The most recently seen v2 worker keeps the key; the others are unbound the
    // same way revoking the key unbinds them.
    private const string SHADOWED_WORKER_BINDING = 'api_key_id IS NOT NULL AND api_version = 2 AND EXISTS (
            SELECT 1 FROM m_STTWorker keeper
             WHERE keeper.api_key_id = m_STTWorker.api_key_id
               AND keeper.api_version = 2
               AND (
                   CAST(COALESCE(keeper.last_seen, 0) AS INTEGER) > CAST(COALESCE(m_STTWorker.last_seen, 0) AS INTEGER)
                   OR (
                       CAST(COALESCE(keeper.last_seen, 0) AS INTEGER) = CAST(COALESCE(m_STTWorker.last_seen, 0) AS INTEGER)
                       AND keeper.id > m_STTWorker.id
                   )
               )
        )';

    /** @return array{demoted_parts: int, unbound_workers: int} */
    public function ensure(mixed $connection): array
    {
        if (!$connection->isUnderTransaction()) {
            throw new RuntimeException('Module-owned index repair requires an active transaction');
        }

        $demotedParts = $this->count($connection, 'm_STTCallTranscriptPart', self::SHADOWED_CURRENT_PART);
        if ($demotedParts > 0) {
            $this->execute(
                $connection,
                'UPDATE m_STTCallTranscriptPart SET is_current = 0 WHERE ' . self::SHADOWED_CURRENT_PART
            );
        }
        $unboundWorkers = $this->count($connection, 'm_STTWorker', self::SHADOWED_WORKER_BINDING);
        if ($unboundWorkers > 0) {
            $this->execute(
                $connection,
                'UPDATE m_STTWorker SET api_key_id = NULL WHERE ' . self::SHADOWED_WORKER_BINDING
            );
        }

        $this->execute(
            $connection,
            'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::CURRENT_PART_INDEX . '
                ON m_STTCallTranscriptPart (call_transcript_id, result_id)
             WHERE is_current = 1'
        );
        $this->execute(
            $connection,
            'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::WORKER_API_KEY_INDEX . '
                ON m_STTWorker (api_key_id)
             WHERE api_key_id IS NOT NULL AND api_version = 2'
        );
        foreach (self::LEGACY_INDEXES as $legacyIndex) {
            $this->execute($connection, 'DROP INDEX IF EXISTS ' . $legacyIndex);
        }

        $this->assertPartialUnique($connection, self::CURRENT_PART_INDEX);
        $this->assertPartialUnique($connection, self::WORKER_API_KEY_INDEX);

        return ['demoted_parts' => $demotedParts, 'unbound_workers' => $unboundWorkers];
    }

    private function count(mixed $connection, string $table, string $condition): int
    {
        $rows = $connection->fetchAll(
            'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE ' . $condition,
            PDO::FETCH_ASSOC
        );

        return (int)($rows[0]['total'] ?? 0);
    }

    private function assertPartialUnique(mixed $connection, string $name): void
    {
        $rows = $connection->fetchAll(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = :name",
            PDO::FETCH_ASSOC,
            ['name' => $name]
        );
        $sql = strtoupper((string)($rows[0]['sql'] ?? ''));
        if (!str_contains($sql, 'UNIQUE INDEX') || !str_contains($sql, ' WHERE ')) {
            throw new RuntimeException('Module-owned partial unique index is missing: ' . $name);
        }
    }

    private function execute(mixed $connection, string $sql): void
    {
        if ($connection->execute($sql) !== true) {
            throw new RuntimeException('Module-owned index statement failed');
        }
    }
}
