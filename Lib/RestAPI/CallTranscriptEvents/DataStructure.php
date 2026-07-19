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
                'cursor' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamCursor',
                    'example' => '1784455000:80',
                    'pattern' => '^\\d+:\\d+$',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamEventLimit',
                    'example' => 100,
                    'minimum' => 1,
                    'maximum' => 200,
                    'required' => false,
                ],
            ],
            'response' => [
                'events' => ['type' => 'array', 'description' => 'module_local_speech_to_text_ApiFieldCallTranscriptEvents'],
                'next_cursor' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldNextCursor'],
            ],
        ];
    }

    public static function getListItemSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaCallTranscriptEvent',
            'properties' => [
                'cursor' => ['type' => 'string', 'pattern' => '^\\d+:\\d+$', 'description' => 'module_local_speech_to_text_ApiFieldCursor', 'example' => '1784455400:81'],
                'event_type' => ['type' => 'string', 'enum' => ['call-transcript.completed', 'call-transcript.updated'], 'description' => 'module_local_speech_to_text_ApiFieldCallTranscriptEventType', 'example' => 'call-transcript.updated'],
                'aggregate_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldAggregateId', 'example' => 77],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => 'mikopbx-1784455200.42'],
                'revision' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldRevision', 'example' => 2],
                'content_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldContentHash', 'example' => str_repeat('a', 64)],
                'parts_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldPartsHash', 'example' => str_repeat('b', 64)],
                'completeness_status' => ['type' => 'string', 'enum' => ['complete', 'legacy_unresolved'], 'description' => 'module_local_speech_to_text_ApiFieldCompletenessStatus', 'example' => 'complete'],
                'created_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldCreatedAt', 'example' => 1784455400],
            ],
        ];
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaCallTranscriptEventList',
            'properties' => [
                'events' => ['type' => 'array', 'description' => 'module_local_speech_to_text_ApiFieldCallTranscriptEvents', 'items' => self::getListItemSchema()],
                'next_cursor' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldNextCursor', 'example' => '1784455400:81'],
                'limit' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldLimit', 'example' => 100],
            ],
            'example' => [
                'events' => [[
                    'cursor' => '1784455400:81',
                    'event_type' => 'call-transcript.updated',
                    'aggregate_id' => 77,
                    'call_id' => 'mikopbx-1784455200.42',
                    'revision' => 2,
                    'content_hash' => str_repeat('a', 64),
                    'parts_hash' => str_repeat('b', 64),
                    'completeness_status' => 'complete',
                    'created_at' => 1784455400,
                ]],
                'next_cursor' => '1784455400:81',
                'limit' => 100,
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
