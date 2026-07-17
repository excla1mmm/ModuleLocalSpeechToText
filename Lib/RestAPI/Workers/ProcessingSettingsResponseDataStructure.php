<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class ProcessingSettingsResponseDataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [];
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        $properties = [
            'temperature' => self::number(0.0, 1.0, 'Initial decoding temperature.'),
            'temperature_fallback_step' => self::number(0.0, 1.0, 'Temperature increment between fallback attempts.'),
            'temperature_fallback_count' => self::integer(0, 10, 'Number of fallback temperatures.'),
            'top_k' => self::integer(1, 100, 'WhisperKit top-K sampling limit.'),
            'compression_ratio_threshold' => self::number(0.0, 10.0, 'Repetition and compression fallback threshold.'),
            'log_probability_threshold' => self::number(-10.0, 0.0, 'Average log-probability threshold.'),
            'first_token_log_probability_threshold' => self::number(-10.0, 0.0, 'First-token log-probability threshold.'),
            'no_speech_threshold' => self::number(0.0, 1.0, 'No-speech decision threshold.'),
            'audio_normalization' => [
                'type' => 'boolean',
                'description' => 'Whether the worker applies its normalization pipeline.',
            ],
            'voice_activity_detection' => [
                'type' => 'boolean',
                'description' => 'Whether the worker applies RMS voice activity detection.',
            ],
            'max_segment_duration_ms' => self::integer(
                1000,
                120000,
                'Maximum VAD segment duration in integer milliseconds.'
            ),
            'segment_overlap_ms' => self::integer(
                0,
                10000,
                'VAD segment overlap in integer milliseconds; must be less than max_segment_duration_ms.'
            ),
        ];

        return [
            'type' => 'object',
            'required' => ['schema_version', 'processing_settings'],
            'properties' => [
                'schema_version' => [
                    'type' => 'integer',
                    'enum' => [1],
                    'description' => 'Processing profile schema version.',
                ],
                'processing_settings' => [
                    'type' => 'object',
                    'required' => array_keys($properties),
                    'properties' => $properties,
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }

    private static function number(float $minimum, float $maximum, string $description): array
    {
        return [
            'type' => 'number',
            'minimum' => $minimum,
            'maximum' => $maximum,
            'description' => $description,
        ];
    }

    private static function integer(int $minimum, int $maximum, string $description): array
    {
        return [
            'type' => 'integer',
            'minimum' => $minimum,
            'maximum' => $maximum,
            'description' => $description,
        ];
    }
}
