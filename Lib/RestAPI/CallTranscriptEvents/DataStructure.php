<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'cursor' => ['type' => 'string', 'required' => false],
                'limit' => ['type' => 'integer', 'required' => false],
            ],
            'response' => [
                'events' => ['type' => 'array'],
                'next_cursor' => ['type' => 'string'],
            ],
        ];
    }

    public static function getListItemSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'cursor' => ['type' => 'string'],
            'event_type' => ['type' => 'string'],
            'aggregate_id' => ['type' => 'integer'],
            'call_id' => ['type' => 'string'],
            'revision' => ['type' => 'integer'],
            'content_hash' => ['type' => 'string'],
            'parts_hash' => ['type' => 'string'],
            'completeness_status' => ['type' => 'string'],
            'created_at' => ['type' => 'integer'],
        ]];
    }

    public static function getDetailSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'events' => ['type' => 'array', 'items' => self::getListItemSchema()],
            'next_cursor' => ['type' => 'string'],
            'limit' => ['type' => 'integer'],
        ]];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
