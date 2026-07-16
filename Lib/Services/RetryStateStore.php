<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use PDO;
use RuntimeException;

final class RetryStateStore
{
    public const string TYPE_DELAYED = 'delayed';
    public const string TYPE_SKIPPED = 'skipped';

    public function __construct(private readonly mixed $connection)
    {
    }

    public function ensureSchema(): void
    {
        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTRetryState (
                cdr_id INTEGER PRIMARY KEY,
                retry_type TEXT NOT NULL,
                payload TEXT NOT NULL,
                reason TEXT NOT NULL,
                first_seen INTEGER NOT NULL,
                last_seen INTEGER NOT NULL,
                next_retry_at INTEGER NOT NULL
            )'
        );
        $this->connection->execute(
            'CREATE INDEX IF NOT EXISTS m_STTRetryState_due
                ON m_STTRetryState (retry_type, next_retry_at, first_seen, cdr_id)'
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function remember(
        int $cdrId,
        string $type,
        array $payload,
        string $reason,
        ?int $now = null
    ): void {
        if ($cdrId <= 0 || !in_array($type, [self::TYPE_DELAYED, self::TYPE_SKIPPED], true)) {
            throw new RuntimeException('Invalid retry-state identity');
        }

        $now ??= time();
        $firstSeen = max(0, (int)($payload['first_seen'] ?? $payload['created_at'] ?? $now));
        $this->connection->execute(
            'INSERT INTO m_STTRetryState
                (cdr_id, retry_type, payload, reason, first_seen, last_seen, next_retry_at)
             VALUES
                (:cdr_id, :retry_type, :payload, :reason, :first_seen, :last_seen, :next_retry_at)
             ON CONFLICT(cdr_id) DO UPDATE SET
                retry_type = excluded.retry_type,
                payload = excluded.payload,
                reason = excluded.reason,
                first_seen = MIN(m_STTRetryState.first_seen, excluded.first_seen),
                last_seen = excluded.last_seen,
                next_retry_at = MIN(m_STTRetryState.next_retry_at, excluded.next_retry_at)',
            [
                'cdr_id' => $cdrId,
                'retry_type' => $type,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'reason' => $reason,
                'first_seen' => $firstSeen,
                'last_seen' => $now,
                'next_retry_at' => $now,
            ]
        );
    }

    /**
     * @return array<int, array{cdr_id: int, retry_type: string, payload: array<string, mixed>, reason: string, first_seen: int, last_seen: int, next_retry_at: int}>
     */
    public function due(string $type, int $limit, ?int $now = null): array
    {
        $now ??= time();
        $rows = $this->connection->fetchAll(
            'SELECT cdr_id, retry_type, payload, reason, first_seen, last_seen, next_retry_at
               FROM m_STTRetryState
              WHERE retry_type = :retry_type AND next_retry_at <= :now
              ORDER BY next_retry_at ASC, first_seen ASC, cdr_id ASC
              LIMIT ' . max(1, min(1000, $limit)),
            PDO::FETCH_ASSOC,
            [
                'retry_type' => $type,
                'now' => $now,
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            try {
                $payload = json_decode((string)($row['payload'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Invalid durable retry payload', 0, $exception);
            }
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid durable retry payload');
            }
            $result[] = [
                'cdr_id' => (int)$row['cdr_id'],
                'retry_type' => (string)$row['retry_type'],
                'payload' => $payload,
                'reason' => (string)$row['reason'],
                'first_seen' => (int)$row['first_seen'],
                'last_seen' => (int)$row['last_seen'],
                'next_retry_at' => (int)$row['next_retry_at'],
            ];
        }

        return $result;
    }

    public function defer(int $cdrId, string $type, int $delaySeconds, ?int $now = null): void
    {
        $now ??= time();
        $this->connection->execute(
            'UPDATE m_STTRetryState
                SET next_retry_at = :next_retry_at, last_seen = :last_seen
              WHERE cdr_id = :cdr_id AND retry_type = :retry_type',
            [
                'next_retry_at' => $now + max(1, $delaySeconds),
                'last_seen' => $now,
                'cdr_id' => $cdrId,
                'retry_type' => $type,
            ]
        );
    }

    public function deferWithBackoff(
        int $cdrId,
        string $type,
        int $baseDelaySeconds,
        int $maxDelaySeconds,
        ?int $now = null
    ): void {
        $now ??= time();
        $baseDelaySeconds = max(1, $baseDelaySeconds);
        $maxDelaySeconds = max($baseDelaySeconds, $maxDelaySeconds);
        $this->connection->execute(
            'UPDATE m_STTRetryState
                SET next_retry_at = :now + MIN(
                        CAST(:max_delay AS INTEGER),
                        MAX(CAST(:base_delay AS INTEGER), (next_retry_at - last_seen) * 2)
                    ),
                    last_seen = :now
              WHERE cdr_id = :cdr_id AND retry_type = :retry_type',
            [
                'now' => $now,
                'base_delay' => $baseDelaySeconds,
                'max_delay' => $maxDelaySeconds,
                'cdr_id' => $cdrId,
                'retry_type' => $type,
            ]
        );
    }

    /**
     * @param array<int|string, mixed> $records
     */
    public function importLegacy(
        array $records,
        string $type,
        string $defaultReason,
        ?int $now = null
    ): void {
        if (!in_array($type, [self::TYPE_DELAYED, self::TYPE_SKIPPED], true)) {
            throw new RuntimeException('Invalid retry-state type');
        }

        $now ??= time();
        foreach ($records as $recordId => $record) {
            $recordId = (int)$recordId;
            if ($recordId <= 0 || !is_array($record)) {
                continue;
            }
            $firstSeen = max(0, (int)($record['first_seen'] ?? $record['created_at'] ?? $now));
            $this->connection->execute(
                'INSERT INTO m_STTRetryState
                    (cdr_id, retry_type, payload, reason, first_seen, last_seen, next_retry_at)
                 VALUES
                    (:cdr_id, :retry_type, :payload, :reason, :first_seen, :last_seen, :next_retry_at)
                 ON CONFLICT(cdr_id) DO NOTHING',
                [
                    'cdr_id' => $recordId,
                    'retry_type' => $type,
                    'payload' => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'reason' => trim((string)($record['reason'] ?? '')) ?: $defaultReason,
                    'first_seen' => $firstSeen,
                    'last_seen' => $now,
                    'next_retry_at' => $now,
                ]
            );
        }
    }

    public function remove(int $cdrId): void
    {
        $this->connection->execute(
            'DELETE FROM m_STTRetryState WHERE cdr_id = :cdr_id',
            ['cdr_id' => $cdrId]
        );
    }

    public function count(?string $type = null): int
    {
        $where = '';
        $bind = [];
        if ($type !== null) {
            $where = ' WHERE retry_type = :retry_type';
            $bind['retry_type'] = $type;
        }
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS retry_count FROM m_STTRetryState' . $where,
            PDO::FETCH_ASSOC,
            $bind
        );

        return (int)($rows[0]['retry_count'] ?? 0);
    }
}
