<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        public function setSource(string $source): void {}
        public function initialize(): void {}
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\Services\AdministrativeSettingsWriter;
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    final class AdministrativeSettingsConnection
    {
        public ?string $failOnSqlContaining = null;

        public function __construct(public readonly PDO $pdo) {}

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

        public function begin(): bool { return $this->pdo->beginTransaction(); }
        public function commit(): bool { return $this->pdo->commit(); }
        public function rollback(): bool { return $this->pdo->rollBack(); }
        public function isUnderTransaction(): bool { return $this->pdo->inTransaction(); }
    }

    function expectAdministrativeSettings(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function administrativeSetting(PDO $pdo, string $key): string
    {
        $statement = $pdo->prepare('SELECT value FROM m_STTSetting WHERE key = :key');
        $statement->execute(['key' => $key]);
        return (string)$statement->fetchColumn();
    }

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Lib/Services/TransactionRecoveryException.php';
    require_once __DIR__ . '/../Lib/Services/ModuleTransaction.php';
    require_once __DIR__ . '/../Lib/Services/WorkerProcessingSettingsService.php';

    $writerPath = __DIR__ . '/../Lib/Services/AdministrativeSettingsWriter.php';
    expectAdministrativeSettings(is_file($writerPath), 'Atomic administrative settings writer is missing');
    require_once $writerPath;

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection = new AdministrativeSettingsConnection($pdo);
    $connection->execute('CREATE TABLE m_STTSetting (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $connection->execute(
        'CREATE TABLE m_STTWorker (id INTEGER PRIMARY KEY, has_settings_changed INTEGER NOT NULL DEFAULT 0)'
    );
    $connection->execute('INSERT INTO m_STTWorker (id, has_settings_changed) VALUES (1, 0)');

    $defaults = WorkerProcessingSettingsService::defaults();
    $seed = $defaults + [
        STTSetting::DEFAULT_LANGUAGE => 'en',
        STTSetting::SELECTED_MODEL => 'parakeet-tdt-0.6b-v3',
    ];
    foreach ($seed as $key => $value) {
        $stored = is_bool($value)
            ? ($value ? '1' : '0')
            : (is_string($value)
                ? $value
                : (json_encode($value, JSON_PRESERVE_ZERO_FRACTION) ?: (string)$value));
        $connection->execute(
            'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)',
            ['key' => $key, 'value' => $stored]
        );
    }

    $writer = new AdministrativeSettingsWriter($connection);
    $connection->failOnSqlContaining = 'UPDATE m_STTWorker SET has_settings_changed = 1';
    $rejected = false;
    try {
        $writer->save(
            [
                STTSetting::DEFAULT_LANGUAGE => 'ru',
                STTSetting::SELECTED_MODEL => 'large-v3-turbo',
            ],
            array_merge($defaults, [STTSetting::TEMPERATURE => 0.4]),
            true
        );
    } catch (RuntimeException) {
        $rejected = true;
    }
    $connection->failOnSqlContaining = null;

    expectAdministrativeSettings($rejected, 'A failed worker notification must reject the complete settings save');
    $rolledBackValues = [
        administrativeSetting($pdo, STTSetting::DEFAULT_LANGUAGE),
        administrativeSetting($pdo, STTSetting::SELECTED_MODEL),
        administrativeSetting($pdo, STTSetting::TEMPERATURE),
    ];
    expectAdministrativeSettings(
        $rolledBackValues === ['en', 'parakeet-tdt-0.6b-v3', '0.0'],
        'A failed worker notification must roll back every administrative and processing setting: '
            . json_encode($rolledBackValues)
    );

    $writer->save(
        [
            STTSetting::DEFAULT_LANGUAGE => 'ru',
            STTSetting::SELECTED_MODEL => 'large-v3-turbo',
        ],
        array_merge($defaults, [STTSetting::TEMPERATURE => 0.4]),
        true
    );
    expectAdministrativeSettings(
        administrativeSetting($pdo, STTSetting::DEFAULT_LANGUAGE) === 'ru'
            && administrativeSetting($pdo, STTSetting::SELECTED_MODEL) === 'large-v3-turbo'
            && administrativeSetting($pdo, STTSetting::TEMPERATURE) === '0.4',
        'A successful administrative save must commit all settings together'
    );
    expectAdministrativeSettings(
        (int)$pdo->query('SELECT has_settings_changed FROM m_STTWorker WHERE id = 1')->fetchColumn() === 1,
        'A successful model/profile save must notify workers in the same transaction'
    );

    echo "Administrative settings transaction behavior OK\n";
}
