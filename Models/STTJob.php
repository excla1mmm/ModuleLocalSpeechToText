<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;
use RuntimeException;

/**
 * @Indexes(
 *     [name='queue', columns=['status', 'priority', 'cdr_endtime', 'created_at'], type=''],
 *     [name='file_path', columns=['file_path'], type='UNIQUE']
 * )
 */
class STTJob extends ModulesModelsBase
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_DONE = 'done';
    public const string STATUS_ERROR = 'error';

    // Internal policy, not a user setting: a claim (or heartbeat) grants this many
    // seconds of lease before the job is eligible for stale-lease recovery. Renewing
    // the heartbeat has no cap, so recognition itself can take arbitrarily long.
    public const int PROCESSING_LEASE_TTL_SECONDS = 600;
    private const int MAX_ATTEMPTS = 3;

    public static function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $call_id = '';

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $file_path = '';

    /**
     * @Column(type="string", default="pending", nullable=true)
     */
    public ?string $status = self::STATUS_PENDING;

    /**
     * @Column(type="integer", default="5", nullable=true)
     */
    public ?string $priority = '5';

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?int $worker_id = null;

    /**
     * @Column(type="integer", default="0", nullable=true)
     */
    public ?string $attempts = '0';

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
    public ?string $cdr_endtime = null;

    /** @Column(type="integer", nullable=true) */
    public ?int $cdr_id = null;

    /** @Column(type="string", nullable=true) */
    public ?string $cdr_uniqueid = '';

    /** @Column(type="integer", nullable=true) */
    public ?string $cdr_start_ms = null;

    /** @Column(type="integer", nullable=true) */
    public ?string $cdr_end_ms = null;

    /** @Column(type="string", nullable=true) */
    public ?string $recording_fingerprint = '';

    /** @Column(type="integer", nullable=true) */
    public ?string $recording_duration_sec = null;

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $started_at = null;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $lease_token = null;

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $lease_expires_at = null;

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $finished_at = null;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $error_msg = '';

    public function initialize(): void
    {
        $this->setSource('m_STTJob');
        parent::initialize();

        $this->hasOne(
            'id',
            STTResult::class,
            'job_id',
            [
                'alias' => 'STTResult',
                'foreignKey' => [
                    'allowNulls' => true,
                    'action' => Relation::NO_ACTION,
                ],
            ]
        );
        $this->hasOne(
            'worker_id',
            STTWorker::class,
            'id',
            [
                'alias' => 'STTWorker',
                'foreignKey' => [
                    'allowNulls' => true,
                    'action' => Relation::NO_ACTION,
                ],
            ]
        );
    }

    /**
     * @param array{cdr_id?: int|null, cdr_uniqueid?: string, cdr_start_ms?: int|null, cdr_end_ms?: int|null, recording_fingerprint?: string} $recordingIdentity
     */
    public static function createPending(
        string $callId,
        string $filePath,
        int $priority = 5,
        ?int $cdrEndtime = null,
        array $recordingIdentity = [],
        ?int $recordingDurationSec = null
    ): self
    {
        $createdAt = time();
        $job = new self();
        $job->call_id = $callId;
        $job->file_path = $filePath;
        $job->status = self::STATUS_PENDING;
        $job->priority = (string)$priority;
        $job->attempts = '0';
        $job->created_at = (string)$createdAt;
        $job->updated_at = (string)$createdAt;
        $job->cdr_endtime = (string)($cdrEndtime !== null && $cdrEndtime > 0 ? $cdrEndtime : $createdAt);
        $job->cdr_id = isset($recordingIdentity['cdr_id']) && (int)$recordingIdentity['cdr_id'] > 0
            ? (int)$recordingIdentity['cdr_id']
            : null;
        $job->cdr_uniqueid = trim((string)($recordingIdentity['cdr_uniqueid'] ?? ''));
        $job->cdr_start_ms = isset($recordingIdentity['cdr_start_ms']) && (int)$recordingIdentity['cdr_start_ms'] > 0
            ? (string)(int)$recordingIdentity['cdr_start_ms']
            : null;
        $job->cdr_end_ms = isset($recordingIdentity['cdr_end_ms']) && (int)$recordingIdentity['cdr_end_ms'] > 0
            ? (string)(int)$recordingIdentity['cdr_end_ms']
            : null;
        $job->recording_fingerprint = trim((string)($recordingIdentity['recording_fingerprint'] ?? ''));
        $job->recording_duration_sec = $recordingDurationSec !== null && $recordingDurationSec > 0
            ? (string)$recordingDurationSec
            : null;

        if (!$job->save()) {
            throw new RuntimeException(implode(', ', $job->getMessages()));
        }

        return $job;
    }

    public static function findByFilePath(string $filePath): ?self
    {
        $job = self::findFirst([
            'conditions' => 'file_path = :file_path:',
            'bind' => [
                'file_path' => $filePath,
            ],
        ]);

        return $job instanceof self ? $job : null;
    }

    public static function existsForFilePath(string $filePath): bool
    {
        return self::findByFilePath($filePath) !== null;
    }

    public static function claimNextForWorker(STTWorker $worker): ?self
    {
        $model = new self();
        $connection = $model->getWriteConnection();
        $workerId = (int)$worker->id;
        self::requeueStaleProcessing();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $now = time();
            $leaseToken = self::newLeaseToken();
            $leaseExpiresAt = $now + self::processingTimeoutSeconds();
            $job = self::findFirst([
                'conditions' => 'status = :status:',
                'bind' => [
                    'status' => self::STATUS_PENDING,
                ],
                'order' => 'priority ASC, cdr_endtime DESC, created_at DESC, id DESC',
            ]);

            if ($job === null) {
                return null;
            }

            $connection->execute(
                'UPDATE m_STTJob
                    SET status = :processing,
                        worker_id = :worker_id,
                        started_at = :started_at,
                        updated_at = :updated_at,
                        lease_token = :lease_token,
                        lease_expires_at = :lease_expires_at,
                        finished_at = NULL,
                        error_msg = NULL
                  WHERE id = :id
                    AND status = :pending
                    AND EXISTS (SELECT 1 FROM m_STTWorker WHERE id = :worker_id)',
                [
                    'processing' => self::STATUS_PROCESSING,
                    'worker_id' => $workerId,
                    'started_at' => (string)$now,
                    'updated_at' => (string)$now,
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => (string)$leaseExpiresAt,
                    'id' => (int)$job->id,
                    'pending' => self::STATUS_PENDING,
                ]
            );

            try {
                $affectedRows = (int)$connection->affectedRows();
            } catch (\Throwable $exception) {
                try {
                    if (!self::compensateUnknownClaim($connection, (int)$job->id, $workerId, $leaseToken)) {
                        throw new RuntimeException('STT claim outcome is unknown and compensation did not match');
                    }
                } catch (\Throwable $compensationException) {
                    throw new RuntimeException('STT claim outcome is unknown and compensation failed', 0, $compensationException);
                }
                throw $exception;
            }

            if ($affectedRows === 1) {
                $job->status = self::STATUS_PROCESSING;
                $job->worker_id = $workerId;
                $job->started_at = (string)$now;
                $job->updated_at = (string)$now;
                $job->lease_token = $leaseToken;
                $job->lease_expires_at = (string)$leaseExpiresAt;
                $job->finished_at = null;
                $job->error_msg = null;
                return $job;
            }
        }

        return null;
    }

    private static function compensateUnknownClaim(mixed $connection, int $jobId, int $workerId, string $leaseToken): bool
    {
        $connection->execute(
            'UPDATE m_STTJob SET status = :pending, worker_id = NULL, started_at = NULL,
                    updated_at = :updated_at, lease_token = NULL, lease_expires_at = NULL,
                    finished_at = NULL, error_msg = NULL
              WHERE id = :id AND status = :processing AND worker_id = :worker_id AND lease_token = :lease_token',
            ['pending' => self::STATUS_PENDING, 'updated_at' => (string)time(), 'id' => $jobId,
             'processing' => self::STATUS_PROCESSING, 'worker_id' => $workerId, 'lease_token' => $leaseToken]
        );
        return (int)$connection->affectedRows() === 1;
    }

    public static function requeueStaleProcessing(): int
    {
        $timeout = self::processingTimeoutSeconds();
        if ($timeout <= 0) {
            return 0;
        }

        $model = new self();
        $connection = $model->getWriteConnection();
        $now = time();
        $connection->execute(
            'UPDATE m_STTJob
                SET attempts = CAST(COALESCE(NULLIF(attempts, \'\'), \'0\') AS INTEGER) + 1,
                    status = CASE
                        WHEN CAST(COALESCE(NULLIF(attempts, \'\'), \'0\') AS INTEGER) + 1 >= CAST(:max_attempts AS INTEGER)
                        THEN :error
                        ELSE :pending
                    END,
                    worker_id = NULL,
                    started_at = NULL,
                    updated_at = :updated_at,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    finished_at = CASE
                        WHEN CAST(COALESCE(NULLIF(attempts, \'\'), \'0\') AS INTEGER) + 1 >= CAST(:max_attempts AS INTEGER)
                        THEN :updated_at
                        ELSE NULL
                    END,
                    error_msg = CASE
                        WHEN CAST(COALESCE(NULLIF(attempts, \'\'), \'0\') AS INTEGER) + 1 >= CAST(:max_attempts AS INTEGER)
                        THEN \'Processing lease timeout exhausted retry budget after \'
                            || (CAST(COALESCE(NULLIF(attempts, \'\'), \'0\') AS INTEGER) + 1)
                            || \' attempts\'
                        ELSE \'Requeued after processing lease timeout (attempt \'
                            || (CAST(COALESCE(NULLIF(attempts, \'\'), \'0\') AS INTEGER) + 1)
                            || \' of \' || :max_attempts || \')\'
                    END
              WHERE status = :processing
                AND (
                    (
                        lease_expires_at IS NOT NULL
                        AND lease_expires_at <> \'\'
                        AND CAST(lease_expires_at AS INTEGER) < :now
                    )
                    OR (
                        (lease_expires_at IS NULL OR lease_expires_at = \'\')
                        AND (
                            started_at IS NULL
                            OR started_at = \'\'
                            OR CAST(started_at AS INTEGER) < :threshold
                        )
                    )
                )',
            [
                'pending' => self::STATUS_PENDING,
                'error' => self::STATUS_ERROR,
                'processing' => self::STATUS_PROCESSING,
                'max_attempts' => self::MAX_ATTEMPTS,
                'now' => (string)$now,
                'threshold' => (string)($now - $timeout),
                'updated_at' => (string)$now,
            ]
        );

        return (int)$connection->affectedRows();
    }

    public static function requeueErrors(): int
    {
        $model = new self();
        $connection = $model->getWriteConnection();
        $updatedAt = (string)time();
        $connection->execute(
            'UPDATE m_STTJob
                SET status = :pending,
                    worker_id = NULL,
                    attempts = 0,
                    started_at = NULL,
                    updated_at = :updated_at,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    finished_at = NULL,
                    error_msg = NULL
              WHERE status = :error',
            [
                'pending' => self::STATUS_PENDING,
                'error' => self::STATUS_ERROR,
                'updated_at' => $updatedAt,
            ]
        );

        return (int)$connection->affectedRows();
    }

    private static function processingTimeoutSeconds(): int
    {
        return self::PROCESSING_LEASE_TTL_SECONDS;
    }

    private static function newLeaseToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function markDoneForWorker(int $workerId, string $leaseToken): bool
    {
        $finishedAt = (string)time();
        $connection = $this->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET status = :done,
                    finished_at = :finished_at,
                    updated_at = :updated_at,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    error_msg = NULL
              WHERE id = :id
                AND status = :processing
                AND worker_id = :worker_id
                AND lease_token = :lease_token',
            [
                'done' => self::STATUS_DONE,
                'finished_at' => $finishedAt,
                'updated_at' => $finishedAt,
                'id' => (int)$this->id,
                'processing' => self::STATUS_PROCESSING,
                'worker_id' => $workerId,
                'lease_token' => $leaseToken,
            ]
        );

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $this->status = self::STATUS_DONE;
        $this->finished_at = $finishedAt;
        $this->updated_at = $finishedAt;
        $this->lease_token = null;
        $this->lease_expires_at = null;
        $this->error_msg = null;

        return true;
    }

    public function refreshProcessingLeaseForWorker(int $workerId, string $leaseToken): bool
    {
        $now = time();
        $leaseExpiresAt = (string)($now + self::processingTimeoutSeconds());
        $updatedAt = (string)$now;
        $connection = $this->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET lease_expires_at = :lease_expires_at,
                    updated_at = :updated_at
              WHERE id = :id
                AND status = :processing
                AND worker_id = :worker_id
                AND lease_token = :lease_token
                AND lease_expires_at >= :now',
            [
                'lease_expires_at' => $leaseExpiresAt,
                'updated_at' => $updatedAt,
                'id' => (int)$this->id,
                'processing' => self::STATUS_PROCESSING,
                'worker_id' => $workerId,
                'lease_token' => $leaseToken,
                'now' => $now,
            ]
        );

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $this->lease_expires_at = $leaseExpiresAt;
        $this->updated_at = $updatedAt;

        return true;
    }

    public function releaseForWorker(int $workerId, string $leaseToken): bool
    {
        $updatedAt = (string)time();
        $connection = $this->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET status = :pending,
                    worker_id = NULL,
                    started_at = NULL,
                    updated_at = :updated_at,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    finished_at = NULL,
                    error_msg = :error_msg
              WHERE id = :id
                AND status = :processing
                AND worker_id = :worker_id
                AND lease_token = :lease_token',
            [
                'pending' => self::STATUS_PENDING,
                'updated_at' => $updatedAt,
                'error_msg' => 'Released by worker',
                'id' => (int)$this->id,
                'processing' => self::STATUS_PROCESSING,
                'worker_id' => $workerId,
                'lease_token' => $leaseToken,
            ]
        );

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $this->status = self::STATUS_PENDING;
        $this->worker_id = null;
        $this->started_at = null;
        $this->updated_at = $updatedAt;
        $this->lease_token = null;
        $this->lease_expires_at = null;
        $this->finished_at = null;
        $this->error_msg = 'Released by worker';

        return true;
    }

    public function markFailedForWorker(int $workerId, string $leaseToken, string $errorMessage): bool
    {
        $currentAttempts = (int)$this->attempts;
        $nextAttempts = $currentAttempts + 1;
        $finalFailure = $nextAttempts >= self::MAX_ATTEMPTS;
        $finishedAt = (string)time();
        $connection = $this->getWriteConnection();
        $bind = [
            'attempts' => (string)$nextAttempts,
            'error_msg' => substr($errorMessage, 0, 1000),
            'updated_at' => $finishedAt,
            'id' => (int)$this->id,
            'processing' => self::STATUS_PROCESSING,
            'worker_id' => $workerId,
            'lease_token' => $leaseToken,
            'current_attempts' => (string)$currentAttempts,
        ];

        if ($finalFailure) {
            $sql = 'UPDATE m_STTJob
                    SET attempts = :attempts,
                        error_msg = :error_msg,
                        status = :error,
                        lease_token = NULL,
                        lease_expires_at = NULL,
                        finished_at = :finished_at,
                        updated_at = :updated_at
                  WHERE id = :id
                    AND status = :processing
                    AND worker_id = :worker_id
                    AND lease_token = :lease_token
                    AND CAST(attempts AS INTEGER) = :current_attempts';
            $bind['error'] = self::STATUS_ERROR;
            $bind['finished_at'] = $finishedAt;
        } else {
            $sql = 'UPDATE m_STTJob
                    SET attempts = :attempts,
                        error_msg = :error_msg,
                        status = :pending,
                        worker_id = NULL,
                        started_at = NULL,
                        lease_token = NULL,
                        lease_expires_at = NULL,
                        finished_at = NULL,
                        updated_at = :updated_at
                  WHERE id = :id
                    AND status = :processing
                    AND worker_id = :worker_id
                    AND lease_token = :lease_token
                    AND CAST(attempts AS INTEGER) = :current_attempts';
            $bind['pending'] = self::STATUS_PENDING;
        }

        $connection->execute($sql, $bind);

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $this->status = $finalFailure ? self::STATUS_ERROR : self::STATUS_PENDING;
        $this->worker_id = $finalFailure ? $workerId : null;
        $this->attempts = (string)$nextAttempts;
        $this->started_at = $finalFailure ? $this->started_at : null;
        $this->updated_at = $finishedAt;
        $this->lease_token = null;
        $this->lease_expires_at = null;
        $this->finished_at = $finalFailure ? $finishedAt : null;
        $this->error_msg = substr($errorMessage, 0, 1000);

        return true;
    }

    public function markTerminalErrorForWorker(int $workerId, string $leaseToken, string $errorMessage): bool
    {
        $currentAttempts = (int)$this->attempts;
        $finishedAt = (string)time();
        $connection = $this->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET attempts = :attempts,
                    error_msg = :error_msg,
                    status = :error,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    finished_at = :finished_at,
                    updated_at = :updated_at
              WHERE id = :id
                AND status = :processing
                AND worker_id = :worker_id
                AND lease_token = :lease_token
                AND CAST(attempts AS INTEGER) = :current_attempts',
            [
                'attempts' => (string)($currentAttempts + 1),
                'error_msg' => substr($errorMessage, 0, 1000),
                'error' => self::STATUS_ERROR,
                'finished_at' => $finishedAt,
                'updated_at' => $finishedAt,
                'id' => (int)$this->id,
                'processing' => self::STATUS_PROCESSING,
                'worker_id' => $workerId,
                'lease_token' => $leaseToken,
                'current_attempts' => (string)$currentAttempts,
            ]
        );

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $this->status = self::STATUS_ERROR;
        $this->attempts = (string)($currentAttempts + 1);
        $this->error_msg = substr($errorMessage, 0, 1000);
        $this->lease_token = null;
        $this->lease_expires_at = null;
        $this->finished_at = $finishedAt;
        $this->updated_at = $finishedAt;

        return true;
    }
}
