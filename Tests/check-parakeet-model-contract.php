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
        'label' => 'module_local_speech_to_text_ModelParakeetV3',
        'description' => 'module_local_speech_to_text_ModelParakeetV3Desc',
        'source_url' => 'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml',
        'profile' => 'module_local_speech_to_text_ModelProfileParakeetV3',
        'best_for' => 'module_local_speech_to_text_ModelParakeetV3BestFor',
        'tradeoff' => 'module_local_speech_to_text_ModelParakeetV3Tradeoff',
        'language' => '25 languages',
        'license' => 'CC BY 4.0',
    ];
    expectParakeet(STTSetting::DEFAULT_MODEL === 'large-v3-turbo', 'Default model must remain Large V3 Turbo');
    expectParakeet(
        (STTSetting::MODEL_PRESETS['parakeet-tdt-0.6b-v3'] ?? null) === $expected,
        'Parakeet preset must match the reviewed runtime descriptor exactly'
    );

    ModulesModelsBase::$values = [STTSetting::SELECTED_MODEL => 'parakeet-tdt-0.6b-v3'];
    expectParakeet(STTSetting::selectedModelId() === 'parakeet-tdt-0.6b-v3', 'Parakeet selection must persist');
    expectParakeet(STTSetting::selectedModelEngine() === 'parakeet', 'Selected engine must be Parakeet');
    expectParakeet(STTSetting::selectedModelArtifactType() === 'parakeet-coreml', 'Selected artifact must be Parakeet Core ML');

    $translation = new class {
        public function _(string $key): string { return $key; }
    };
    $rows = (new ModelMarketplaceService($translation))->modelMarketplaceRows();
    $matches = array_values(array_filter($rows, static fn (array $row): bool => ($row['id'] ?? '') === 'parakeet-tdt-0.6b-v3'));
    expectParakeet(count($matches) === 1, 'Marketplace must contain exactly one Parakeet row');
    expectParakeet(($matches[0]['selected'] ?? false) === true, 'Selected Parakeet row must use the generic selected state');
    expectParakeet(($matches[0]['sourceUrl'] ?? '') === $expected['source_url'], 'Marketplace must expose the official source URL');
    expectParakeet(($matches[0]['technical'] ?? '') === 'parakeet · Core ML · 25 languages · CC BY 4.0', 'Parakeet technical label mismatch');

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
