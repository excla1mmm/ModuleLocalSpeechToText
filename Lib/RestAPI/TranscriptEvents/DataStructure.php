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
                    'description' => 'module_local_speech_to_text_ApiParamCursor',
                    'example' => '1784455000:120',
                    'required' => false,
                    'pattern' => '^\\d+:\\d+$',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamEventLimit',
                    'example' => 100,
                    'required' => false,
                    'minimum' => 1,
                    'maximum' => 200,
                ],
            ],
            'response' => [
                'events' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldTranscriptEvents',
                ],
                'next_cursor' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiFieldNextCursor',
                ],
            ],
        ];
    }

    public static function getListItemSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaTranscriptEvent',
            'properties' => [
                'cursor' => ['type' => 'string', 'pattern' => '^\\d+:\\d+$', 'description' => 'module_local_speech_to_text_ApiFieldCursor', 'example' => '1784455350:121'],
                'event_type' => ['type' => 'string', 'enum' => ['transcript.completed'], 'description' => 'module_local_speech_to_text_ApiFieldTranscriptEventType', 'example' => 'transcript.completed'],
                'result_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldResultId', 'example' => 501],
                'job_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldJobId', 'example' => 42],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => 'mikopbx-1784455200.42'],
                'created_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldCreatedAt', 'example' => 1784455350],
                'update_type' => ['type' => 'string', 'enum' => ['created'], 'description' => 'module_local_speech_to_text_ApiFieldUpdateType', 'example' => 'created'],
            ],
        ];
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaTranscriptEventList',
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldTranscriptEvents',
                    'items' => self::getListItemSchema(),
                ],
                'next_cursor' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldNextCursor', 'example' => '1784455350:121'],
                'limit' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldLimit', 'example' => 100],
            ],
            'example' => [
                'events' => [[
                    'cursor' => '1784455350:121',
                    'event_type' => 'transcript.completed',
                    'result_id' => 501,
                    'job_id' => 42,
                    'call_id' => 'mikopbx-1784455200.42',
                    'created_at' => 1784455350,
                    'update_type' => 'created',
                ]],
                'next_cursor' => '1784455350:121',
                'limit' => 100,
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
