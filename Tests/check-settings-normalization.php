<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        /**
         * @var array<string, string>
         */
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

        public function save(): bool
        {
            if (property_exists($this, 'key') && is_string($this->key)) {
                self::$values[$this->key] = (string)($this->value ?? '');
            }

            return true;
        }

        public function getMessages(): array
        {
            return [];
        }
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';

    function expectSameSettingsNormalization(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
            );
        }
    }

    function expectTrueSettingsNormalization(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    ModulesModelsBase::$values = [];

    expectSameSettingsNormalization(
        ['Alpha', 'Beta', 'Gamma', 'Delta'],
        STTSetting::splitVocabularyTerms(" Alpha, Beta\nGamma; Delta "),
        'Vocabulary terms must split on commas, semicolons and newlines'
    );
    expectSameSettingsNormalization(
        120,
        strlen(STTSetting::splitVocabularyTerms(str_repeat('x', 140))[0] ?? ''),
        'Vocabulary terms must be capped at 120 characters'
    );

    ModulesModelsBase::$values = [
        STTSetting::CUSTOM_VOCABULARY_TERMS => "Alice, alice\nBob;BOB\nCarol",
    ];
    expectSameSettingsNormalization(
        ['Alice', 'Bob', 'Carol'],
        STTSetting::selectedVocabularyTerms(),
        'Selected vocabulary terms must be deduplicated case-insensitively while preserving first spelling'
    );

    expectSameSettingsNormalization(0, STTSetting::normalizeCdrHistoryDays(-5), 'Negative CDR history must disable backfill');
    expectSameSettingsNormalization(0, STTSetting::normalizeCdrHistoryDays(0), 'Zero CDR history must disable backfill');
    expectSameSettingsNormalization(1, STTSetting::normalizeCdrHistoryDays(1), 'Positive CDR history must be preserved');
    expectSameSettingsNormalization(730, STTSetting::normalizeCdrHistoryDays(999), 'CDR history must be capped at two years');

    expectSameSettingsNormalization('en', STTSetting::normalizeLanguageId(' EN '), 'Known language ids must normalize to lowercase');
    expectSameSettingsNormalization(STTSetting::AUTO_LANGUAGE, STTSetting::normalizeLanguageId('xx'), 'Unknown languages must fall back to auto');
    expectSameSettingsNormalization('small', STTSetting::normalizeModelId('argmaxinc/whisperkit-coreml:openai_whisper-small_216MB'), 'Legacy small model id must normalize to the current preset id');
    expectSameSettingsNormalization(STTSetting::DEFAULT_MODEL, STTSetting::normalizeModelId('unknown-model'), 'Unknown model ids must fall back to the default model');

    ModulesModelsBase::$values = [
        STTSetting::MAX_FILE_SIZE_MB => '0',
    ];
    expectSameSettingsNormalization(1024 * 1024, STTSetting::maxFileSizeBytes(), 'Max file size must be at least 1 MiB');
    ModulesModelsBase::$values = [
        STTSetting::MAX_FILE_SIZE_MB => '12000',
    ];
    expectSameSettingsNormalization(10240 * 1024 * 1024, STTSetting::maxFileSizeBytes(), 'Max file size must be capped at 10240 MiB');

    ModulesModelsBase::$values = [
        STTSetting::WORKER_API_KEY_IDS => json_encode([2, '3', -1, 'bad', 2]) ?: '[]',
        STTSetting::WORKER_API_KEY_ID => '5',
    ];
    expectSameSettingsNormalization(
        [2, 3, 5],
        STTSetting::workerApiKeyIds(),
        'Worker API keys must merge normalized current and legacy setting values'
    );

    STTSetting::setWorkerApiKeyIds([7, '8', 0, -3, 7]);
    expectSameSettingsNormalization('[7,8]', ModulesModelsBase::$values[STTSetting::WORKER_API_KEY_IDS] ?? null, 'Worker API key list must be persisted as normalized JSON');
    expectSameSettingsNormalization('7', ModulesModelsBase::$values[STTSetting::WORKER_API_KEY_ID] ?? null, 'Legacy worker API key setting must mirror the first current key');

    $repo = 'owner/model.repo';
    $runtimeModel = 'model-v1';
    $customModelId = 'custom:' . sha1($repo . ':' . $runtimeModel);
    ModulesModelsBase::$values = [
        STTSetting::CUSTOM_MODELS => json_encode([
            'invalid' => [
                'repo' => '../bad',
                'model' => 'model',
            ],
            'valid' => [
                'repo' => ' ' . $repo . ' ',
                'model' => ' ' . $runtimeModel . ' ',
                'label' => str_repeat('L', 200),
                'description' => str_repeat('D', 300),
                'license' => str_repeat('A', 100),
                'source_url' => 'https://huggingface.co/owner/model.repo/tree/main/model-v1',
                'tags' => ['tag', 'tag', ' ', str_repeat('x', 40)],
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}',
    ];

    $models = STTSetting::customModelPresets();
    expectTrueSettingsNormalization(isset($models[$customModelId]), 'Valid custom models must be exposed under a deterministic id');
    expectSameSettingsNormalization([$customModelId], array_keys($models), 'Invalid custom models must be ignored');
    expectSameSettingsNormalization(160, strlen($models[$customModelId]['label']), 'Custom model labels must be capped');
    expectSameSettingsNormalization(240, strlen($models[$customModelId]['description']), 'Custom model descriptions must be capped');
    expectSameSettingsNormalization(80, strlen($models[$customModelId]['license']), 'Custom model licenses must be capped');
    expectSameSettingsNormalization(
        ['tag', str_repeat('x', 32)],
        $models[$customModelId]['tags'],
        'Custom model tags must be deduplicated, trimmed and capped'
    );
    expectSameSettingsNormalization(
        $customModelId,
        STTSetting::normalizeModelId($customModelId),
        'Known custom model ids must be selectable'
    );

    echo "Settings normalization contract OK\n";
}
