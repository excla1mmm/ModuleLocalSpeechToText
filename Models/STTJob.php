<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;
use RuntimeException;
use Throwable;

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
        $connection->begin();

        try {
            $job = self::findFirst([
                'conditions' => 'status = :status:',
                'bind' => [
                    'status' => self::STATUS_PENDING,
                ],
                'order' => 'priority ASC, created_at ASC, id ASC',
            ]);

            if ($job === null) {
                $connection->commit();
                return null;
            }

            $job->status = self::STATUS_PROCESSING;
            $job->worker_id = (int)$worker->id;
            $job->started_at = (string)time();
            $job->error_msg = null;

            if (!$job->save()) {
                throw new RuntimeException(implode(', ', $job->getMessages()));
            }

            $connection->commit();
            return $job;
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }

    public function markDone(): void
    {
        $this->status = self::STATUS_DONE;
        $this->finished_at = (string)time();
        $this->error_msg = null;

        if (!$this->save()) {
            throw new RuntimeException(implode(', ', $this->getMessages()));
        }
    }

    public function markFailed(string $errorMessage): void
    {
        $this->attempts = (string)(((int)$this->attempts) + 1);
        $this->error_msg = substr($errorMessage, 0, 1000);

        if ((int)$this->attempts >= 3) {
            $this->status = self::STATUS_ERROR;
            $this->finished_at = (string)time();
        } else {
            $this->status = self::STATUS_PENDING;
            $this->worker_id = null;
            $this->started_at = null;
        }

        if (!$this->save()) {
            throw new RuntimeException(implode(', ', $this->getMessages()));
        }
    }
}
