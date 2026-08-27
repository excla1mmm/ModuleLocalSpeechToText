<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        /** @var array<string, string> */
        public static array $values = [];
        public function setSource(string $source): void {}
        public function initialize(): void {}
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
        public function save(): bool { return true; }
        public function getMessages(): array { return []; }
    }
}

namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModelMarketplaceService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Lib/Services/ModelMarketplaceService.php';

    function expectParakeet(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $expected = [
        'repo' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
        'model' => 'parakeet-tdt-0.6b-v3',
        'engine' => 'parakeet',
        'artifact_type' => 'parakeet-coreml',
        'display_name' => 'Parakeet TDT 0.6B v3',
        'label' => 'module_local_speech_to_text_ModelParakeetV3',
        'description' => 'module_local_speech_to_text_ModelParakeetV3Desc',
        'source_url' => 'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml',
        'profile' => 'module_local_speech_to_text_ModelProfileParakeetV3',
        'best_for' => 'module_local_speech_to_text_ModelParakeetV3BestFor',
        'tradeoff' => 'module_local_speech_to_text_ModelParakeetV3Tradeoff',
        'language' => '25 languages',
        'license' => 'CC BY 4.0',
        'supported_languages' => [
            'bg', 'hr', 'cs', 'da', 'nl', 'en', 'et', 'fi', 'fr', 'de', 'el', 'hu', 'it',
            'lv', 'lt', 'mt', 'pl', 'pt', 'ro', 'sk', 'sl', 'es', 'sv', 'ru', 'uk',
        ],
    ];
    expectParakeet(STTSetting::DEFAULT_MODEL === 'parakeet-tdt-0.6b-v3', 'Parakeet must be the default model');
    expectParakeet(
        array_keys(STTSetting::MODEL_PRESETS) === [
            'large-v3-turbo',
            'podlodka',
            'large-v3',
            'parakeet-tdt-0.6b-v3',
        ],
        'Built-in marketplace must contain only the four approved models in display order'
    );
    expectParakeet(
        (STTSetting::MODEL_PRESETS['parakeet-tdt-0.6b-v3'] ?? null) === $expected,
        'Parakeet preset must match the reviewed runtime descriptor exactly'
    );

    ModulesModelsBase::$values = [];
    expectParakeet(STTSetting::selectedModelId() === 'parakeet-tdt-0.6b-v3', 'Fresh settings must select Parakeet by default');
    expectParakeet(STTSetting::selectedModelEngine() === 'parakeet', 'Selected engine must be Parakeet');
    expectParakeet(STTSetting::selectedModelArtifactType() === 'parakeet-coreml', 'Selected artifact must be Parakeet Core ML');

    ModulesModelsBase::$values = [
        'custom_models' => json_encode([
            'custom:legacy' => [
                'repo' => 'owner/legacy-whisperkit-coreml',
                'model' => 'legacy-model',
                'label' => 'Legacy custom model',
            ],
        ], JSON_UNESCAPED_SLASHES),
    ];
    expectParakeet(
        array_keys(STTSetting::modelPresets()) === [
            'large-v3-turbo',
            'podlodka',
            'large-v3',
            'parakeet-tdt-0.6b-v3',
        ],
        'Saved custom model data must not extend the supported marketplace catalog'
    );
    ModulesModelsBase::$values = [];

    $translation = new class {
        public function _(string $key): string { return $key; }
    };
    $rows = (new ModelMarketplaceService($translation))->modelMarketplaceRows();
    expectParakeet(
        array_column($rows, 'id') === ['large-v3-turbo', 'podlodka', 'large-v3', 'parakeet-tdt-0.6b-v3'],
        'Marketplace rows must expose only the four approved models in display order'
    );
    $matches = array_values(array_filter($rows, static fn (array $row): bool => ($row['id'] ?? '') === 'parakeet-tdt-0.6b-v3'));
    expectParakeet(count($matches) === 1, 'Marketplace must contain exactly one Parakeet row');
    expectParakeet(($matches[0]['selected'] ?? false) === true, 'Selected Parakeet row must use the generic selected state');
    expectParakeet(($matches[0]['sourceUrl'] ?? '') === $expected['source_url'], 'Marketplace must expose the official source URL');
    expectParakeet(($matches[0]['technical'] ?? '') === 'parakeet · Core ML · 25 languages · CC BY 4.0', 'Parakeet technical label mismatch');

    $russianMessages = include __DIR__ . '/../Messages/ru.php';
    $russianTranslation = new class($russianMessages) {
        /** @param array<string, string> $messages */
        public function __construct(private array $messages) {}
        public function _(string $key): string { return $this->messages[$key] ?? $key; }
    };
    $russianRows = (new ModelMarketplaceService($russianTranslation))->modelMarketplaceRows();
    $russianRowsById = array_column($russianRows, null, 'id');
    $expectedGuidance = [
        'large-v3-turbo' => [
            'title' => 'Whisper Large V3 Turbo',
            'profile' => 'Универсальный WhisperKit',
            'bestFor' => 'Если нужна проверенная модель WhisperKit',
            'tradeoff' => 'Хороший баланс скорости и качества для обычных звонков на разных языках. Модель от OpenAI.',
        ],
        'podlodka' => [
            'title' => 'Whisper Podlodka Turbo',
            'profile' => 'Русская речь',
            'bestFor' => 'Если почти все разговоры проходят на русском',
            'tradeoff' => 'Дообученная версия Whisper large-v3-turbo, созданная для улучшения качества транскрипции на русском языке.',
        ],
        'large-v3' => [
            'title' => 'Whisper Large V3',
            'profile' => 'Максимум качества',
            'bestFor' => 'Если качество важнее скорости обработки',
            'tradeoff' => 'Тяжёлая модель от OpenAI. Подходит для сложных и неразборчивых записей.',
        ],
        'parakeet-tdt-0.6b-v3' => [
            'profile' => 'Рекомендуется',
            'bestFor' => 'Лучший выбор для большинства звонков',
            'tradeoff' => 'Быстро и точно распознаёт длинную речь на 25 языках. Оптимальный вариант для повседневной работы. Модель от NVIDIA.',
        ],
    ];
    foreach ($expectedGuidance as $modelId => $guidance) {
        foreach ($guidance as $field => $text) {
            expectParakeet(
                ($russianRowsById[$modelId][$field] ?? null) === $text,
                "Russian marketplace guidance mismatch for {$modelId}.{$field}"
            );
        }
    }

    $englishMessages = include __DIR__ . '/../Messages/en.php';
    $englishTranslation = new class($englishMessages) {
        /** @param array<string, string> $messages */
        public function __construct(private array $messages) {}
        public function _(string $key): string { return $this->messages[$key] ?? $key; }
    };
    $englishRows = (new ModelMarketplaceService($englishTranslation))->modelMarketplaceRows();
    $englishRowsById = array_column($englishRows, null, 'id');
    $expectedEnglishGuidance = [
        'large-v3-turbo' => [
            'title' => 'Whisper Large V3 Turbo',
            'profile' => 'Universal WhisperKit',
            'bestFor' => 'When you need a proven WhisperKit model',
            'tradeoff' => 'A good balance of speed and quality for everyday calls in different languages. A model from OpenAI.',
        ],
        'podlodka' => [
            'title' => 'Whisper Podlodka Turbo',
            'profile' => 'Russian speech',
            'bestFor' => 'When nearly all conversations are in Russian',
            'tradeoff' => 'A fine-tuned version of Whisper large-v3-turbo, created to improve transcription quality for Russian.',
        ],
        'large-v3' => [
            'title' => 'Whisper Large V3',
            'profile' => 'Highest quality',
            'bestFor' => 'When quality matters more than processing speed',
            'tradeoff' => 'A heavyweight model from OpenAI. Suitable for difficult and unclear recordings.',
        ],
        'parakeet-tdt-0.6b-v3' => [
            'profile' => 'Recommended',
            'bestFor' => 'The best choice for most calls',
            'tradeoff' => 'Recognizes long-form speech quickly and accurately in 25 languages. The best choice for everyday use. A model from NVIDIA.',
        ],
    ];
    foreach ($expectedEnglishGuidance as $modelId => $guidance) {
        foreach ($guidance as $field => $text) {
            expectParakeet(
                ($englishRowsById[$modelId][$field] ?? null) === $text,
                "English marketplace guidance mismatch for {$modelId}.{$field}"
            );
        }
    }

    $translationKeys = [
        $expected['label'], $expected['description'], $expected['profile'],
        $expected['best_for'], $expected['tradeoff'],
    ];
    $sourceKeys = array_keys(include __DIR__ . '/../Messages/ru.php');
    foreach (glob(__DIR__ . '/../Messages/*.php') ?: [] as $messageFile) {
        $messages = include $messageFile;
        expectParakeet(array_keys($messages) === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
        foreach ($translationKeys as $key) {
            expectParakeet(isset($messages[$key]) && trim((string)$messages[$key]) !== '', basename($messageFile) . " misses {$key}");
        }
    }

    $view = file_get_contents(__DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt');
    expectParakeet(is_string($view), 'Cannot read model marketplace view');
    expectParakeet(str_contains($view, 'name="selected_model"'), 'Generic model selection radio must remain');
    expectParakeet(!str_contains($view, 'parakeet-tdt-0.6b-v3'), 'View must not add a Parakeet-specific control');
    expectParakeet(str_contains($view, "form.render('custom_vocabulary_terms')"), 'Vocabulary field must remain unchanged');

    echo "Parakeet model contract OK\n";
}
