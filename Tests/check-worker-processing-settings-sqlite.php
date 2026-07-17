<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        public function setSource(string $source): void
        {
        }

        public function initialize(): void
        {
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Models/STTWorker.php';
    require_once __DIR__ . '/../Lib/Services/WorkerProcessingSettingsService.php';

    final class ProcessingSettingsSqliteConnection
    {
        public function __construct(private readonly PDO $pdo)
        {
        }

        public function execute(string $sql, array $bind = []): bool
        {
            $statement = $this->pdo->prepare($sql);

            return $statement->execute($bind);
        }

        public function fetchAll(string $sql, int $fetchMode, array $bind = []): array
        {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($bind);

            return $statement->fetchAll($fetchMode);
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

        echo "Worker processing settings SQLite contract OK\n";
    } finally {
        @unlink($databasePath);
    }
}
