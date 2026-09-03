<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use RuntimeException;

final class AdministrativeSettingsWriter
{
    public function __construct(private readonly mixed $connection = null)
    {
    }

    /**
     * @param array<string, scalar|null> $settings
     * @param array<string, mixed>|null $processingSettings
     */
    public function save(
        array $settings,
        ?array $processingSettings = null,
        bool $markWorkersChanged = false
    ): void {
        $normalizedProcessing = $processingSettings === null
            ? null
            : WorkerProcessingSettingsService::normalize($processingSettings);
        $database = $this->connection ?? (new STTSetting())->getWriteConnection();

        (new ModuleTransaction())->run(
            $database,
            static function (mixed $database) use ($settings, $normalizedProcessing, $markWorkersChanged): void {
                foreach ($settings as $key => $value) {
                    if (!$database->execute(
                        'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)
                         ON CONFLICT(key) DO UPDATE SET value = excluded.value',
                        ['key' => $key, 'value' => (string)$value]
                    )) {
                        throw new RuntimeException('Unable to save administrative settings');
                    }
                }

                if ($normalizedProcessing !== null) {
                    (new WorkerProcessingSettingsService($database))->save($normalizedProcessing);
                }
                if ($markWorkersChanged) {
                    WorkerProcessingSettingsService::markAllWorkersChanged($database);
                }
            }
        );
    }
}
