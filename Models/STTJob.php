<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;
use RuntimeException;

/**
 * @Indexes(
 *     [name='queue', columns=['status', 'priority', 'created_at'], type=''],
 *     [name='file_path', columns=['file_path'], type='UNIQUE']
 * )
 */
class STTJob extends ModulesModelsBase
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_DONE = 'done';
    public const string STATUS_ERROR = 'error';
    private const int DEFAULT_PROCESSING_TIMEOUT_SECONDS = 1800;

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
     * @Column(type="integer", nullable=true)
     */
    public ?string $started_at = null;

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

    public static function createPending(string $callId, string $filePath, int $priority = 5): self
    {
        $job = new self();
        $job->call_id = $callId;
        $job->file_path = $filePath;
        $job->status = self::STATUS_PENDING;
        $job->priority = (string)$priority;
        $job->attempts = '0';
        $job->created_at = (string)time();

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
            $job = self::findFirst([
                'conditions' => 'status = :status:',
                'bind' => [
                    'status' => self::STATUS_PENDING,
                ],
                'order' => 'priority ASC, created_at ASC, id ASC',
            ]);

            if ($job === null) {
                return null;
            }

            $connection->execute(
                'UPDATE m_STTJob
                    SET status = :processing, worker_id = :worker_id, started_at = :started_at, finished_at = NULL, error_msg = NULL
                  WHERE id = :id AND status = :pending',
                [
                    'processing' => self::STATUS_PROCESSING,
                    'worker_id' => $workerId,
                    'started_at' => (string)time(),
                    'id' => (int)$job->id,
                    'pending' => self::STATUS_PENDING,
                ]
            );

            if ((int)$connection->affectedRows() === 1) {
                $claimedJob = self::findFirstById($job->id);

                return $claimedJob instanceof self ? $claimedJob : null;
            }
        }

        return null;
    }

    public static function requeueStaleProcessing(): int
    {
        $timeout = self::processingTimeoutSeconds();
        if ($timeout <= 0) {
            return 0;
        }

        $model = new self();
        $connection = $model->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET status = :pending, worker_id = NULL, started_at = NULL, finished_at = NULL, error_msg = :error_msg
              WHERE status = :processing
                AND (
                    started_at IS NULL
                    OR started_at = \'\'
                    OR CAST(started_at AS INTEGER) < :threshold
                )',
            [
                'pending' => self::STATUS_PENDING,
                'processing' => self::STATUS_PROCESSING,
                'threshold' => (string)(time() - $timeout),
                'error_msg' => 'Requeued after processing timeout',
            ]
        );

        return (int)$connection->affectedRows();
    }

    public static function requeueErrors(): int
    {
        $model = new self();
        $connection = $model->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET status = :pending,
                    worker_id = NULL,
                    attempts = 0,
                    started_at = NULL,
                    finished_at = NULL,
                    error_msg = NULL
              WHERE status = :error',
            [
                'pending' => self::STATUS_PENDING,
                'error' => self::STATUS_ERROR,
            ]
        );

        return (int)$connection->affectedRows();
    }

    private static function processingTimeoutSeconds(): int
    {
        $timeout = (int)STTSetting::getValue(
            STTSetting::PROCESSING_TIMEOUT_SEC,
            (string)self::DEFAULT_PROCESSING_TIMEOUT_SECONDS
        );

        return max(60, min(86400, $timeout));
    }

    public function markDoneForWorker(int $workerId): bool
    {
        $finishedAt = (string)time();
        $connection = $this->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET status = :done,
                    finished_at = :finished_at,
                    error_msg = NULL
              WHERE id = :id
                AND status = :processing
                AND worker_id = :worker_id',
            [
                'done' => self::STATUS_DONE,
                'finished_at' => $finishedAt,
                'id' => (int)$this->id,
                'processing' => self::STATUS_PROCESSING,
                'worker_id' => $workerId,
            ]
        );

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $this->status = self::STATUS_DONE;
        $this->finished_at = $finishedAt;
        $this->error_msg = null;

        return true;
    }

    public function refreshProcessingLeaseForWorker(int $workerId): bool
    {
        $startedAt = (string)time();
        $connection = $this->getWriteConnection();
        $connection->execute(
            'UPDATE m_STTJob
                SET started_at = :started_at
              WHERE id = :id
                AND status = :processing
                AND worker_id = :worker_id',
            [
                'started_at' => $startedAt,
                'id' => (int)$this->id,
                'processing' => self::STATUS_PROCESSING,
                'worker_id' => $workerId,
            ]
        );

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $this->started_at = $startedAt;

        return true;
    }

    public function markFailedForWorker(int $workerId, string $errorMessage): bool
    {
        $currentAttempts = (int)$this->attempts;
        $nextAttempts = $currentAttempts + 1;
        $finalFailure = $nextAttempts >= 3;
        $finishedAt = (string)time();
        $connection = $this->getWriteConnection();
        $bind = [
            'attempts' => (string)$nextAttempts,
            'error_msg' => substr($errorMessage, 0, 1000),
            'id' => (int)$this->id,
            'processing' => self::STATUS_PROCESSING,
            'worker_id' => $workerId,
            'current_attempts' => (string)$currentAttempts,
        ];

        if ($finalFailure) {
            $sql = 'UPDATE m_STTJob
                    SET attempts = :attempts,
                        error_msg = :error_msg,
                        status = :error,
                        finished_at = :finished_at
                  WHERE id = :id
                    AND status = :processing
                    AND worker_id = :worker_id
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
                        finished_at = NULL
                  WHERE id = :id
                    AND status = :processing
                    AND worker_id = :worker_id
                    AND CAST(attempts AS INTEGER) = :current_attempts';
            $bind['pending'] = self::STATUS_PENDING;
        }

        $connection->execute($sql, $bind);

        if ((int)$connection->affectedRows() !== 1) {
            return false;
        }

        $freshJob = self::findFirstById($this->id);
        if ($freshJob instanceof self) {
            $this->status = $freshJob->status;
            $this->worker_id = $freshJob->worker_id;
            $this->attempts = $freshJob->attempts;
            $this->started_at = $freshJob->started_at;
            $this->finished_at = $freshJob->finished_at;
            $this->error_msg = $freshJob->error_msg;
        }

        return true;
    }
}
