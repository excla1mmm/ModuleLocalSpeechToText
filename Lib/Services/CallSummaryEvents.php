<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;

/**
 * Publishes `call-summary.completed` into m_STTCallTranscriptEvent once every
 * part of a published call revision has a final summary (done, skipped or
 * error), so importers learn when the summary in the call detail is ready.
 *
 * One event per aggregate revision (the event_key is unique). A call that
 * changes while it is still waiting publishes nothing until its new revision
 * is published. Parts transcribed before summarization was turned on never get
 * a summary and do not hold the event back.
 *
 * Summary events are served only to consumers that opt in with
 * include_summaries; the default event feed keeps its completed/updated-only
 * contract.
 */
final class CallSummaryEvents
{
    public const string EVENT_TYPE = 'call-summary.completed';
    private const array FINAL_STATUSES = ['done', 'skipped', 'error'];
    // Only calls whose summaries finished this recently are looked at, so a
    // scan never walks the whole call history.
    private const int LOOKBACK_SECONDS = 7 * 86400;

    public static function eventKey(int $aggregateId, int $revision): string
    {
        return sprintf('%s:%d:%d', self::EVENT_TYPE, $aggregateId, $revision);
    }

    /** @return int published events */
    public function publishReady(mixed $connection, ?int $now = null, int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $now ??= time();
        $rows = $connection->fetchAll(
            'SELECT c.id, c.call_id, c.current_revision, rev.content_hash, rev.parts_hash, rev.completeness_status
               FROM m_STTCallTranscript c
               JOIN m_STTCallTranscriptRevision rev
                 ON rev.call_transcript_id = c.id AND rev.revision = c.current_revision
              WHERE CAST(c.current_revision AS INTEGER) > 0
                AND c.pending_content_hash = :empty
                AND c.id IN (
                    SELECT p.call_transcript_id FROM m_STTSummary s
                      JOIN m_STTCallTranscriptPart p ON p.result_id = s.result_id AND p.is_current = 1
                     WHERE s.status IN (:done, :skipped, :error)
                       -- finished_at has INTEGER affinity: a range on i_m_STTSummary_finished
                       AND s.finished_at >= :since
                )
                AND NOT EXISTS (
                    SELECT 1 FROM m_STTCallTranscriptPart p
                 LEFT JOIN m_STTSummary s ON s.result_id = p.result_id
                 LEFT JOIN m_STTResult r ON r.id = p.result_id
                     WHERE p.call_transcript_id = c.id AND p.is_current = 1
                       AND ((s.id IS NOT NULL AND s.status NOT IN (:done, :skipped, :error))
                            -- not queued yet: the queue adds parts lazily
                            OR (s.id IS NULL AND CAST(COALESCE(r.created_at, 0) AS INTEGER) >= COALESCE(
                                (SELECT CAST(value AS INTEGER) FROM m_STTSetting WHERE key = :enabled_at_key), 0)))
                )
                AND NOT EXISTS (
                    SELECT 1 FROM m_STTCallTranscriptEvent e
                     WHERE e.event_key = :event_type || \':\' || c.id || \':\' || c.current_revision
                )
              ORDER BY CAST(c.id AS INTEGER) ASC
              LIMIT ' . $limit,
            PDO::FETCH_ASSOC,
            [
                'empty' => '',
                'done' => self::FINAL_STATUSES[0],
                'skipped' => self::FINAL_STATUSES[1],
                'error' => self::FINAL_STATUSES[2],
                'event_type' => self::EVENT_TYPE,
                'enabled_at_key' => 'summarization_enabled_at',
                'since' => $now - self::LOOKBACK_SECONDS,
            ]
        );
        if ($rows === []) {
            return 0;
        }

        // After every cursor a consumer may already hold, as other events do.
        $frontier = $connection->fetchAll(
            'SELECT COALESCE(MAX(CAST(created_at AS INTEGER)), 0) AS cursor_frontier FROM m_STTCallTranscriptEvent',
            PDO::FETCH_ASSOC
        );
        $createdAt = max($now, (int)($frontier[0]['cursor_frontier'] ?? 0));

        $published = 0;
        foreach ($rows as $row) {
            $aggregateId = (int)($row['id'] ?? 0);
            $revision = (int)($row['current_revision'] ?? 0);
            $connection->execute(
                'INSERT OR IGNORE INTO m_STTCallTranscriptEvent
                    (event_type, event_key, call_transcript_id, call_id, revision, content_hash, parts_hash,
                     completeness_status, created_at)
                 VALUES (:event_type, :event_key, :aggregate_id, :call_id, :revision, :content_hash, :parts_hash,
                     :completeness_status, :created_at)',
                [
                    'event_type' => self::EVENT_TYPE,
                    'event_key' => self::eventKey($aggregateId, $revision),
                    'aggregate_id' => $aggregateId,
                    'call_id' => (string)($row['call_id'] ?? ''),
                    'revision' => $revision,
                    'content_hash' => (string)($row['content_hash'] ?? ''),
                    'parts_hash' => (string)($row['parts_hash'] ?? ''),
                    'completeness_status' => (string)($row['completeness_status'] ?? ''),
                    'created_at' => $createdAt,
                ]
            );
            $published++;
        }

        return $published;
    }
}
