<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        /** @var array<string, string> */
        public static array $values = [];

        public function setSource(string $source): void
        {
        }

        public function initialize(): void
        {
        }

        public static function findFirst(array $parameters = []): ?object
        {
            $key = $parameters['bind']['key'] ?? null;
            if (!is_string($key) || !array_key_exists($key, self::$values)) {
                return null;
            }

            $setting = new static();
            $setting->key = $key;
            $setting->value = self::$values[$key];

            return $setting;
        }
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Models/STTWorker.php';
    require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
    require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
    require_once __DIR__ . '/../Lib/Services/SummaryModel.php';
    require_once __DIR__ . '/../Lib/Services/WorkerProcessingSettingsService.php';

    final class ProcessingSettingsSqliteConnection
    {
        public ?string $failOnSqlContaining = null;

        public function __construct(private readonly PDO $pdo)
        {
        }

        public function execute(string $sql, array $bind = []): bool
        {
            if ($this->failOnSqlContaining !== null && str_contains($sql, $this->failOnSqlContaining)) {
                return false;
            }

            $statement = $this->pdo->prepare($sql);

            return $statement->execute($bind);
        }

        public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bind);

            return $statement->fetchAll($fetchMode);
        }

        public function begin(): bool
        {
            return $this->pdo->beginTransaction();
        }

        public function commit(): bool
        {
            return $this->pdo->commit();
        }

        public function rollback(): bool
        {
            return $this->pdo->rollBack();
        }

        public function isUnderTransaction(): bool
        {
            return $this->pdo->inTransaction();
        }
    }

    function expectProcessingSettingsSqlite(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** @return array<string, int> */
    function dirtyFlags(ProcessingSettingsSqliteConnection $connection): array
    {
        $rows = $connection->fetchAll(
            'SELECT worker_uid, has_settings_changed FROM m_STTWorker ORDER BY worker_uid',
            PDO::FETCH_ASSOC
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['worker_uid']] = (int)$row['has_settings_changed'];
        }

        return $result;
    }

    function workerByUid(ProcessingSettingsSqliteConnection $connection, string $workerUid): STTWorker
    {
        $rows = $connection->fetchAll(
            'SELECT id, worker_uid, has_settings_changed FROM m_STTWorker WHERE worker_uid = :worker_uid',
            PDO::FETCH_ASSOC,
            ['worker_uid' => $workerUid]
        );
        $worker = new STTWorker();
        $worker->id = (int)$rows[0]['id'];
        $worker->worker_uid = (string)$rows[0]['worker_uid'];
        $worker->has_settings_changed = (int)$rows[0]['has_settings_changed'];

        return $worker;
    }

    function settingValue(ProcessingSettingsSqliteConnection $connection, string $key): string
    {
        $rows = $connection->fetchAll(
            'SELECT value FROM m_STTSetting WHERE key = :key',
            PDO::FETCH_ASSOC,
            ['key' => $key]
        );

        return (string)($rows[0]['value'] ?? '');
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDERR, "pdo_sqlite is required for processing-settings behavior tests\n");
        exit(1);
    }

    $databasePath = tempnam(sys_get_temp_dir(), 'local-stt-processing-settings-');
    if ($databasePath === false) {
        throw new RuntimeException('Unable to allocate the SQLite test database');
    }

    try {
        $pdoA = new PDO('sqlite:' . $databasePath);
        $pdoB = new PDO('sqlite:' . $databasePath);
        foreach ([$pdoA, $pdoB] as $pdo) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 2000');
        }
        $connectionA = new ProcessingSettingsSqliteConnection($pdoA);
        $connectionB = new ProcessingSettingsSqliteConnection($pdoB);
        $connectionA->execute('CREATE TABLE m_STTSetting (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $connectionA->execute(
            'CREATE TABLE m_STTWorker (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                worker_uid TEXT NOT NULL UNIQUE,
                has_settings_changed INTEGER NOT NULL DEFAULT 1
            )'
        );
        $connectionA->execute("INSERT INTO m_STTWorker (worker_uid) VALUES ('worker-a'), ('worker-b')");

        $defaults = WorkerProcessingSettingsService::defaults();
        foreach ($defaults as $key => $value) {
            $stored = is_bool($value)
                ? ($value ? '1' : '0')
                : (json_encode($value, JSON_PRESERVE_ZERO_FRACTION) ?: (string)$value);
            $connectionA->execute(
                'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)',
                ['key' => $key, 'value' => $stored]
            );
        }

        $serviceA = new WorkerProcessingSettingsService($connectionA);
        $serviceB = new WorkerProcessingSettingsService($connectionB);
        ModulesModelsBase::$values = [
            STTSetting::SELECTED_MODEL => 'parakeet-tdt-0.6b-v3',
        ];
        expectProcessingSettingsSqlite(
            $serviceA->save($defaults) === false,
            'Saving the canonical defaults twice must be a no-op'
        );

        $changed = array_merge($defaults, ['temperature' => 0.4]);
        expectProcessingSettingsSqlite($serviceA->save($changed), 'A real change must be reported');
        expectProcessingSettingsSqlite(
            dirtyFlags($connectionA) === ['worker-a' => 1, 'worker-b' => 1],
            'A real change must dirty every worker'
        );

        $payload = $serviceA->fetchForWorker(workerByUid($connectionA, 'worker-a'));
        expectProcessingSettingsSqlite(
            $payload['processing_settings']['temperature'] === 0.4,
            'Fetch must return the complete new profile'
        );
        expectProcessingSettingsSqlite(
            ($payload['selected_model'] ?? null) === [
                'model_id' => 'parakeet-tdt-0.6b-v3',
                'model_repo' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
                'model_engine' => 'parakeet',
                'model_artifact_type' => 'parakeet-coreml',
                'model_platform' => 'macos',
                'model_display_name' => 'Parakeet TDT 0.6B v3',
            ],
            'Fetch must return the PBX-selected model descriptor'
        );
        expectProcessingSettingsSqlite(
            ($payload['summarization']['enabled'] ?? null) === false
                && ($payload['summarization']['model']['model_file'] ?? null) === 'gemma-3-4b-it-Q4_K_M.gguf'
                && ($payload['summarization']['model']['model_sha256_manifest'][0]['size'] ?? null) === 2489757856
                && preg_match('/^[0-9a-f]{40}$/', (string)($payload['summarization']['model']['model_revision'] ?? '')) === 1,
            'Fetch must describe the pinned summary model and report summarization off by default'
        );
        ModulesModelsBase::$values[STTSetting::SUMMARIZATION_ENABLED] = '1';
        expectProcessingSettingsSqlite(
            (WorkerProcessingSettingsService::apiPayload($changed)['summarization']['enabled'] ?? null) === true,
            'Turning summarization on must reach the workers'
        );
        unset(ModulesModelsBase::$values[STTSetting::SUMMARIZATION_ENABLED]);
        ModulesModelsBase::$values[STTSetting::SELECTED_MODEL] = 'large-v3-turbo';
        $whisperPayload = WorkerProcessingSettingsService::apiPayload($changed)['selected_model'] ?? [];
        expectProcessingSettingsSqlite(
            ($whisperPayload['model_revision'] ?? null) === STTSetting::ARGMAX_WHISPERKIT_REVISION,
            'Whisper processing settings must carry the pinned repository commit'
        );
        expectProcessingSettingsSqlite(
            ($whisperPayload['model_sha256_manifest'] ?? null) === STTSetting::WHISPER_SHA256_MANIFESTS['large-v3-turbo'],
            'Whisper processing settings must carry the reviewed SHA-256 manifest'
        );
        ModulesModelsBase::$values[STTSetting::SELECTED_MODEL] = 'gigaam-v3-windows';
        expectProcessingSettingsSqlite(
            (WorkerProcessingSettingsService::apiPayload($changed, 'windows')['selected_model'] ?? null) === [
                'model_id' => 'v3_e2e_rnnt',
                'model_repo' => 'istupakov/gigaam-v3-onnx',
                'model_engine' => 'gigaam',
                'model_artifact_type' => 'gigaam-onnx',
                'model_platform' => 'windows',
                'model_display_name' => 'GigaAM v3',
                'model_revision' => STTSetting::LINUX_MODEL_REVISIONS['gigaam-v3'],
                'model_sha256_manifest' => STTSetting::LINUX_SHA256_MANIFESTS['gigaam-v3'],
            ],
            'A Windows GigaAM selection must reuse the pinned Linux artifact on the windows platform'
        );
        ModulesModelsBase::$values[STTSetting::SELECTED_MODEL] = 'whisper-large-v3-turbo-windows';
        expectProcessingSettingsSqlite(
            (WorkerProcessingSettingsService::apiPayload($changed, 'windows')['selected_model'] ?? null) === [
                'model_id' => 'large-v3-turbo',
                'model_repo' => 'ggerganov/whisper.cpp',
                'model_engine' => 'whisperCpp',
                'model_artifact_type' => 'whisper-ggml',
                'model_platform' => 'windows',
                'model_display_name' => 'Whisper Large V3 Turbo (whisper.cpp)',
                'model_revision' => '5359861c739e955e79d9a303bcbc70fb988958b1',
                'model_sha256_manifest' => [[
                    'path' => 'ggml-large-v3-turbo.bin',
                    'size' => 1624555275,
                    'sha256' => '1fc70f774d38eb169993ac391eea357ef47c88757ef72ee5943879b7e8e2bc69',
                ]],
            ],
            'A whisper.cpp selection must carry its pinned GGML descriptor'
        );
        // Every platform has its own model at the same time; a worker gets the one of its platform.
        ModulesModelsBase::$values[STTSetting::SELECTED_MODEL] = 'gigaam-v3';
        ModulesModelsBase::$values['selected_model_macos'] = 'large-v3-turbo';
        ModulesModelsBase::$values['selected_model_windows'] = 'gigaam-v3-windows';
        $modelsByPlatform = [];
        foreach (['macos', 'linux', 'windows'] as $platform) {
            $modelsByPlatform[$platform] = WorkerProcessingSettingsService::apiPayload($changed, $platform)['selected_model']['model_platform'] ?? null;
        }
        expectProcessingSettingsSqlite(
            $modelsByPlatform === ['macos' => 'macos', 'linux' => 'linux', 'windows' => 'windows'],
            'Processing settings must describe the model of the requested worker platform'
        );
        expectProcessingSettingsSqlite(
            (WorkerProcessingSettingsService::apiPayload($changed, 'linux')['selected_model']['model_repo'] ?? null) === 'istupakov/gigaam-v3-onnx'
                && (WorkerProcessingSettingsService::apiPayload($changed, 'macos')['selected_model']['model_repo'] ?? null) === 'argmaxinc/whisperkit-coreml',
            'The legacy selection must keep serving its own platform next to the per-platform ones'
        );
        unset(ModulesModelsBase::$values['selected_model_macos'], ModulesModelsBase::$values['selected_model_windows']);
        ModulesModelsBase::$values[STTSetting::SELECTED_MODEL] = 'parakeet-tdt-0.6b-v3';
        expectProcessingSettingsSqlite(
            dirtyFlags($connectionA) === ['worker-a' => 0, 'worker-b' => 1],
            'Fetch must clear only the requesting worker'
        );

        $serviceA->fetchForWorker(workerByUid($connectionA, 'worker-a'));
        $afterFetch = array_merge($changed, ['temperature' => 0.5]);
        expectProcessingSettingsSqlite($serviceB->save($afterFetch), 'Save after fetch must change the profile');
        expectProcessingSettingsSqlite(
            dirtyFlags($connectionA)['worker-a'] === 1,
            'Fetch followed by save must leave the worker dirty'
        );

        $beforeFetch = array_merge($afterFetch, ['temperature' => 0.6]);
        expectProcessingSettingsSqlite($serviceB->save($beforeFetch), 'Save before fetch must change the profile');
        $orderedPayload = $serviceA->fetchForWorker(workerByUid($connectionA, 'worker-a'));
        expectProcessingSettingsSqlite(
            $orderedPayload['processing_settings']['temperature'] === 0.6
                && dirtyFlags($connectionA)['worker-a'] === 0,
            'Save followed by fetch must return the new profile and clear the worker'
        );

        WorkerProcessingSettingsService::markAllWorkersChanged($connectionA);
        expectProcessingSettingsSqlite(
            dirtyFlags($connectionA) === ['worker-a' => 1, 'worker-b' => 1],
            'A selected-model change must request an immediate refresh from every worker'
        );

        $beforeFailedSave = settingValue($connectionA, STTSetting::TEMPERATURE);
        $connectionA->failOnSqlContaining = 'UPDATE m_STTWorker SET has_settings_changed = 1';
        $saveRejected = false;
        try {
            $serviceA->save(array_merge($beforeFetch, ['temperature' => 0.7]));
        } catch (RuntimeException) {
            $saveRejected = true;
        }
        $connectionA->failOnSqlContaining = null;
        expectProcessingSettingsSqlite($saveRejected, 'A false worker notification write must fail the settings save');
        expectProcessingSettingsSqlite(
            settingValue($connectionA, STTSetting::TEMPERATURE) === $beforeFailedSave,
            'A false worker notification write must roll back preceding setting writes'
        );

        $connectionA->failOnSqlContaining = 'SET has_settings_changed = 0';
        $fetchRejected = false;
        try {
            $serviceA->fetchForWorker(workerByUid($connectionA, 'worker-a'));
        } catch (RuntimeException) {
            $fetchRejected = true;
        }
        $connectionA->failOnSqlContaining = null;
        expectProcessingSettingsSqlite($fetchRejected, 'A false settings acknowledgement write must fail the fetch');
        expectProcessingSettingsSqlite(
            dirtyFlags($connectionA)['worker-a'] === 1,
            'A failed settings acknowledgement must leave the worker dirty'
        );

        echo "Worker processing settings SQLite contract OK\n";
    } finally {
        @unlink($databasePath);
    }
}
