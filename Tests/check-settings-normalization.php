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
    use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Lib/Services/ModelMarketplaceService.php';
    require_once __DIR__ . '/../Lib/Services/WorkerProcessingSettingsService.php';

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

    expectSameSettingsNormalization(50, STTSetting::cdrScanLimit(), 'Fresh installations must scan 50 CDR records per pass');
    ModulesModelsBase::$values = [STTSetting::CDR_SCAN_LIMIT => '75'];
    expectSameSettingsNormalization(75, STTSetting::cdrScanLimit(), 'Saved CDR scan limits must remain unchanged');
    ModulesModelsBase::$values = [STTSetting::CDR_SCAN_LIMIT => '0'];
    expectSameSettingsNormalization(1, STTSetting::cdrScanLimit(), 'CDR scan limit must be at least one');
    ModulesModelsBase::$values = [STTSetting::CDR_SCAN_LIMIT => '2000'];
    expectSameSettingsNormalization(1000, STTSetting::cdrScanLimit(), 'CDR scan limit must remain capped at 1000');
    ModulesModelsBase::$values = [];

    $defaults = WorkerProcessingSettingsService::defaults();
    expectSameSettingsNormalization(0.0, $defaults['temperature'], 'Temperature default must be 0.0');
    expectSameSettingsNormalization(0.2, $defaults['temperature_fallback_step'], 'Fallback step default must be 0.2');
    expectSameSettingsNormalization(5, $defaults['temperature_fallback_count'], 'Fallback count default must be 5');
    expectSameSettingsNormalization(5, $defaults['top_k'], 'Top K default must be 5');
    expectSameSettingsNormalization(2.4, $defaults['compression_ratio_threshold'], 'Compression ratio default must be 2.4');
    expectSameSettingsNormalization(-1.0, $defaults['log_probability_threshold'], 'Log probability default must be -1.0');
    expectSameSettingsNormalization(-1.5, $defaults['first_token_log_probability_threshold'], 'First-token default must be -1.5');
    expectSameSettingsNormalization(0.6, $defaults['no_speech_threshold'], 'No-speech default must be 0.6');
    expectSameSettingsNormalization(true, $defaults['audio_normalization'], 'Normalization must default on');
    expectSameSettingsNormalization(true, $defaults['voice_activity_detection'], 'VAD must default on');
    expectSameSettingsNormalization(15000, $defaults['max_segment_duration_ms'], 'Segment duration default must be 15 seconds');
    expectSameSettingsNormalization(300, $defaults['segment_overlap_ms'], 'Overlap default must be 300 ms');

    $normalized = WorkerProcessingSettingsService::normalize(array_merge($defaults, [
        'temperature' => '0.4',
        'temperature_fallback_count' => '3',
        'audio_normalization' => 'false',
        'voice_activity_detection' => 'true',
    ]));
    expectSameSettingsNormalization(0.4, $normalized['temperature'], 'Numeric strings must normalize to floats');
    expectSameSettingsNormalization(3, $normalized['temperature_fallback_count'], 'Integer strings must normalize to integers');
    expectSameSettingsNormalization(false, $normalized['audio_normalization'], 'False form values must normalize');
    expectSameSettingsNormalization(true, $normalized['voice_activity_detection'], 'True form values must normalize');

    foreach ([
        ['temperature' => 1.1],
        ['top_k' => 0],
        ['max_segment_duration_ms' => 999],
        ['segment_overlap_ms' => 15000],
    ] as $invalidPatch) {
        try {
            WorkerProcessingSettingsService::normalize(array_merge($defaults, $invalidPatch));
            throw new RuntimeException('Invalid processing profile was accepted');
        } catch (InvalidArgumentException) {
        }
    }

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
    expectSameSettingsNormalization(365, STTSetting::normalizeCdrHistoryDays(999), 'CDR history must be capped at one year');

    expectSameSettingsNormalization('en', STTSetting::normalizeLanguageId(' EN '), 'Known language ids must normalize to lowercase');
    expectSameSettingsNormalization(STTSetting::AUTO_LANGUAGE, STTSetting::normalizeLanguageId('xx'), 'Unknown languages must fall back to auto');
    expectSameSettingsNormalization('parakeet-tdt-0.6b-v3', STTSetting::DEFAULT_MODEL, 'Parakeet must be the default model');
    expectSameSettingsNormalization('parakeet-tdt-0.6b-v3', STTSetting::normalizeModelId('parakeet-tdt-0.6b-v3'), 'Parakeet preset id must normalize without fallback');
    expectSameSettingsNormalization(STTSetting::DEFAULT_MODEL, STTSetting::normalizeModelId('argmaxinc/whisperkit-coreml:openai_whisper-small_216MB'), 'Removed Small aliases must fall back to the default model');
    expectSameSettingsNormalization('podlodka', STTSetting::normalizeModelId('smkrv/whisper-podlodka-turbo-coreml:bond005_whisper-podlodka-turbo'), 'Legacy Podlodka model id must normalize to the Podlodka preset');
    expectSameSettingsNormalization('podlodka', STTSetting::normalizeModelId('custom:c60f298fe61e0020d1932b4341e62fc30c6a9eac'), 'Legacy custom Podlodka id must normalize to the Podlodka preset');
    expectSameSettingsNormalization(STTSetting::DEFAULT_MODEL, STTSetting::normalizeModelId('unknown-model'), 'Unknown model ids must fall back to the default model');
    $podlodkaPreset = STTSetting::modelPresets()['podlodka'] ?? [];
    expectSameSettingsNormalization('module_local_speech_to_text_ModelPodlodkaDesc', $podlodkaPreset['description'] ?? null, 'Podlodka preset description must use a translation key');
    expectSameSettingsNormalization(
        'https://huggingface.co/smkrv/whisper-podlodka-turbo-coreml/tree/' . STTSetting::PODLODKA_WHISPERKIT_REVISION,
        $podlodkaPreset['source_url'] ?? null,
        'Podlodka preset source URL must point at the pinned WhisperKit/Core ML repository commit'
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
        'https://huggingface.co/smkrv/whisper-podlodka-turbo-coreml/tree/' . STTSetting::PODLODKA_WHISPERKIT_REVISION,
        $podlodkaRows[0]['sourceUrl'] ?? null,
        'Podlodka marketplace row must expose the pinned WhisperKit/Core ML repository link'
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
    ModulesModelsBase::$values[STTSetting::WORKER_API_KEY_HISTORY_IDS] = '[6]';
    STTSetting::addWorkerApiKeyId(9);
    STTSetting::removeWorkerApiKeyId(9);
    expectSameSettingsNormalization(
        [6, 9],
        STTSetting::workerApiKeyHistoryIds(),
        'Worker API-key history must retain revoked IDs for queued/cache fail-closed authorization'
    );
    expectTrueSettingsNormalization(
        STTSetting::isWorkerApiKeyIdKnown(9) && !STTSetting::isWorkerApiKeyIdAllowed(9),
        'Removed worker API key must remain known without remaining authorized'
    );

    echo "Settings normalization contract OK\n";
}
