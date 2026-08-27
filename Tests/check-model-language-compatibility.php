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
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModelMarketplaceService;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Lib/Services/ModelMarketplaceService.php';

    function expectModelLanguageCompatibility(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    expectModelLanguageCompatibility(
        method_exists(STTSetting::class, 'modelSupportsLanguage'),
        'STT settings must expose model/language compatibility validation'
    );
    expectModelLanguageCompatibility(
        STTSetting::modelSupportsLanguage('parakeet-tdt-0.6b-v3', 'auto'),
        'Parakeet must allow automatic language selection'
    );
    expectModelLanguageCompatibility(
        STTSetting::modelSupportsLanguage('parakeet-tdt-0.6b-v3', 'ru'),
        'Parakeet must allow supported explicit languages'
    );
    expectModelLanguageCompatibility(
        !STTSetting::modelSupportsLanguage('parakeet-tdt-0.6b-v3', 'ja'),
        'Parakeet must reject unsupported explicit languages'
    );
    expectModelLanguageCompatibility(
        STTSetting::modelSupportsLanguage('large-v3-turbo', 'ja'),
        'WhisperKit models must retain the full configured language catalog'
    );
    expectModelLanguageCompatibility(
        method_exists(STTSetting::class, 'languagePresetsForModel'),
        'STT settings must expose model-aware language options'
    );
    expectModelLanguageCompatibility(
        array_keys(STTSetting::languagePresetsForModel('parakeet-tdt-0.6b-v3')) === [
            'auto', 'ru', 'en', 'de', 'es', 'fr', 'it', 'pt', 'pl', 'uk', 'nl',
        ],
        'Parakeet language options must exclude unsupported configured languages'
    );
    expectModelLanguageCompatibility(
        STTSetting::languagePresetsForModel('large-v3-turbo') === STTSetting::LANGUAGE_PRESETS,
        'WhisperKit language options must remain unchanged'
    );

    $translation = new class {
        public function _(string $key): string { return $key; }
    };
    $marketplaceRows = array_column(
        (new ModelMarketplaceService($translation))->modelMarketplaceRows(),
        null,
        'id'
    );
    expectModelLanguageCompatibility(
        ($marketplaceRows['parakeet-tdt-0.6b-v3']['supportedLanguageIds'] ?? null)
            === STTSetting::PARAKEET_LANGUAGE_IDS,
        'Marketplace must expose Parakeet language capabilities to the UI'
    );
    expectModelLanguageCompatibility(
        ($marketplaceRows['large-v3-turbo']['supportedLanguageIds'] ?? null)
            === array_keys(STTSetting::LANGUAGE_PRESETS),
        'Marketplace must expose the configured language catalog for WhisperKit'
    );
    expectModelLanguageCompatibility(
        ($marketplaceRows['parakeet-tdt-0.6b-v3']['supportedLanguagesAttribute'] ?? null)
            === implode(',', STTSetting::PARAKEET_LANGUAGE_IDS),
        'Marketplace must provide a template-safe Parakeet language capability attribute'
    );

    $formSource = (string)file_get_contents(__DIR__ . '/../App/Forms/ModuleLocalSpeechToTextForm.php');
    expectModelLanguageCompatibility(
        str_contains($formSource, 'STTSetting::languagePresetsForModel(STTSetting::selectedModelId())'),
        'Settings form must render only language options supported by the selected model'
    );

    $baseControllerSource = (string)file_get_contents(
        __DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php'
    );
    expectModelLanguageCompatibility(
        substr_count($baseControllerSource, 'assertModelLanguageCompatibility(') >= 2,
        'Settings save must validate the submitted language against the effective model'
    );

    $controllerSource = (string)file_get_contents(
        __DIR__ . '/../App/Controllers/ModuleLocalSpeechToTextController.php'
    );
    expectModelLanguageCompatibility(
        str_contains($controllerSource, 'assertModelLanguageCompatibility('),
        'Model save must validate the selected model against the current language'
    );

    $viewSource = (string)file_get_contents(
        __DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt'
    );
    expectModelLanguageCompatibility(
        str_contains($viewSource, 'data-selected-language="{{ selectedLanguage|e }}"')
            && str_contains($viewSource, 'data-supported-languages="{{ row[\'supportedLanguagesAttribute\']|e }}"')
            && str_contains($viewSource, 'id="stt-model-language-compatibility"'),
        'Model marketplace must expose compatibility data and an inline warning'
    );

    $sourceJs = (string)file_get_contents(
        __DIR__ . '/../public/assets/js/src/module-local-speech-to-text.js'
    );
    $bundledJs = (string)file_get_contents(
        __DIR__ . '/../public/assets/js/module-local-speech-to-text.js'
    );
    expectModelLanguageCompatibility(
        str_contains($sourceJs, 'selectedModelSupportsLanguage')
            && str_contains($sourceJs, 'stt-model-language-compatibility')
            && str_contains($sourceJs, 'var compatible = updateCompatibilityState();')
            && $sourceJs === $bundledJs,
        'Source and bundled UI must refresh compatibility for every model selection'
    );

    $messageKey = 'module_local_speech_to_text_ModelLanguageIncompatible';
    $russianMessages = include __DIR__ . '/../Messages/ru.php';
    $englishMessages = include __DIR__ . '/../Messages/en.php';
    expectModelLanguageCompatibility(
        ($russianMessages[$messageKey] ?? '')
            === 'Выбранный язык не поддерживается этой моделью. Выберите «Авто» или совместимый язык в настройках.',
        'Russian compatibility warning must explain how to resolve the conflict'
    );
    expectModelLanguageCompatibility(
        ($englishMessages[$messageKey] ?? '')
            === 'The selected language is not supported by this model. Choose Auto or a compatible language in settings.',
        'English compatibility warning must explain how to resolve the conflict'
    );
    foreach (glob(__DIR__ . '/../Messages/*.php') ?: [] as $messageFile) {
        $messages = include $messageFile;
        expectModelLanguageCompatibility(
            isset($messages[$messageKey]) && trim((string)$messages[$messageKey]) !== '',
            basename($messageFile) . ' is missing the model/language compatibility warning'
        );
    }

    echo "Model/language compatibility contract OK\n";
}
