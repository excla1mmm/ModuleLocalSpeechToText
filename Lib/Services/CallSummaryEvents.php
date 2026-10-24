<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;

/**
 * Publishes `call-summary.completed` into m_STTCallTranscriptEvent once the
 * summary of a published call revision is final (done, skipped or error), so
 * importers learn when the summary in the call detail is ready.
 *
 * One event per aggregate revision (the event_key is unique), and only for a
 * summary made from that revision's content: a call that changes is
 * summarized again and gets its event then. Calls transcribed before
 * summarization was turned on never get a summary and never an event.
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
               FROM m_STTSummary s
               JOIN m_STTCallTranscript c ON c.id = s.call_transcript_id
               JOIN m_STTCallTranscriptRevision rev
                 ON rev.call_transcript_id = c.id AND rev.revision = c.current_revision
              WHERE s.status IN (:done, :skipped, :error)
                -- finished_at has INTEGER affinity: a range on i_m_STTSummary_finished
                AND s.finished_at >= :since
                AND s.content_hash = c.current_content_hash
                AND CAST(c.current_revision AS INTEGER) > 0
                AND c.pending_content_hash = :empty
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
