<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use PDO;
use Throwable;

/**
 * The summary of one transcript (STTResult) and the lease of the worker that
 * produces it. Summaries are the lowest-priority work: a worker asks for one
 * only when no transcription job is pending.
 *
 * @Indexes(
 *     [name='result_id', columns=['result_id'], type='UNIQUE'],
 *     [name='status', columns=['status', 'result_id'], type=''],
 *     [name='finished', columns=['status', 'finished_at'], type='']
 * )
 */
class STTSummary extends ModulesModelsBase
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_DONE = 'done';
    public const string STATUS_ERROR = 'error';
    // The transcript has too little text to summarize.
    public const string STATUS_SKIPPED = 'skipped';

    public const int LEASE_TTL_SECONDS = 600;
    public const int MAX_ATTEMPTS = 3;
    // Fewer characters of recognized text than this is not a conversation.
    public const int MIN_TEXT_CHARS = 40;
    private const int ENQUEUE_BATCH = 200;

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?int $result_id = null;

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
     * Creates the missing summary rows of transcripts created since
     * summarization was turned on, newest first. A transcript with almost no
     * text is stored as skipped so it is not looked at again.
     */
    public static function enqueueMissing(int $enabledAt): int
    {
        $connection = (new self())->getWriteConnection();
        $now = (string)time();
        $connection->execute(
            'INSERT OR IGNORE INTO m_STTSummary (result_id, status, attempts, created_at, updated_at, finished_at)
             SELECT r.id,
                    CASE WHEN LENGTH(TRIM(COALESCE(r.search_text, \'\'))) < CAST(:min_chars AS INTEGER) THEN :skipped ELSE :pending END,
                    0, :now, :now,
                    CASE WHEN LENGTH(TRIM(COALESCE(r.search_text, \'\'))) < CAST(:min_chars AS INTEGER) THEN :now ELSE NULL END
               FROM m_STTResult r
               LEFT JOIN m_STTSummary s ON s.result_id = r.id
              WHERE s.id IS NULL
                -- created_at has INTEGER affinity, so the bound text compares
                -- as a number and the created_at index is used.
                AND r.created_at >= :enabled_at
              ORDER BY r.id DESC
              LIMIT ' . self::ENQUEUE_BATCH,
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
     * Leases the pending summary of the freshest call to the worker, or returns null.
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
            // The freshest call first, by when the call ended, not by when it
            // was transcribed: transcription runs newest call first, so the
            // newest calls get the smallest result ids.
            $next = $connection->fetchOne(
                'SELECT s.id FROM m_STTSummary s
                   LEFT JOIN m_STTResult r ON r.id = s.result_id
                   LEFT JOIN m_STTJob j ON j.id = r.job_id
                  WHERE s.status = :pending' . ($exclude === '' ? '' : ' AND s.id NOT IN (' . $exclude . ')') . '
                  ORDER BY CAST(COALESCE(j.cdr_endtime, 0) AS INTEGER) DESC, s.result_id DESC
                  LIMIT 1',
                PDO::FETCH_ASSOC,
                ['pending' => self::STATUS_PENDING] + $excludeBind
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
                        worker_id = :worker_id,
                        lease_token = :lease_token,
                        lease_expires_at = :lease_expires_at,
                        updated_at = :now,
                        error_msg = NULL
                  WHERE id = :id AND status = :pending',
                [
                    'processing' => self::STATUS_PROCESSING,
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

    /**
     * Summaries of the given transcripts, keyed by result id.
     *
     * @param array<int, int> $resultIds
     * @return array<int, self>
     */
    public static function forResultIds(array $resultIds): array
    {
        $resultIds = array_values(array_unique(array_filter(array_map('intval', $resultIds), static fn (int $id): bool => $id > 0)));
        if ($resultIds === []) {
            return [];
        }

        $summaries = [];
        try {
            foreach (self::find([
                'conditions' => 'result_id IN ({ids:array})',
                'bind' => ['ids' => $resultIds],
            ]) as $summary) {
                if ($summary instanceof self) {
                    $summaries[(int)$summary->result_id] = $summary;
                }
            }
        } catch (Throwable) {
            // The table appears with the module update; a card must still render without it.
            return [];
        }

        return $summaries;
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
