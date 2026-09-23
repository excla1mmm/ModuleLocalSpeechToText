<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;

require_once __DIR__ . '/CallTranscriptPayloadCodec.php';

/**
 * One-time, idempotent rewrite of call transcript storage into the compact
 * format (schema migration "compact call transcript storage"):
 *
 * - revision payloads and pending payloads are re-encoded by
 *   CallTranscriptPayloadCodec (derived fields dropped, JSON deflated);
 *   content_hash / parts_hash / revision numbers are not touched;
 * - superseded parts drop their segment copy (never read again);
 * - current parts drop their segment copy only when it is identical to the
 *   segments of the result they point to, which is where readers load them
 *   from afterwards. Parts that differ keep their copy and stay readable.
 *
 * Every step only touches rows still in the legacy form, so re-running the
 * compaction (for example after a restored setup snapshot) is safe.
 */
final class CallTranscriptStorageCompaction
{
    private const string SEGMENTS_IN_RESULT = '[]';

    public function __construct(
        private readonly CallTranscriptPayloadCodec $codec = new CallTranscriptPayloadCodec(),
        private readonly int $batchSize = 200,
    ) {
    }

    /**
     * @return array{revisions: int, pending: int, superseded_parts: int, current_parts: int, kept_parts: int}
     */
    public function run(mixed $database): array
    {
        return [
            'revisions' => $this->recodePayloads(
                $database,
                'm_STTCallTranscriptRevision',
                'payload',
                ''
            ),
            'pending' => $this->recodePayloads(
                $database,
                'm_STTCallTranscript',
                'pending_payload',
                " AND pending_payload <> ''"
            ),
            'superseded_parts' => $this->releaseSupersededPartSegments($database),
        ] + $this->releaseCurrentPartSegments($database);
    }

    private function recodePayloads(mixed $database, string $table, string $column, string $extraCondition): int
    {
        $changed = 0;
        $lastId = 0;
        do {
            $rows = $database->fetchAll(
                "SELECT id, {$column} AS stored FROM {$table}
                  WHERE id > :last_id{$extraCondition}
                    AND {$column} NOT LIKE '" . CallTranscriptPayloadCodec::COMPRESSED_PREFIX . "%'
               ORDER BY id
                  LIMIT " . max(1, $this->batchSize),
                PDO::FETCH_ASSOC,
                ['last_id' => $lastId]
            );
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $encoded = $this->codec->recode((string)$row['stored']);
                if ($encoded === null) {
                    continue;
                }
                $database->execute(
                    "UPDATE {$table} SET {$column} = :stored WHERE id = :id",
                    ['stored' => $encoded, 'id' => $lastId]
                );
                $changed++;
            }
        } while ($rows !== []);

        return $changed;
    }

    private function releaseSupersededPartSegments(mixed $database): int
    {
        $rows = $database->fetchAll(
            "SELECT COUNT(*) AS total FROM m_STTCallTranscriptPart
              WHERE CAST(is_current AS INTEGER) <> 1 AND segments_json <> :empty",
            PDO::FETCH_ASSOC,
            ['empty' => self::SEGMENTS_IN_RESULT]
        );
        $total = (int)($rows[0]['total'] ?? 0);
        if ($total > 0) {
            $database->execute(
                "UPDATE m_STTCallTranscriptPart SET segments_json = :released
                  WHERE CAST(is_current AS INTEGER) <> 1 AND segments_json <> :legacy_marker",
                ['released' => self::SEGMENTS_IN_RESULT, 'legacy_marker' => self::SEGMENTS_IN_RESULT]
            );
        }

        return $total;
    }

    /** @return array{current_parts: int, kept_parts: int} */
    private function releaseCurrentPartSegments(mixed $database): array
    {
        $released = 0;
        $kept = 0;
        $lastId = 0;
        do {
            $rows = $database->fetchAll(
                "SELECT p.id, p.segments_json, r.transcript
                   FROM m_STTCallTranscriptPart p
                   LEFT JOIN m_STTResult r ON r.id = p.result_id
                  WHERE p.id > :last_id
                    AND CAST(p.is_current AS INTEGER) = 1
                    AND p.segments_json <> :empty
               ORDER BY p.id
                  LIMIT " . max(1, $this->batchSize),
                PDO::FETCH_ASSOC,
                ['last_id' => $lastId, 'empty' => self::SEGMENTS_IN_RESULT]
            );
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $stored = $this->segments((string)$row['segments_json']);
                if ($stored === null
                    || $row['transcript'] === null
                    || $stored !== $this->segments((string)$row['transcript'])
                ) {
                    $kept++;
                    continue;
                }
                $database->execute(
                    'UPDATE m_STTCallTranscriptPart SET segments_json = :empty WHERE id = :id',
                    ['empty' => self::SEGMENTS_IN_RESULT, 'id' => $lastId]
                );
                $released++;
            }
        } while ($rows !== []);

        return ['current_parts' => $released, 'kept_parts' => $kept];
    }

    /** @return array<int, mixed>|null */
    private function segments(string $json): ?array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : null;
    }
}
