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
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModelMarketplaceService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Lib/Services/ModelMarketplaceService.php';

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
    expectSameSettingsNormalization('large-v3-turbo', STTSetting::DEFAULT_MODEL, 'Large V3 Turbo must remain the default model');
    expectSameSettingsNormalization('small', STTSetting::normalizeModelId('argmaxinc/whisperkit-coreml:openai_whisper-small_216MB'), 'Legacy small model id must normalize to the current preset id');
    expectSameSettingsNormalization('podlodka', STTSetting::normalizeModelId('smkrv/whisper-podlodka-turbo-coreml:bond005_whisper-podlodka-turbo'), 'Legacy Podlodka model id must normalize to the Podlodka preset');
    expectSameSettingsNormalization('podlodka', STTSetting::normalizeModelId('custom:c60f298fe61e0020d1932b4341e62fc30c6a9eac'), 'Legacy custom Podlodka id must normalize to the Podlodka preset');
    expectSameSettingsNormalization(STTSetting::DEFAULT_MODEL, STTSetting::normalizeModelId('unknown-model'), 'Unknown model ids must fall back to the default model');
    $podlodkaPreset = STTSetting::modelPresets()['podlodka'] ?? [];
    expectSameSettingsNormalization('module_local_speech_to_text_ModelPodlodkaDesc', $podlodkaPreset['description'] ?? null, 'Podlodka preset description must use a translation key');
    expectSameSettingsNormalization(
        'https://huggingface.co/smkrv/whisper-podlodka-turbo-coreml',
        $podlodkaPreset['source_url'] ?? null,
        'Podlodka preset source URL must point at the WhisperKit/Core ML Hugging Face repository'
    );
    expectTrueSettingsNormalization(
        !str_contains(json_encode(STTSetting::MODEL_PRESETS, JSON_UNESCAPED_SLASHES) ?: '', 'https://huggingface.co/bond005/whisper-podlodka-turbo'),
        'Podlodka UI/config must not point at the upstream Transformers Hugging Face repository'
    );

    $translationStub = new class {
        public function _(string $key): string
        {
            return $key;
        }
    };
    $marketplaceRows = (new ModelMarketplaceService($translationStub))->modelMarketplaceRows();
    $podlodkaRows = array_values(array_filter(
        $marketplaceRows,
        static fn (array $row): bool => ($row['id'] ?? '') === 'podlodka'
    ));
    expectSameSettingsNormalization(1, count($podlodkaRows), 'Podlodka must be exposed as exactly one marketplace row');
    expectSameSettingsNormalization(
        'https://huggingface.co/smkrv/whisper-podlodka-turbo-coreml',
        $podlodkaRows[0]['sourceUrl'] ?? null,
        'Podlodka marketplace row must expose the WhisperKit/Core ML Hugging Face repository link'
    );

    ModulesModelsBase::$values = [];
    expectTrueSettingsNormalization(
        method_exists(STTSetting::class, 'maxRecordingDurationMinutes'),
        'Recording duration setting helpers must exist'
    );
    expectSameSettingsNormalization(60, STTSetting::maxRecordingDurationMinutes(), 'Recording duration must default to 60 minutes');
    expectSameSettingsNormalization(1, STTSetting::normalizeRecordingDurationMinutes(0), 'Recording duration must be at least one minute');
    expectSameSettingsNormalization(1440, STTSetting::normalizeRecordingDurationMinutes(2000), 'Recording duration must be capped at 1440 minutes');

    ModulesModelsBase::$values = [STTSetting::MAX_RECORDING_DURATION_MIN => '60'];
    expectSameSettingsNormalization(3600, STTSetting::maxRecordingDurationSeconds(), 'Duration minutes must convert to seconds');
    expectTrueSettingsNormalization(STTSetting::recordingDurationAllowed(3600), 'Boundary duration must be allowed');
    expectTrueSettingsNormalization(!STTSetting::recordingDurationAllowed(3601), 'Duration above the boundary must be rejected');
    expectTrueSettingsNormalization(STTSetting::recordingDurationAllowed(0), 'Unknown zero duration must be allowed');
    expectTrueSettingsNormalization(STTSetting::recordingDurationAllowed(null), 'Missing duration must be allowed');

    ModulesModelsBase::$values = ['max_file_size_mb' => '1'];
    expectSameSettingsNormalization(500 * 1024 * 1024, STTSetting::maxFileSizeBytes(), 'File guard must remain fixed at 500 MiB');

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
