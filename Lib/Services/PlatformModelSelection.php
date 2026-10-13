<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use InvalidArgumentException;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * Turns the marketplace post (selected_model[platform] for every platform)
 * into the settings to save. Only models that differ from the configured
 * ones count as a change. Every configured platform keeps its model under its
 * own key, because SELECTED_MODEL names only the model chosen last.
 */
final class PlatformModelSelection
{
    /**
     * @param callable(string): void $assertLanguage Rejects a model that does not know the selected language.
     * @return array<string, string> Empty when nothing changed.
     */
    public static function settingsToSave(mixed $posted, callable $assertLanguage): array
    {
        if (is_string($posted)) {
            $posted = [STTSetting::modelPlatform($posted) => $posted];
        }
        if (!is_array($posted)) {
            return [];
        }

        $configured = STTSetting::configuredModelIds();
        $values = [];
        $lastChosen = null;
        foreach (STTSetting::WORKER_PLATFORMS as $platform) {
            $modelId = is_string($posted[$platform] ?? null) ? trim(substr($posted[$platform], 0, 255)) : '';
            if ($modelId === '' || $modelId === ($configured[$platform] ?? null)) {
                continue;
            }
            if (!array_key_exists($modelId, STTSetting::modelPresets()) || STTSetting::modelPlatform($modelId) !== $platform) {
                throw new InvalidArgumentException('Unknown model for platform ' . $platform);
            }
            $assertLanguage($modelId);
            $values[STTSetting::selectedModelKey($platform)] = $modelId;
            $lastChosen = $modelId;
        }
        if ($lastChosen === null) {
            return [];
        }
        foreach ($configured as $platform => $modelId) {
            $values += [STTSetting::selectedModelKey($platform) => $modelId];
        }
        $values[STTSetting::SELECTED_MODEL] = $lastChosen;

        return $values;
    }
}
