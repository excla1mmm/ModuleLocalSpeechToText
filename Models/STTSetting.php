<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use RuntimeException;

/**
 * @Indexes(
 *     [name='key', columns=['key'], type='UNIQUE']
 * )
 */
class STTSetting extends ModulesModelsBase
{
    public const string DEFAULT_LANGUAGE = 'default_language';
    public const string POLL_INTERVAL_SEC = 'poll_interval_sec';
    public const string MAX_FILE_SIZE_MB = 'max_file_size_mb';
    public const string CDR_SCAN_LIMIT = 'cdr_scan_limit';
    public const string PROCESSING_TIMEOUT_SEC = 'processing_timeout_sec';
    public const string SELECTED_MODEL = 'selected_model';
    public const string DELAYED_RECORDINGS = 'delayed_recordings';

    public const string DEFAULT_MODEL = 'large-v3-turbo';

    public const array MODEL_PRESETS = [
        'tiny' => [
            'repo' => 'mlx-community/whisper-tiny',
            'label' => 'module_local_speech_to_text_ModelTiny',
            'description' => 'module_local_speech_to_text_ModelTinyDesc',
        ],
        'base' => [
            'repo' => 'mlx-community/whisper-base-mlx',
            'label' => 'module_local_speech_to_text_ModelBase',
            'description' => 'module_local_speech_to_text_ModelBaseDesc',
        ],
        'small' => [
            'repo' => 'mlx-community/whisper-small-mlx',
            'label' => 'module_local_speech_to_text_ModelSmall',
            'description' => 'module_local_speech_to_text_ModelSmallDesc',
        ],
        'medium' => [
            'repo' => 'mlx-community/whisper-medium-mlx',
            'label' => 'module_local_speech_to_text_ModelMedium',
            'description' => 'module_local_speech_to_text_ModelMediumDesc',
        ],
        'large-v3-turbo' => [
            'repo' => 'mlx-community/whisper-large-v3-turbo',
            'label' => 'module_local_speech_to_text_ModelLargeV3Turbo',
            'description' => 'module_local_speech_to_text_ModelLargeV3TurboDesc',
        ],
        'large-v3' => [
            'repo' => 'mlx-community/whisper-large-v3-mlx',
            'label' => 'module_local_speech_to_text_ModelLargeV3',
            'description' => 'module_local_speech_to_text_ModelLargeV3Desc',
        ],
    ];

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $key = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $value = '';

    public function initialize(): void
    {
        $this->setSource('m_STTSetting');
        parent::initialize();
    }

    public static function getValue(string $key, string $default = ''): string
    {
        $setting = self::findFirst([
            'conditions' => 'key = :key:',
            'bind' => [
                'key' => $key,
            ],
        ]);

        return $setting === null ? $default : (string)$setting->value;
    }

    public static function setValue(string $key, string $value): void
    {
        $setting = self::findFirst([
            'conditions' => 'key = :key:',
            'bind' => [
                'key' => $key,
            ],
        ]);
        if ($setting === null) {
            $setting = new self();
            $setting->key = $key;
        }

        $setting->value = $value;

        if (!$setting->save()) {
            throw new RuntimeException(implode(', ', $setting->getMessages()));
        }
    }

    public static function selectedModelId(): string
    {
        return self::normalizeModelId(self::getValue(self::SELECTED_MODEL, self::DEFAULT_MODEL));
    }

    /**
     * @return array<string, string>
     */
    public static function selectedModelPreset(): array
    {
        return self::MODEL_PRESETS[self::selectedModelId()];
    }

    public static function selectedModelRepo(): string
    {
        return self::selectedModelPreset()['repo'];
    }

    public static function normalizeModelId(string $modelId): string
    {
        $modelId = trim($modelId);

        return array_key_exists($modelId, self::MODEL_PRESETS) ? $modelId : self::DEFAULT_MODEL;
    }
}
