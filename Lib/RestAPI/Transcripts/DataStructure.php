<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts;

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
                    'description' => 'Completed transcript result id.',
                    'example' => 42,
                    'required' => true,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Page size. Maximum is 200.',
                    'example' => 50,
                    'required' => false,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Page offset.',
                    'example' => 0,
                    'required' => false,
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Filter by call date from ISO date or datetime.',
                    'example' => '2026-05-01',
                    'required' => false,
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Filter by call date to ISO date or datetime.',
                    'example' => '2026-05-18',
                    'required' => false,
                ],
            ],
            'response' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'Completed transcript summaries.',
                ],
                'pagination' => [
                    'type' => 'object',
                    'description' => 'Pagination metadata.',
                ],
            ],
        ];
    }

    public static function getListItemSchema(): array
    {
        return self::summarySchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::summarySchema()['properties'] + [
                'transcript' => [
                    'type' => 'object',
                    'properties' => [
                        'segment_count' => ['type' => 'integer'],
                        'turn_count' => ['type' => 'integer'],
                        'text' => [
                            'type' => 'string',
                            'description' => 'Plain readable transcript assembled from speaker turns.',
                        ],
                        'turns' => [
                            'type' => 'array',
                            'description' => 'Readable speaker turns coalesced from raw transcript segments.',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'turn_id' => ['type' => 'string'],
                                    'index' => ['type' => 'integer'],
                                    'start_ms' => ['type' => 'integer', 'nullable' => true],
                                    'end_ms' => ['type' => 'integer', 'nullable' => true],
                                    'start' => ['type' => 'number'],
                                    'end' => ['type' => 'number'],
                                    'text' => ['type' => 'string'],
                                    'channel' => ['type' => 'string', 'nullable' => true],
                                    'speaker' => ['type' => 'string', 'nullable' => true],
                                    'speaker_key' => ['type' => 'string'],
                                    'source_segment_ids' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                    ],
                                    'source_indexes' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'integer'],
                                    ],
                                ],
                            ],
                        ],
                        'segments' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'segment_id' => ['type' => 'string'],
                                    'index' => ['type' => 'integer'],
                                    'start_ms' => ['type' => 'integer', 'nullable' => true],
                                    'end_ms' => ['type' => 'integer', 'nullable' => true],
                                    'start' => ['type' => 'number'],
                                    'end' => ['type' => 'number'],
                                    'text' => ['type' => 'string'],
                                    'channel' => ['type' => 'string', 'nullable' => true],
                                    'speaker' => ['type' => 'string', 'nullable' => true],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }

    private static function summarySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'result_id' => ['type' => 'integer'],
                'job_id' => ['type' => 'integer', 'nullable' => true],
                'call_id' => ['type' => 'string'],
                'cdr_id' => ['type' => 'integer', 'nullable' => true],
                'cdr_endtime' => ['type' => 'integer', 'nullable' => true],
                'call_timestamp' => ['type' => 'integer'],
                'finished_at' => ['type' => 'integer', 'nullable' => true],
                'result_created_at' => ['type' => 'integer'],
                'duration' => ['type' => 'number', 'nullable' => true],
                'language' => ['type' => 'string'],
                'model_used' => ['type' => 'string'],
                'recording' => [
                    'type' => 'object',
                    'properties' => [
                        'available' => ['type' => 'boolean'],
                        'name' => ['type' => 'string'],
                    ],
                ],
                'call' => [
                    'type' => 'object',
                    'properties' => [
                        'start' => ['type' => 'string'],
                        'endtime' => ['type' => 'string'],
                        'src_num' => ['type' => 'string'],
                        'dst_num' => ['type' => 'string'],
                        'did' => ['type' => 'string'],
                        'disposition' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }
}
