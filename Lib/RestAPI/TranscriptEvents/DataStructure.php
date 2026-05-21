<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'cursor' => [
                    'type' => 'string',
                    'description' => 'Last processed cursor in created_at:event_id format.',
                    'example' => '1760784793:42',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of events. Maximum is 200.',
                    'example' => 100,
                    'required' => false,
                ],
            ],
            'response' => [
                'events' => [
                    'type' => 'array',
                    'description' => 'Completed transcript events.',
                ],
                'next_cursor' => [
                    'type' => 'string',
                    'description' => 'Cursor to store after processing returned events.',
                ],
            ],
        ];
    }

    public static function getListItemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cursor' => ['type' => 'string'],
                'event_type' => ['type' => 'string'],
                'result_id' => ['type' => 'integer'],
                'job_id' => ['type' => 'integer', 'nullable' => true],
                'call_id' => ['type' => 'string'],
                'created_at' => ['type' => 'integer'],
                'update_type' => ['type' => 'string'],
            ],
        ];
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => self::getListItemSchema(),
                ],
                'next_cursor' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
