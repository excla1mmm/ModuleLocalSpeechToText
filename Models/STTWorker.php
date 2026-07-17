<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;
use RuntimeException;

/**
 * @Indexes(
 *     [name='worker_uid', columns=['worker_uid'], type='UNIQUE'],
 *     [name='api_key_id', columns=['api_key_id'], type='']
 * )
 */
class STTWorker extends ModulesModelsBase
{
    public const string STATUS_ONLINE = 'online';
    public const string STATUS_OFFLINE = 'offline';
    private const int MIN_OFFLINE_TIMEOUT_SECONDS = 900;
    private const int HEARTBEAT_WRITE_INTERVAL_SECONDS = 30;

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
    public ?int $api_key_id = null;

    /**
     * @Column(type="integer", default="1", nullable=false)
     */
    public ?int $has_settings_changed = 1;

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

    public static function registerWorker(
        string $workerUid,
        string $name,
        string $modelName,
        ?string $ip,
        ?int $apiKeyId = null
    ): self
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
            $worker->has_settings_changed = 1;
        }

        $worker->name = $name;
        $worker->model = $modelName;
        $worker->ip = $ip;
        if ($apiKeyId !== null && $apiKeyId > 0) {
            $worker->api_key_id = $apiKeyId;
        }
        $worker->status = self::STATUS_ONLINE;
        $worker->last_seen = (string)time();

        if (!$worker->save()) {
            throw new RuntimeException(implode(', ', $worker->getMessages()));
        }

        return $worker;
    }

    public function hasProcessingSettingsChanged(): bool
    {
        return (int)$this->has_settings_changed === 1;
    }

    public function touchOnline(?string $ip = null): void
    {
        $now = time();
        $nextIp = $ip !== null && $ip !== '' ? $ip : (string)$this->ip;
        $lastSeen = (int)$this->last_seen;
        if (
            (string)$this->status === self::STATUS_ONLINE
            && (string)$this->ip === $nextIp
            && $lastSeen > 0
            && $now - $lastSeen < self::HEARTBEAT_WRITE_INTERVAL_SECONDS
        ) {
            return;
        }

        if ($ip !== null && $ip !== '') {
            $this->ip = $ip;
        }
        $this->status = self::STATUS_ONLINE;
        $this->last_seen = (string)$now;

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
        $pollInterval = (int)STTSetting::getValue(STTSetting::POLL_INTERVAL_SEC, '30');

        return max(self::MIN_OFFLINE_TIMEOUT_SECONDS, min(7200, $pollInterval * 3));
    }
}
