<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use PDO;
use Throwable;

/**
 * The summary of one call (STTCallTranscript), made from its published
 * revision with every leg in it, and the lease of the worker that produces
 * it. Summaries are the lowest-priority work: a worker asks for one only when
 * no transcription job is pending.
 *
 * revision/content_hash name the revision the summary was made (or is being
 * made) from; a call that publishes different content later is summarized
 * again.
 *
 * @Indexes(
 *     [name='call_transcript_id', columns=['call_transcript_id'], type='UNIQUE'],
 *     [name='queue', columns=['status', 'call_endtime'], type=''],
 *     [name='finished', columns=['status', 'finished_at'], type='']
 * )
 */
class STTSummary extends ModulesModelsBase
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_DONE = 'done';
    public const string STATUS_ERROR = 'error';
    // The call has too little text to summarize.
    public const string STATUS_SKIPPED = 'skipped';

    public const int LEASE_TTL_SECONDS = 600;
    public const int MAX_ATTEMPTS = 3;
    // Fewer characters of recognized text than this is not a conversation.
    public const int MIN_TEXT_CHARS = 40;
    private const int ENQUEUE_BATCH = 200;

    // Recognized characters of the call's current parts, and when its latest
    // leg ended: the queue order.
    private const string CALL_FACTS_SQL = '(SELECT COALESCE(SUM(LENGTH(TRIM(COALESCE(r.search_text, \'\')))), 0)
               FROM m_STTCallTranscriptPart p JOIN m_STTResult r ON r.id = p.result_id
              WHERE p.call_transcript_id = c.id AND p.is_current = 1) AS text_chars,
            (SELECT MAX(CAST(j.cdr_endtime AS INTEGER)) FROM m_STTJob j WHERE j.call_id = c.call_id) AS call_endtime';
    // A published call with no newer revision staged and no leg still transcribed.
    private const string CALL_SETTLED_SQL = 'CAST(c.current_revision AS INTEGER) > 0
            AND c.pending_content_hash = \'\'
            AND NOT EXISTS (SELECT 1 FROM m_STTJob j WHERE j.call_id = c.call_id AND j.status IN (\'pending\', \'processing\'))';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $call_transcript_id = null;

    /**
     * @Column(type="integer", nullable=false, default=0)
     */
    public ?string $revision = '0';

    /**
     * @Column(type="string", nullable=false, allow_empty_string=true, default="")
     */
    public ?string $content_hash = '';

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $call_endtime = null;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $status = self::STATUS_PENDING;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $topic = null;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $summary = null;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $model_used = null;

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $worker_id = null;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $lease_token = null;

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $lease_expires_at = null;

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $attempts = '0';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $error_msg = null;

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $created_at = '0';

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $updated_at = '0';

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $finished_at = null;

    public function initialize(): void
    {
        $this->setSource('m_STTSummary');
        parent::initialize();
    }

    /**
     * Creates the missing summary rows of calls created since summarization
     * was turned on, once the call is published with all its legs, newest
     * first. A call with almost no text is stored as skipped so it is not
     * looked at again.
     */
    public static function enqueueMissing(int $enabledAt): int
    {
        $connection = (new self())->getWriteConnection();
        $now = (string)time();
        $connection->execute(
            'INSERT OR IGNORE INTO m_STTSummary
                (call_transcript_id, revision, content_hash, call_endtime, status, attempts, created_at, updated_at, finished_at)
             SELECT id, current_revision, current_content_hash, call_endtime,
                    CASE WHEN text_chars < CAST(:min_chars AS INTEGER) THEN :skipped ELSE :pending END,
                    0, :now, :now,
                    CASE WHEN text_chars < CAST(:min_chars AS INTEGER) THEN :now ELSE NULL END
               FROM (SELECT c.id, c.current_revision, c.current_content_hash, ' . self::CALL_FACTS_SQL . '
                       FROM m_STTCallTranscript c
                       LEFT JOIN m_STTSummary s ON s.call_transcript_id = c.id
                      WHERE s.id IS NULL
                        -- created_at has INTEGER affinity, so the bound text compares
                        -- as a number and the created_at index is used.
                        AND c.created_at >= :enabled_at
                        AND ' . self::CALL_SETTLED_SQL . '
                      ORDER BY c.id DESC
                      LIMIT ' . self::ENQUEUE_BATCH . ')',
            [
                'min_chars' => self::MIN_TEXT_CHARS,
                'skipped' => self::STATUS_SKIPPED,
                'pending' => self::STATUS_PENDING,
                'now' => $now,
                'enabled_at' => $enabledAt,
            ]
        );

        return (int)$connection->affectedRows();
    }

    /**
     * Queues again the summaries of calls that published different content
     * since (a leg that arrived after publication, a redone transcript). A
     * summary being made right now is left to finish and looked at on a later
     * pass.
     */
    public static function requeueChangedCalls(): int
    {
        $connection = (new self())->getWriteConnection();
        $rows = $connection->fetchAll(
            'SELECT s.id, s.content_hash AS summary_hash, c.current_revision, c.current_content_hash, ' . self::CALL_FACTS_SQL . '
               FROM m_STTSummary s
               JOIN m_STTCallTranscript c ON c.id = s.call_transcript_id
              WHERE s.status <> :processing
                AND s.content_hash <> c.current_content_hash
                AND ' . self::CALL_SETTLED_SQL . '
              LIMIT ' . self::ENQUEUE_BATCH,
            PDO::FETCH_ASSOC,
            ['processing' => self::STATUS_PROCESSING]
        );
        $requeued = 0;
        foreach ($rows as $row) {
            $now = (string)time();
            $skipped = (int)($row['text_chars'] ?? 0) < self::MIN_TEXT_CHARS;
            $connection->execute(
                'UPDATE m_STTSummary
                    SET revision = :revision, content_hash = :content_hash, call_endtime = :call_endtime,
                        status = :status, topic = NULL, summary = NULL, model_used = NULL, attempts = 0,
                        error_msg = NULL, worker_id = NULL, lease_token = NULL, lease_expires_at = NULL,
                        updated_at = :now, finished_at = :finished_at
                  WHERE id = :id AND status <> :processing AND content_hash = :summary_hash',
                [
                    'revision' => (int)$row['current_revision'],
                    'content_hash' => (string)$row['current_content_hash'],
                    'call_endtime' => $row['call_endtime'] === null ? null : (int)$row['call_endtime'],
                    'status' => $skipped ? self::STATUS_SKIPPED : self::STATUS_PENDING,
                    'now' => $now,
                    'finished_at' => $skipped ? $now : null,
                    'id' => (int)$row['id'],
                    'processing' => self::STATUS_PROCESSING,
                    'summary_hash' => (string)$row['summary_hash'],
                ]
            );
            $requeued += (int)$connection->affectedRows();
        }

        return $requeued;
    }

    /**
     * Leases the pending summary of the freshest call to the worker, or
     * returns null. The lease pins the call's current revision: the prompt is
     * built from it, and a later revision queues the call again.
     *
     * @param array<int, int> $excludeIds summaries already tried in this request
     */
    public static function claimNextForWorker(int $workerId, array $excludeIds = []): ?self
    {
        $exclude = '';
        $excludeBind = [];
        foreach (array_values(array_unique(array_map('intval', $excludeIds))) as $index => $excludeId) {
            $exclude .= ($exclude === '' ? '' : ', ') . ':exclude_' . $index;
            $excludeBind['exclude_' . $index] = $excludeId;
        }
        $connection = (new self())->getWriteConnection();
        self::requeueStaleProcessing();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            // The freshest call first, by when its last leg ended.
            $next = $connection->fetchOne(
                'SELECT s.id, c.current_revision, c.current_content_hash
                   FROM m_STTSummary s
                   JOIN m_STTCallTranscript c ON c.id = s.call_transcript_id
                  WHERE s.status = :pending
                    AND CAST(c.current_revision AS INTEGER) > 0
                    AND c.pending_content_hash = :empty' . ($exclude === '' ? '' : ' AND s.id NOT IN (' . $exclude . ')') . '
                  ORDER BY CAST(COALESCE(s.call_endtime, 0) AS INTEGER) DESC, s.id DESC
                  LIMIT 1',
                PDO::FETCH_ASSOC,
                ['pending' => self::STATUS_PENDING, 'empty' => ''] + $excludeBind
            );
            $summary = is_array($next) ? self::findFirstById((int)$next['id']) : null;
            if (!$summary instanceof self) {
                return null;
            }

            $now = time();
            $leaseToken = bin2hex(random_bytes(16));
            $leaseExpiresAt = $now + self::LEASE_TTL_SECONDS;
            $connection->execute(
                'UPDATE m_STTSummary
                    SET status = :processing,
                        revision = :revision,
                        content_hash = :content_hash,
                        worker_id = :worker_id,
                        lease_token = :lease_token,
                        lease_expires_at = :lease_expires_at,
                        updated_at = :now,
                        error_msg = NULL
                  WHERE id = :id AND status = :pending',
                [
                    'processing' => self::STATUS_PROCESSING,
                    'revision' => (int)$next['current_revision'],
                    'content_hash' => (string)$next['current_content_hash'],
                    'worker_id' => $workerId,
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => (string)$leaseExpiresAt,
                    'now' => (string)$now,
                    'id' => (int)$summary->id,
                    'pending' => self::STATUS_PENDING,
                ]
            );
            if ((int)$connection->affectedRows() === 1) {
                $summary->status = self::STATUS_PROCESSING;
                $summary->revision = (string)(int)$next['current_revision'];
                $summary->content_hash = (string)$next['current_content_hash'];
                $summary->worker_id = (string)$workerId;
                $summary->lease_token = $leaseToken;
                $summary->lease_expires_at = (string)$leaseExpiresAt;
                $summary->updated_at = (string)$now;
                $summary->error_msg = null;

                return $summary;
            }
        }

        return null;
    }

    /** A lease that ran out spends one attempt, like a reported failure. */
    public static function requeueStaleProcessing(): int
    {
        $connection = (new self())->getWriteConnection();
        $now = (string)time();
        $connection->execute(
            'UPDATE m_STTSummary
                SET attempts = CAST(attempts AS INTEGER) + 1,
                    status = CASE WHEN CAST(attempts AS INTEGER) + 1 >= CAST(:max_attempts AS INTEGER) THEN :error ELSE :pending END,
                    finished_at = CASE WHEN CAST(attempts AS INTEGER) + 1 >= CAST(:max_attempts AS INTEGER) THEN :now ELSE NULL END,
                    error_msg = \'Summary lease timed out\',
                    worker_id = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    updated_at = :now
              WHERE status = :processing
                AND CAST(COALESCE(NULLIF(lease_expires_at, \'\'), \'0\') AS INTEGER) < CAST(:now AS INTEGER)',
            [
                'max_attempts' => self::MAX_ATTEMPTS,
                'error' => self::STATUS_ERROR,
                'pending' => self::STATUS_PENDING,
                'processing' => self::STATUS_PROCESSING,
                'now' => $now,
            ]
        );

        return (int)$connection->affectedRows();
    }

    public function isLeasedTo(int $workerId, string $leaseToken): bool
    {
        return (string)$this->status === self::STATUS_PROCESSING
            && (int)$this->worker_id === $workerId
            && $leaseToken !== ''
            && hash_equals((string)$this->lease_token, $leaseToken);
    }

    public function refreshLeaseForWorker(int $workerId, string $leaseToken): bool
    {
        $leaseExpiresAt = time() + self::LEASE_TTL_SECONDS;
        $updated = $this->updateLeased($workerId, $leaseToken, 'lease_expires_at = :lease_expires_at, updated_at = :now', [
            'lease_expires_at' => (string)$leaseExpiresAt,
        ]);
        if ($updated) {
            $this->lease_expires_at = (string)$leaseExpiresAt;
        }

        return $updated;
    }

    /** Returns the summary to the queue without spending an attempt. */
    public function releaseForWorker(int $workerId, string $leaseToken): bool
    {
        $updated = $this->updateLeased($workerId, $leaseToken, 'status = :pending, worker_id = NULL, lease_token = NULL,
            lease_expires_at = NULL, updated_at = :now', ['pending' => self::STATUS_PENDING]);
        if ($updated) {
            $this->status = self::STATUS_PENDING;
        }

        return $updated;
    }

    public function markDoneForWorker(int $workerId, string $leaseToken, string $topic, string $summary, string $modelUsed): bool
    {
        $updated = $this->updateLeased($workerId, $leaseToken, 'status = :done, topic = :topic, summary = :summary,
            model_used = :model_used, error_msg = NULL, lease_token = NULL, lease_expires_at = NULL,
            updated_at = :now, finished_at = :now', [
            'done' => self::STATUS_DONE,
            'topic' => $topic,
            'summary' => $summary,
            'model_used' => $modelUsed,
        ]);
        if ($updated) {
            $this->status = self::STATUS_DONE;
            $this->topic = $topic;
            $this->summary = $summary;
        }

        return $updated;
    }

    /**
     * Spends one attempt; the summary goes back to the queue or ends in error.
     * A final failure (one no retry can fix) ends it in error at once.
     */
    public function markFailedForWorker(int $workerId, string $leaseToken, string $errorMessage, bool $final = false): bool
    {
        $attempts = (int)$this->attempts + 1;
        $status = $final || $attempts >= self::MAX_ATTEMPTS ? self::STATUS_ERROR : self::STATUS_PENDING;
        $updated = $this->updateLeased($workerId, $leaseToken, 'status = :next_status, attempts = :attempts,
            error_msg = :error_msg, worker_id = NULL, lease_token = NULL, lease_expires_at = NULL,
            updated_at = :now, finished_at = :finished_at', [
            'next_status' => $status,
            'attempts' => $attempts,
            'error_msg' => mb_substr($errorMessage, 0, 1000),
            'finished_at' => $status === self::STATUS_ERROR ? (string)time() : null,
        ]);
        if ($updated) {
            $this->status = $status;
            $this->attempts = (string)$attempts;
        }

        return $updated;
    }

    /** The summary row of a call, or null. */
    public static function forCallTranscriptId(int $callTranscriptId): ?self
    {
        if ($callTranscriptId <= 0) {
            return null;
        }
        try {
            return self::findFirst([
                'conditions' => 'call_transcript_id = :id:',
                'bind' => ['id' => $callTranscriptId],
            ]);
        } catch (Throwable) {
            // The table appears with the module update; a card must still render without it.
            return null;
        }
    }

    /** @param array<string, mixed> $bind */
    private function updateLeased(int $workerId, string $leaseToken, string $assignments, array $bind): bool
    {
        if ($leaseToken === '') {
            return false;
        }
        $connection = $this->getWriteConnection();
        $connection->execute(
            "UPDATE m_STTSummary SET {$assignments}
              WHERE id = :id AND status = :processing AND worker_id = :worker_id AND lease_token = :lease_token",
            $bind + [
                'now' => (string)time(),
                'id' => (int)$this->id,
                'processing' => self::STATUS_PROCESSING,
                'worker_id' => $workerId,
                'lease_token' => $leaseToken,
            ]
        );

        return (int)$connection->affectedRows() === 1;
    }
}
