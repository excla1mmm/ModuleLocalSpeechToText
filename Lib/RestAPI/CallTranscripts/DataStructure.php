<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts;

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
                    'description' => 'module_local_speech_to_text_ApiParamAggregateId',
                    'example' => 77,
                    'minimum' => 1,
                    'required' => true,
                ],
                'revision' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamRevision',
                    'example' => 2,
                    'minimum' => 1,
                    'required' => false,
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
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaCallTranscript',
            'required' => ['source_instance_id', 'contract_version', 'aggregate_id', 'call_id', 'revision', 'content_hash', 'parts_hash', 'completeness_status', 'language', 'is_current', 'created_at'],
            'properties' => [
                'source_instance_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'module_local_speech_to_text_ApiFieldSourceInstanceId', 'example' => '0123456789abcdef0123456789abcdef'],
                'contract_version' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldContractVersion', 'example' => 3],
                'aggregate_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldAggregateId', 'example' => 77],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => '1784455200.42'],
                'revision' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldRevision', 'example' => 2],
                'content_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldContentHash', 'example' => str_repeat('a', 64)],
                'parts_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldPartsHash', 'example' => str_repeat('b', 64)],
                'completeness_status' => ['type' => 'string', 'enum' => ['complete', 'legacy_unresolved'], 'description' => 'module_local_speech_to_text_ApiFieldCompletenessStatus', 'example' => 'complete'],
                'language' => ['type' => ['string', 'null'], 'pattern' => '^[a-z]{2,3}$', 'description' => 'module_local_speech_to_text_ApiFieldCallTranscriptLanguage', 'example' => 'ru'],
                'is_current' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldIsCurrent', 'example' => true],
                'created_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldCreatedAt', 'example' => 1784455400],
                'part_manifest' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldPartManifest',
                    'items' => self::partSchema(),
                ],
                'transcript' => self::transcriptSchema(),
            ],
            'example' => self::detailExample(),
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    private static function partSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaCallTranscriptPart',
            'properties' => [
                'result_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldResultId', 'example' => 501],
                'job_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldJobId', 'example' => 42],
                'cdr_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldCdrId', 'example' => 7301],
                'uniqueid' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCdrUniqueId', 'example' => '1784455200.42'],
                'cdr_start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldCdrStartMs', 'example' => 1784455200000],
                'cdr_end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldCdrEndMs', 'example' => 1784455283000],
                'recording_fingerprint' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldRecordingFingerprint', 'example' => str_repeat('c', 64)],
                'content_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldContentHash', 'example' => str_repeat('d', 64)],
                'source_label' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldSourceLabel', 'example' => 'part:501'],
                'resolution_status' => ['type' => 'string', 'enum' => ['complete', 'legacy_unresolved'], 'description' => 'module_local_speech_to_text_ApiFieldResolutionStatus', 'example' => 'complete'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function transcriptSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiFieldAggregatedTranscript',
            'properties' => [
                'segments' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldAggregatedSegments',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'segment_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldSegmentId', 'example' => 'call:55d14f21df10a70e:r501:s0'],
                            'index' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldIndex', 'example' => 0],
                            'result_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldResultId', 'example' => 501],
                            'start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldStartMs', 'example' => 0],
                            'end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldEndMs', 'example' => 4210],
                            'recording_start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldRecordingStartMs', 'example' => 0],
                            'recording_end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldRecordingEndMs', 'example' => 4210],
                            'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldText', 'example' => 'Здравствуйте, чем я могу помочь?'],
                            'channel' => ['type' => 'string', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldChannel', 'example' => 'left'],
                        ],
                    ],
                ],
                'turns' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldAggregatedTurns',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'turn_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldTurnId', 'example' => 'call:55d14f21df10a70e:r501:s0'],
                            'index' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldIndex', 'example' => 0],
                            'source_result_ids' => [
                                'type' => 'array',
                                'description' => 'module_local_speech_to_text_ApiFieldSourceResultIds',
                                'items' => ['type' => 'integer'],
                                'example' => [501],
                            ],
                            'start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldStartMs', 'example' => 0],
                            'end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldEndMs', 'example' => 4210],
                            'recording_start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldRecordingStartMs', 'example' => 0],
                            'recording_end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldRecordingEndMs', 'example' => 4210],
                            'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldText', 'example' => 'Здравствуйте, чем я могу помочь?'],
                            'channel' => ['type' => 'string', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldChannel', 'example' => 'left'],
                        ],
                    ],
                ],
                'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldTranscriptText', 'example' => '[left] Здравствуйте, чем я могу помочь?'],
                'timeline_reliable' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldTimelineReliable', 'example' => true],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function detailExample(): array
    {
        $segmentId = 'call:55d14f21df10a70e:r501:s0';
        $fingerprint = str_repeat('c', 64);
        $part = [
            'result_id' => 501,
            'job_id' => 42,
            'cdr_id' => 7301,
            'uniqueid' => '1784455200.42',
            'cdr_start_ms' => 1784455200000,
            'cdr_end_ms' => 1784455283000,
            'recording_fingerprint' => $fingerprint,
            'content_hash' => str_repeat('d', 64),
            'source_label' => 'part:501',
            'resolution_status' => 'complete',
        ];
        $segment = [
            'segment_id' => $segmentId,
            'index' => 0,
            'result_id' => 501,
            'start_ms' => 0,
            'end_ms' => 4210,
            'recording_start_ms' => 0,
            'recording_end_ms' => 4210,
            'text' => 'Здравствуйте, чем я могу помочь?',
            'channel' => 'left',
        ];
        $turn = [
            'turn_id' => $segmentId,
            'index' => 0,
            'source_result_ids' => [501],
            'start_ms' => 0,
            'end_ms' => 4210,
            'recording_start_ms' => 0,
            'recording_end_ms' => 4210,
            'text' => 'Здравствуйте, чем я могу помочь?',
            'channel' => 'left',
        ];

        return [
            'source_instance_id' => '0123456789abcdef0123456789abcdef',
            'contract_version' => 3,
            'aggregate_id' => 77,
            'call_id' => '1784455200.42',
            'revision' => 2,
            'content_hash' => str_repeat('a', 64),
            'parts_hash' => str_repeat('b', 64),
            'completeness_status' => 'complete',
            'language' => 'ru',
            'is_current' => true,
            'created_at' => 1784455400,
            'part_manifest' => [$part],
            'transcript' => [
                'segments' => [$segment],
                'turns' => [$turn],
                'text' => '[left] Здравствуйте, чем я могу помочь?',
                'timeline_reliable' => true,
            ],
        ];
    }
}
