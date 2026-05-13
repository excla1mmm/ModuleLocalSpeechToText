<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Transcription job id.',
                    'example' => 42,
                    'required' => true,
                ],
                'worker_uid' => [
                    'type' => 'string',
                    'description' => 'Stable worker identifier.',
                    'example' => 'mac-mini-stt-01',
                    'required' => true,
                    'maxLength' => 255,
                ],
                'transcript' => [
                    'type' => 'array',
                    'description' => 'Transcript segments returned by the worker. Segment timestamps must use integer start_ms and end_ms fields.',
                    'required' => true,
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Detected language.',
                    'example' => 'ru',
                    'required' => false,
                    'maxLength' => 16,
                ],
                'duration_ms' => [
                    'type' => 'integer',
                    'description' => 'Audio duration in milliseconds.',
                    'example' => 83400,
                    'required' => false,
                ],
                'model_used' => [
                    'type' => 'string',
                    'description' => 'Model used for transcription.',
                    'example' => 'whisper-large-v3',
                    'required' => false,
                    'maxLength' => 255,
                ],
                'technical_info' => [
                    'type' => 'object',
                    'description' => 'Optional technical quality report produced by the worker.',
                    'required' => false,
                ],
                'error_msg' => [
                    'type' => 'string',
                    'description' => 'Worker error message.',
                    'example' => 'Audio decoding failed',
                    'required' => false,
                    'maxLength' => 1000,
                ],
            ],
            'response' => [
                'job' => [
                    'type' => 'object',
                    'description' => 'Claimed job or null when queue is empty.',
                ],
            ],
        ];
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'job' => [
                    'oneOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'call_id' => ['type' => 'string'],
                                'file_url' => ['type' => 'string'],
                                'duration_hint' => ['type' => 'number', 'nullable' => true],
                                'language_hint' => ['type' => 'string'],
                                'model_id' => ['type' => 'string'],
                                'model_repo' => ['type' => 'string'],
                                'vocabulary_terms' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        ['type' => 'null'],
                    ],
                ],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
