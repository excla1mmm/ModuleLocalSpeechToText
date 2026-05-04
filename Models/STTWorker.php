<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;
use RuntimeException;

/**
 * @Indexes(
 *     [name='worker_uid', columns=['worker_uid'], type='UNIQUE']
 * )
 */
class STTWorker extends ModulesModelsBase
{
    public const string STATUS_ONLINE = 'online';
    public const string STATUS_OFFLINE = 'offline';
    private const int MIN_OFFLINE_TIMEOUT_SECONDS = 60;

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $worker_uid = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $name = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $ip = '';

    /**
     * @Column(type="string", default="offline", nullable=true)
     */
    public ?string $status = self::STATUS_OFFLINE;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $model = '';

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $last_seen = null;

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTWorker');
        parent::initialize();

        $this->hasMany(
            'id',
            STTJob::class,
            'worker_id',
            [
                'alias' => 'STTJobs',
                'foreignKey' => [
                    'allowNulls' => true,
                    'action' => Relation::NO_ACTION,
                ],
            ]
        );
    }

    public static function registerWorker(string $workerUid, string $name, string $modelName, ?string $ip): self
    {
        $worker = self::findFirst([
            'conditions' => 'worker_uid = :worker_uid:',
            'bind' => [
                'worker_uid' => $workerUid,
            ],
        ]);
        if ($worker === null) {
            $worker = new self();
            $worker->worker_uid = $workerUid;
            $worker->created_at = (string)time();
        }

        $worker->name = $name;
        $worker->model = $modelName;
        $worker->ip = $ip;
        $worker->status = self::STATUS_ONLINE;
        $worker->last_seen = (string)time();

        if (!$worker->save()) {
            throw new RuntimeException(implode(', ', $worker->getMessages()));
        }

        return $worker;
    }

    public function touchOnline(?string $ip = null): void
    {
        if ($ip !== null && $ip !== '') {
            $this->ip = $ip;
        }
        $this->status = self::STATUS_ONLINE;
        $this->last_seen = (string)time();

        if (!$this->save()) {
            throw new RuntimeException(implode(', ', $this->getMessages()));
        }
    }

    public static function markStaleOffline(): void
    {
        $threshold = time() - self::offlineTimeoutSeconds();
        $workers = self::find([
            'conditions' => 'status = :status: AND (last_seen IS NULL OR last_seen < :threshold:)',
            'bind' => [
                'status' => self::STATUS_ONLINE,
                'threshold' => (string)$threshold,
            ],
        ]);

        foreach ($workers as $worker) {
            $worker->status = self::STATUS_OFFLINE;
            if (!$worker->save()) {
                throw new RuntimeException(implode(', ', $worker->getMessages()));
            }
        }
    }

    private static function offlineTimeoutSeconds(): int
    {
        $pollInterval = (int)STTSetting::getValue(STTSetting::POLL_INTERVAL_SEC, '5');

        return max(self::MIN_OFFLINE_TIMEOUT_SECONDS, min(7200, $pollInterval * 3));
    }
}
