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
    use MikoPBX\Modules\Models\ModulesModelsBase;
    use Modules\ModuleLocalSpeechToText\Lib\Services\PlatformModelSelection;

    require_once __DIR__ . '/../Models/STTSetting.php';
    require_once __DIR__ . '/../Lib/Services/PlatformModelSelection.php';

    function expectPlatformModelSelection(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $checked = [];
    $assertLanguage = static function (string $modelId) use (&$checked): void {
        $checked[] = $modelId;
    };

    // Nothing posted or nothing changed saves nothing.
    ModulesModelsBase::$values = ['selected_model' => 'parakeet-tdt-0.6b-v3'];
    expectPlatformModelSelection(PlatformModelSelection::settingsToSave(null, $assertLanguage) === [], 'An empty post must save nothing');
    expectPlatformModelSelection(
        PlatformModelSelection::settingsToSave(['macos' => 'parakeet-tdt-0.6b-v3'], $assertLanguage) === [],
        'Re-posting the chosen model must save nothing'
    );

    // Choosing a Linux model keeps the macOS one: both platforms then run side by side.
    $checked = [];
    $values = PlatformModelSelection::settingsToSave(
        ['macos' => 'parakeet-tdt-0.6b-v3', 'linux' => 't-one', 'windows' => 'gigaam-v3-windows'],
        $assertLanguage
    );
    expectPlatformModelSelection(
        $values === [
            'selected_model_linux' => 't-one',
            'selected_model_windows' => 'gigaam-v3-windows',
            'selected_model_macos' => 'parakeet-tdt-0.6b-v3',
            'selected_model' => 'gigaam-v3-windows',
        ],
        'Changed platforms must be saved and the configured macOS model pinned to its own key: ' . json_encode($values)
    );
    expectPlatformModelSelection($checked === ['t-one', 'gigaam-v3-windows'], 'Every newly chosen model must pass the language check');

    // A legacy Linux selection survives a macOS choice.
    ModulesModelsBase::$values = ['selected_model' => 'gigaam-multilingual'];
    expectPlatformModelSelection(
        PlatformModelSelection::settingsToSave(['macos' => 'large-v3'], $assertLanguage) === [
            'selected_model_macos' => 'large-v3',
            'selected_model_linux' => 'gigaam-multilingual',
            'selected_model' => 'large-v3',
        ],
        'The legacy Linux model must be kept under its own key'
    );

    // A model posted for the wrong platform or an unknown model is rejected.
    foreach ([['linux' => 'large-v3'], ['windows' => 'no-such-model']] as $posted) {
        try {
            PlatformModelSelection::settingsToSave($posted, $assertLanguage);
            throw new LogicException('accepted');
        } catch (InvalidArgumentException) {
        } catch (LogicException) {
            expectPlatformModelSelection(false, 'A foreign or unknown model must be rejected: ' . json_encode($posted));
        }
    }

    // A language conflict stops the save.
    try {
        PlatformModelSelection::settingsToSave(['linux' => 'gigaam-v3'], static function (): void {
            throw new InvalidArgumentException('language');
        });
        expectPlatformModelSelection(false, 'A language conflict must stop the save');
    } catch (InvalidArgumentException) {
    }

    ModulesModelsBase::$values = [];
    echo "Platform model selection OK\n";
}
