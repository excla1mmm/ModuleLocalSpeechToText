<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;
use RuntimeException;

/**
 * Publishes a `call-transcript.deleted` tombstone into m_STTCallTranscriptEvent
 * for every call aggregate that is about to be removed, so downstream importers
 * learn about manual deletions and retention purges.
 *
 * Two phases inside the deleting transaction: prepare() BEFORE anything is
 * deleted (it reads each aggregate's last revision and the pre-deletion cursor
 * frontier, so a tombstone sorts after every cursor a consumer may already
 * hold), publish() AFTER the aggregate's rows, its old events included, are
 * gone (tombstones carry the aggregate id and would otherwise be deleted with
 * them). Aggregate ids are AUTOINCREMENT and never reused, which keeps the
 * tombstone event_key unique.
 *
 * Tombstones are served only to consumers that opt in with include_deleted;
 * the default event feed keeps its completed/updated-only contract.
 */
final class CallTranscriptDeletionEvents
{
    public const string EVENT_TYPE = 'call-transcript.deleted';
    public const string REASON_MANUAL = 'manual';
    public const string REASON_RETENTION = 'retention';
    public const array REASONS = [self::REASON_MANUAL, self::REASON_RETENTION];
    /** Stored in the NOT NULL completeness_status column; never published. */
    public const string STORED_STATUS = 'deleted';
    /** Tombstones older than this are pruned on the next deletion. */
    public const int TOMBSTONE_TTL_SECONDS = 90 * 86400;

    /**
     * @param array<int, int> $callTranscriptIds
     * @return array{created_at: int, aggregates: array<int, int>} aggregate id => last revision
     */
    public function prepare(mixed $connection, array $callTranscriptIds, int $now): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $callTranscriptIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return ['created_at' => $now, 'aggregates' => []];
        }

        $placeholders = [];
        $bind = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':aggregate_' . $index;
            $bind['aggregate_' . $index] = $id;
        }
        $aggregates = [];
        foreach ($connection->fetchAll(
            'SELECT id, current_revision FROM m_STTCallTranscript WHERE id IN (' . implode(', ', $placeholders) . ')',
            PDO::FETCH_ASSOC,
            $bind
        ) as $row) {
            $aggregateId = (int)($row['id'] ?? 0);
            if ($aggregateId > 0) {
                $aggregates[$aggregateId] = max(0, (int)($row['current_revision'] ?? 0));
            }
        }

        $frontierRows = $connection->fetchAll(
            'SELECT COALESCE(MAX(CAST(created_at AS INTEGER)), 0) AS cursor_frontier FROM m_STTCallTranscriptEvent',
            PDO::FETCH_ASSOC
        );

        return [
            'created_at' => max($now, (int)($frontierRows[0]['cursor_frontier'] ?? 0)),
            'aggregates' => $aggregates,
        ];
    }

    /** @param array{created_at: int, aggregates: array<int, int>} $prepared */
    public function publish(mixed $connection, array $prepared, string $reason, int $now): int
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new RuntimeException('Unsupported transcript deletion reason');
        }
        if ($prepared['aggregates'] === []) {
            return 0;
        }

        $recorded = 0;
        foreach ($prepared['aggregates'] as $aggregateId => $revision) {
            if ($connection->execute(
                'INSERT OR IGNORE INTO m_STTCallTranscriptEvent
                    (event_type, event_key, call_transcript_id, call_id, revision, content_hash, parts_hash, completeness_status, created_at)
                 VALUES (:event_type, :event_key, :aggregate_id, :call_id, :revision, :empty_hash, :empty_parts, :status, :created_at)',
                [
                    'event_type' => self::EVENT_TYPE,
                    'event_key' => self::eventKey($aggregateId, $reason),
                    'aggregate_id' => $aggregateId,
                    // The deleted call is identified only by its aggregate id.
                    'call_id' => '',
                    'revision' => $revision,
                    'empty_hash' => '',
                    'empty_parts' => '',
                    'status' => self::STORED_STATUS,
                    'created_at' => $prepared['created_at'],
                ]
            ) !== true) {
                throw new RuntimeException('Unable to publish transcript deletion event');
            }
            $recorded++;
        }

        if ($connection->execute(
            'DELETE FROM m_STTCallTranscriptEvent WHERE event_type = :event_type AND CAST(created_at AS INTEGER) < :cutoff',
            ['event_type' => self::EVENT_TYPE, 'cutoff' => $now - self::TOMBSTONE_TTL_SECONDS]
        ) !== true) {
            throw new RuntimeException('Unable to prune expired transcript deletion events');
        }

        return $recorded;
    }

    public static function eventKey(int $aggregateId, string $reason): string
    {
        return self::EVENT_TYPE . ':' . $aggregateId . ':' . $reason;
    }

    /** Reason encoded in a tombstone event_key, or null for a foreign key shape. */
    public static function reasonFromEventKey(string $eventKey): ?string
    {
        $parts = explode(':', $eventKey);
        $reason = count($parts) === 3 && $parts[0] === self::EVENT_TYPE ? $parts[2] : '';

        return in_array($reason, self::REASONS, true) ? $reason : null;
    }
}
