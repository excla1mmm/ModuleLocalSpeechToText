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
     * @Column(type="integer", default="1", nullable=false)
     */
    public ?int $api_version = 1;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $app_version = '';

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?string $last_seen = null;

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $created_at = '0';

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $updated_at = '0';

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
        ?int $apiKeyId = null,
        int $apiVersion = 1,
        string $appVersion = ''
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
        $worker->api_version = $apiVersion;
        $worker->app_version = $appVersion;
        $worker->updated_at = (string)time();

        if ($worker->id === null) {
            self::saveNewWorker($worker);
            return $worker;
        }

        $connection = $worker->getWriteConnection();
        if (!$connection->execute(
            'UPDATE m_STTWorker
             SET name = :name, model = :model, ip = :ip, api_key_id = :api_key_id,
                 status = :status, last_seen = :last_seen, api_version = :api_version,
                 app_version = :app_version, updated_at = :updated_at
             WHERE id = :id',
            [
                'name' => $worker->name,
                'model' => $worker->model,
                'ip' => $worker->ip,
                'api_key_id' => $worker->api_key_id,
                'status' => $worker->status,
                'last_seen' => $worker->last_seen,
                'api_version' => $worker->api_version,
                'app_version' => $worker->app_version,
                'updated_at' => $worker->updated_at,
                'id' => (int)$worker->id,
            ]
        )) {
            throw new RuntimeException('Unable to update worker registration');
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

        if (!$this->getWriteConnection()->execute(
            'UPDATE m_STTWorker SET ip = :ip, status = :status, last_seen = :last_seen WHERE id = :id',
            [
                'ip' => $this->ip,
                'status' => $this->status,
                'last_seen' => $this->last_seen,
                'id' => (int)$this->id,
            ]
        )) {
            throw new RuntimeException('Unable to update worker heartbeat');
        }
    }

    public function updateModel(string $modelName): void
    {
        if ((string)$this->model === $modelName) {
            return;
        }

        if (!$this->getWriteConnection()->execute(
            'UPDATE m_STTWorker SET model = :model WHERE id = :id',
            ['model' => $modelName, 'id' => (int)$this->id]
        )) {
            throw new RuntimeException('Unable to update worker model');
        }
        $this->model = $modelName;
    }

    public static function markStaleOffline(): void
    {
        $threshold = time() - self::offlineTimeoutSeconds();
        $model = new self();
        if (!$model->getWriteConnection()->execute(
            'UPDATE m_STTWorker SET status = :offline
             WHERE status = :online AND (last_seen IS NULL OR last_seen < :threshold)',
            [
                'offline' => self::STATUS_OFFLINE,
                'online' => self::STATUS_ONLINE,
                'threshold' => (string)$threshold,
            ]
        )) {
            throw new RuntimeException('Unable to mark stale workers offline');
        }
    }

    private static function saveNewWorker(self $worker): void
    {
        if (!$worker->save()) {
            throw new RuntimeException(implode(', ', $worker->getMessages()));
        }
    }

    private static function offlineTimeoutSeconds(): int
    {
        $pollInterval = (int)STTSetting::getValue(STTSetting::POLL_INTERVAL_SEC, '30');

        return max(self::MIN_OFFLINE_TIMEOUT_SECONDS, min(7200, $pollInterval * 3));
    }
}
