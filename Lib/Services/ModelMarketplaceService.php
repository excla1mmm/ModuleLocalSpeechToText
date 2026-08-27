<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;

class ModelMarketplaceService
{
    public function __construct(private mixed $translation)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function modelMarketplaceRows(): array
    {
        $rows = [];
        $selectedModel = STTSetting::selectedModelId();

        foreach (STTSetting::modelPresets() as $modelId => $model) {
            $supportedLanguageIds = is_array($model['supported_languages'] ?? null)
                ? array_values($model['supported_languages'])
                : array_keys(STTSetting::LANGUAGE_PRESETS);
            $rows[] = [
                'id' => $modelId,
                'title' => $this->modelText((string)$model['label']),
                'repo' => (string)($model['repo'] ?? ''),
                'runtime' => (string)($model['model'] ?? $modelId),
                'label' => $this->modelText((string)$model['label']),
                'description' => $this->modelText((string)$model['description']),
                'profile' => $this->modelText((string)($model['profile'] ?? '')),
                'bestFor' => $this->modelText((string)($model['best_for'] ?? '')),
                'tradeoff' => $this->modelText((string)($model['tradeoff'] ?? '')),
                'sourceUrl' => (string)($model['source_url'] ?? ''),
                'selected' => $modelId === $selectedModel,
                'supportedLanguageIds' => $supportedLanguageIds,
                'supportedLanguagesAttribute' => implode(',', $supportedLanguageIds),
                'technical' => implode(' · ', array_values(array_filter([
                    (string)($model['engine'] ?? 'WhisperKit'),
                    $this->modelArtifactLabel((string)($model['artifact_type'] ?? '')),
                    (string)($model['language'] ?? ''),
                    (string)($model['license'] ?? ''),
                ]))),
            ];
        }

        return $rows;
    }

    private function modelArtifactLabel(string $artifactType): string
    {
        return match ($artifactType) {
            'whisperkit-coreml', 'parakeet-coreml' => 'Core ML',
            '' => '',
            default => $artifactType,
        };
    }

    private function modelText(string $text): string
    {
        return str_starts_with($text, 'module_local_speech_to_text_')
            ? $this->translation->_($text)
            : $text;
    }
}
