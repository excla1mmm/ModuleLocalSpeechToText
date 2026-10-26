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
                    'default' => '0:0',
                    'pattern' => '^\\d+:\\d+$',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamEventLimit',
                    'example' => 100,
                    'default' => 100,
                    'minimum' => 1,
                    'maximum' => 200,
                    'required' => false,
                ],
                'include_deleted' => [
                    'type' => 'boolean',
                    'description' => 'module_local_speech_to_text_ApiParamIncludeDeleted',
                    'example' => false,
                    'default' => false,
                    'required' => false,
                ],
                'include_summaries' => [
                    'type' => 'boolean',
                    'description' => 'module_local_speech_to_text_ApiParamIncludeSummaries',
                    'example' => false,
                    'default' => false,
                    'required' => false,
                ],
            ],
            'response' => [
                'source_instance_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'module_local_speech_to_text_ApiFieldSourceInstanceId'],
                'contract_version' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldContractVersion'],
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
            'required' => ['cursor', 'event_type', 'call_id', 'aggregate_id', 'revision', 'content_hash', 'parts_hash', 'completeness_status'],
            'properties' => [
                'cursor' => ['type' => 'string', 'pattern' => '^\\d+:\\d+$', 'description' => 'module_local_speech_to_text_ApiFieldCursor', 'example' => '1784455400:81'],
                'event_type' => ['type' => 'string', 'enum' => ['call-transcript.completed', 'call-transcript.updated', 'call-transcript.deleted', 'call-summary.completed'], 'description' => 'module_local_speech_to_text_ApiFieldCallTranscriptEventType', 'example' => 'call-transcript.updated'],
                'call_id' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 255, 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => '1784455200.42'],
                'aggregate_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldAggregateId', 'example' => 77],
                'revision' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldRevision', 'example' => 2],
                'content_hash' => ['type' => ['string', 'null'], 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldContentHash', 'example' => str_repeat('a', 64)],
                'parts_hash' => ['type' => ['string', 'null'], 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldPartsHash', 'example' => str_repeat('b', 64)],
                'completeness_status' => ['type' => ['string', 'null'], 'enum' => ['complete', 'legacy_unresolved', null], 'description' => 'module_local_speech_to_text_ApiFieldCompletenessStatus', 'example' => 'complete'],
                'deleted_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldDeletedAt', 'example' => 1784459000],
                'deletion_reason' => ['type' => 'string', 'enum' => ['manual', 'retention'], 'description' => 'module_local_speech_to_text_ApiFieldDeletionReason', 'example' => 'manual'],
            ],
        ];
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaCallTranscriptEventList',
            'required' => ['source_instance_id', 'contract_version', 'events', 'next_cursor'],
            'properties' => [
                'source_instance_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'module_local_speech_to_text_ApiFieldSourceInstanceId', 'example' => '0123456789abcdef0123456789abcdef'],
                'contract_version' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldContractVersion', 'example' => 3],
                'events' => ['type' => 'array', 'description' => 'module_local_speech_to_text_ApiFieldCallTranscriptEvents', 'items' => self::getListItemSchema()],
                'next_cursor' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldNextCursor', 'example' => '1784455400:81'],
            ],
            'example' => [
                'source_instance_id' => '0123456789abcdef0123456789abcdef',
                'contract_version' => 3,
                'events' => [[
                    'cursor' => '1784455400:81',
                    'event_type' => 'call-transcript.updated',
                    'call_id' => '1784455200.42',
                    'aggregate_id' => 77,
                    'revision' => 2,
                    'content_hash' => str_repeat('a', 64),
                    'parts_hash' => str_repeat('b', 64),
                    'completeness_status' => 'complete',
                ]],
                'next_cursor' => '1784455400:81',
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
