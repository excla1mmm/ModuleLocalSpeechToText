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
     * Platform the worker last declared (macos, linux, windows); empty for a
     * worker that never declared one, which is a macOS worker.
     *
     * @Column(type="string", nullable=false)
     */
    public ?string $platform = '';

    /**
     * Optional features the worker last declared, comma-separated (see
     * WorkerCapabilities); empty for an older worker that declares none.
     *
     * @Column(type="string", nullable=false)
     */
    public ?string $capabilities = '';

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
        string $appVersion = '',
        string $platform = '',
        string $capabilities = ''
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
        $worker->platform = $platform;
        $worker->capabilities = $capabilities;
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
                 app_version = :app_version, platform = :platform, capabilities = :capabilities,
                 updated_at = :updated_at
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
                'platform' => $worker->platform,
                'capabilities' => $worker->capabilities,
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

    /**
     * Remembers the platform a worker declared on a lease request and the
     * model it runs there. A worker that moves to another platform must fetch
     * the processing settings of its new platform.
     */
    public function updatePlatformModel(string $platform, string $modelName): void
    {
        $platformChanged = (string)$this->platform !== $platform;
        if (!$platformChanged && (string)$this->model === $modelName) {
            return;
        }

        if (!$this->getWriteConnection()->execute(
            'UPDATE m_STTWorker
             SET platform = :platform, model = :model,
                 has_settings_changed = CASE WHEN :platform_changed = 1 THEN 1 ELSE has_settings_changed END
             WHERE id = :id',
            [
                'platform' => $platform,
                'model' => $modelName,
                'platform_changed' => $platformChanged ? 1 : 0,
                'id' => (int)$this->id,
            ]
        )) {
            throw new RuntimeException('Unable to update worker model');
        }
        $this->platform = $platform;
        $this->model = $modelName;
        if ($platformChanged) {
            $this->has_settings_changed = 1;
        }
    }

    /** Stores the features declared on a job lease; writes only when they changed. */
    public function updateCapabilities(string $capabilities): void
    {
        if ((string)$this->capabilities === $capabilities) {
            return;
        }
        if (!$this->getWriteConnection()->execute(
            'UPDATE m_STTWorker SET capabilities = :capabilities WHERE id = :id',
            ['capabilities' => $capabilities, 'id' => (int)$this->id]
        )) {
            throw new RuntimeException('Unable to update worker capabilities');
        }
        $this->capabilities = $capabilities;
    }

    /** Whether any online worker declared the feature (see WorkerCapabilities). */
    public static function anyOnlineWithCapability(string $capability): bool
    {
        foreach (self::find([
            'conditions' => 'status = :status: AND capabilities <> :empty:',
            'bind' => ['status' => self::STATUS_ONLINE, 'empty' => ''],
            'columns' => 'capabilities',
        ]) as $row) {
            if (in_array($capability, explode(',', (string)$row->capabilities), true)) {
                return true;
            }
        }

        return false;
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

    public static function deleteIfNoActiveLease(int $workerId): bool
    {
        if ($workerId <= 0) {
            return false;
        }
        $connection = (new self())->getWriteConnection();
        if (!$connection->execute(
            'DELETE FROM m_STTWorker
              WHERE id = :worker_id
                AND NOT EXISTS (
                    SELECT 1
                      FROM m_STTJob
                     WHERE worker_id = :worker_id
                       AND status = :processing
                       AND lease_token IS NOT NULL
                )',
            ['worker_id' => $workerId, 'processing' => STTJob::STATUS_PROCESSING]
        )) {
            throw new RuntimeException('Unable to delete worker');
        }

        return (int)$connection->affectedRows() === 1;
    }

    private static function saveNewWorker(self $worker): void
    {
        if (!$worker->save()) {
            throw new RuntimeException(implode(', ', $worker->getMessages()));
        }
    }

    private static function offlineTimeoutSeconds(): int
    {
        // Internal fixed policy: worker online/offline detection no longer scales
        // with the (removed) poll_interval_sec setting.
        return self::MIN_OFFLINE_TIMEOUT_SECONDS;
    }
}
