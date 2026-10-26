<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure as JobDataStructure;

/**
 * PUT /job-results/{id}: the worker's transcript and diagnostics.
 *
 * Core does not copy array `items` into the generated request body, so the
 * segment shape and the integer-millisecond rule are also spelled out in the
 * `transcript` description.
 */
class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        $definitions = JobDataStructure::getParameterDefinitions();
        $definitions['request'] += [
            'transcript' => [
                'type' => 'array',
                'description' => 'module_local_speech_to_text_ApiParamResultTranscript',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'start_ms' => ['type' => 'integer', 'minimum' => 0],
                        'end_ms' => ['type' => 'integer', 'minimum' => 0],
                        'channel' => ['type' => 'string'],
                        'speaker' => ['type' => 'string'],
                    ],
                ],
                'example' => [
                    ['text' => 'Hello, this is Mary.', 'start_ms' => 0, 'end_ms' => 1200, 'channel' => 'left'],
                ],
            ],
            'technical_info' => [
                'type' => 'object',
                'description' => 'module_local_speech_to_text_ApiParamResultTechnicalInfo',
                'example' => ['model' => ['resolved' => 'parakeet-tdt-0.6b-v3'], 'input' => ['channels' => 2]],
            ],
            'language' => [
                'type' => 'string',
                'description' => 'module_local_speech_to_text_ApiParamResultLanguage',
                'example' => 'en',
            ],
            'language_hint' => [
                'type' => 'string',
                'description' => 'module_local_speech_to_text_ApiParamResultLanguageHint',
                'example' => 'en',
            ],
            'model_used' => [
                'type' => 'string',
                'description' => 'module_local_speech_to_text_ApiFieldModelUsed',
                'example' => 'parakeet-tdt-0.6b-v3',
            ],
            'duration_ms' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'module_local_speech_to_text_ApiParamResultDurationMs',
                'example' => 61250,
            ],
        ];

        return $definitions;
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'description' => 'module_local_speech_to_text_ApiSchemaSubmitResult',
            'oneOf' => [
                [
                    'type' => 'array',
                    'items' => false,
                    'maxItems' => 0,
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['error']],
                        'error_code' => ['type' => 'string', 'enum' => ['result_persistence_failed']],
                    ],
                    'required' => ['status', 'error_code'],
                ],
            ],
            'example' => [],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
