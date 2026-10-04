<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerProcessingSettings;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'X-MikoPBX-Worker-API-Version' => [
                    'type' => 'integer',
                    'in' => 'header',
                    'description' => 'module_local_speech_to_text_ApiVersion',
                    'enum' => [2],
                    'example' => 2,
                ],
                'X-MikoPBX-Worker-UID' => [
                    'type' => 'string',
                    'in' => 'header',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'example' => 'mac-mini-stt-01',
                ],
            ],
            'response' => [],
        ];
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        $properties = [
            'temperature' => self::number(0.0, 1.0, 'module_local_speech_to_text_ApiSettingTemperature', 0.0),
            'temperature_fallback_step' => self::number(0.0, 1.0, 'module_local_speech_to_text_ApiSettingTemperatureFallbackStep', 0.2),
            'temperature_fallback_count' => self::integer(0, 10, 'module_local_speech_to_text_ApiSettingTemperatureFallbackCount', 5),
            'top_k' => self::integer(1, 100, 'module_local_speech_to_text_ApiSettingTopK', 5),
            'compression_ratio_threshold' => self::number(0.0, 10.0, 'module_local_speech_to_text_ApiSettingCompressionRatioThreshold', 2.4),
            'log_probability_threshold' => self::number(-10.0, 0.0, 'module_local_speech_to_text_ApiSettingLogProbabilityThreshold', -1.0),
            'first_token_log_probability_threshold' => self::number(-10.0, 0.0, 'module_local_speech_to_text_ApiSettingFirstTokenLogProbabilityThreshold', -1.5),
            'no_speech_threshold' => self::number(0.0, 1.0, 'module_local_speech_to_text_ApiSettingNoSpeechThreshold', 0.6),
            'audio_normalization' => [
                'type' => 'boolean',
                'description' => 'module_local_speech_to_text_ApiSettingAudioNormalization',
                'example' => true,
            ],
            'voice_activity_detection' => [
                'type' => 'boolean',
                'description' => 'module_local_speech_to_text_ApiSettingVoiceActivityDetection',
                'example' => true,
            ],
            'max_segment_duration_ms' => self::integer(
                1000,
                120000,
                'module_local_speech_to_text_ApiSettingMaxSegmentDurationMs',
                30000
            ),
            'segment_overlap_ms' => self::integer(
                0,
                10000,
                'module_local_speech_to_text_ApiSettingSegmentOverlapMs',
                1000
            ),
        ];

        $selectedModelProperties = [
            'model_id' => [
                'type' => 'string',
                'example' => 'parakeet-tdt-0.6b-v3',
            ],
            'model_repo' => [
                'type' => 'string',
                'example' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
            ],
            'model_engine' => [
                'type' => 'string',
                'enum' => ['whisperKit', 'parakeet', 'fasterWhisper', 'gigaam', 'tOne'],
                'example' => 'parakeet',
            ],
            'model_artifact_type' => [
                'type' => 'string',
                'enum' => ['whisperkit-coreml', 'parakeet-coreml', 'ctranslate2', 'gigaam-pytorch', 't-one-onnx'],
                'example' => 'parakeet-coreml',
            ],
            'model_platform' => [
                'type' => 'string',
                'enum' => ['macos', 'linux'],
                'description' => 'module_local_speech_to_text_ApiFieldModelPlatform',
                'example' => 'macos',
            ],
            'model_display_name' => [
                'type' => 'string',
                'example' => 'Parakeet TDT 0.6B v3',
            ],
            'model_revision' => [
                'type' => 'string',
                'pattern' => '^[a-f0-9]{40}$',
                'description' => 'module_local_speech_to_text_ApiFieldModelRevision',
                'example' => '0f63a7800b00dd0226abd051b906c246e1907482',
            ],
            'model_sha256_manifest' => self::modelManifestSchema(),
        ];

        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaProcessingSettings',
            'required' => ['schema_version', 'processing_settings', 'selected_model'],
            'properties' => [
                'schema_version' => [
                    'type' => 'integer',
                    'enum' => [1],
                    'description' => 'module_local_speech_to_text_ApiFieldProcessingSchemaVersion',
                    'example' => 1,
                ],
                'processing_settings' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldProcessingSettings',
                    'required' => array_keys($properties),
                    'properties' => $properties,
                    'additionalProperties' => false,
                ],
                'selected_model' => [
                    'type' => 'object',
                    'required' => ['model_id', 'model_repo', 'model_engine', 'model_artifact_type', 'model_display_name'],
                    'properties' => $selectedModelProperties,
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
            'example' => [
                'schema_version' => 1,
                'processing_settings' => [
                    'temperature' => 0.0,
                    'temperature_fallback_step' => 0.2,
                    'temperature_fallback_count' => 5,
                    'top_k' => 5,
                    'compression_ratio_threshold' => 2.4,
                    'log_probability_threshold' => -1.0,
                    'first_token_log_probability_threshold' => -1.5,
                    'no_speech_threshold' => 0.6,
                    'audio_normalization' => true,
                    'voice_activity_detection' => true,
                    'max_segment_duration_ms' => 30000,
                    'segment_overlap_ms' => 1000,
                ],
                'selected_model' => [
                    'model_id' => 'parakeet-tdt-0.6b-v3',
                    'model_repo' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
                    'model_engine' => 'parakeet',
                    'model_artifact_type' => 'parakeet-coreml',
                    'model_platform' => 'macos',
                    'model_display_name' => 'Parakeet TDT 0.6B v3',
                ],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }

    private static function number(float $minimum, float $maximum, string $description, float $example): array
    {
        return [
            'type' => 'number',
            'minimum' => $minimum,
            'maximum' => $maximum,
            'description' => $description,
            'example' => $example,
        ];
    }

    private static function integer(int $minimum, int $maximum, string $description, int $example): array
    {
        return [
            'type' => 'integer',
            'minimum' => $minimum,
            'maximum' => $maximum,
            'description' => $description,
            'example' => $example,
        ];
    }

    private static function modelManifestSchema(): array
    {
        return [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 128,
            'items' => [
                'type' => 'object',
                'required' => ['path', 'size', 'sha256'],
                'properties' => [
                    'path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1024],
                    'size' => ['type' => 'integer', 'minimum' => 1],
                    'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }
}
