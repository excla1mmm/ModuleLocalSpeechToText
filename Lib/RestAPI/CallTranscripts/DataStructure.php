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
            'properties' => [
                'aggregate_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldAggregateId', 'example' => 77],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => 'mikopbx-1784455200.42'],
                'revision' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldRevision', 'example' => 2],
                'content_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldContentHash', 'example' => str_repeat('a', 64)],
                'parts_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => 'module_local_speech_to_text_ApiFieldPartsHash', 'example' => str_repeat('b', 64)],
                'completeness_status' => ['type' => 'string', 'enum' => ['complete', 'legacy_unresolved'], 'description' => 'module_local_speech_to_text_ApiFieldCompletenessStatus', 'example' => 'complete'],
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
                            'result_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldResultId', 'example' => 501],
                            'source_index' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldSourceIndex', 'example' => 0],
                            'part_order' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldPartOrder', 'example' => 0],
                            'index' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldIndex', 'example' => 0],
                            'start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldStartMs', 'example' => 0],
                            'end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldEndMs', 'example' => 4210],
                            'absolute_start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldAbsoluteStartMs', 'example' => 1784455200000],
                            'absolute_end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldAbsoluteEndMs', 'example' => 1784455204210],
                            'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldText', 'example' => 'Здравствуйте, чем я могу помочь?'],
                            'normalized_text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldNormalizedText', 'example' => 'здравствуйте, чем я могу помочь?'],
                            'source_label' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldSourceLabel', 'example' => 'part:501'],
                            'recording_fingerprint' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldRecordingFingerprint', 'example' => str_repeat('c', 64)],
                            'resolution_status' => ['type' => 'string', 'enum' => ['complete', 'legacy_unresolved'], 'description' => 'module_local_speech_to_text_ApiFieldResolutionStatus', 'example' => 'complete'],
                            'channel' => ['type' => 'string', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldChannel', 'example' => 'left'],
                            'speaker' => ['type' => 'string', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldSpeaker', 'example' => 'operator'],
                        ],
                    ],
                ],
                'segment_count' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldSegmentCount', 'example' => 1],
                'turns' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldAggregatedTurns',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'turn_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldTurnId', 'example' => 'call:55d14f21df10a70e:r501:s0'],
                            'index' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldIndex', 'example' => 0],
                            'start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldStartMs', 'example' => 0],
                            'end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldEndMs', 'example' => 4210],
                            'absolute_start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldAbsoluteStartMs', 'example' => 1784455200000],
                            'absolute_end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldAbsoluteEndMs', 'example' => 1784455204210],
                            'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldText', 'example' => 'Здравствуйте, чем я могу помочь?'],
                            'speaker' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldSourceLabel', 'example' => 'part:501'],
                            'channel' => ['type' => 'string', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldChannel', 'example' => 'left'],
                            'source_segment_ids' => ['type' => 'array', 'description' => 'module_local_speech_to_text_ApiFieldSourceSegmentIds', 'items' => ['type' => 'string'], 'example' => ['call:55d14f21df10a70e:r501:s0']],
                            'source_result_ids' => ['type' => 'array', 'description' => 'module_local_speech_to_text_ApiFieldSourceResultIds', 'items' => ['type' => 'integer'], 'example' => [501]],
                        ],
                    ],
                ],
                'turn_count' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldTurnCount', 'example' => 1],
                'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldTranscriptText', 'example' => '[part:501] Здравствуйте, чем я могу помочь?'],
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
            'result_id' => 501,
            'source_index' => 0,
            'part_order' => 0,
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 4210,
            'absolute_start_ms' => 1784455200000,
            'absolute_end_ms' => 1784455204210,
            'text' => 'Здравствуйте, чем я могу помочь?',
            'normalized_text' => 'здравствуйте, чем я могу помочь?',
            'source_label' => 'part:501',
            'recording_fingerprint' => $fingerprint,
            'resolution_status' => 'complete',
            'channel' => 'left',
            'speaker' => 'operator',
        ];
        $turn = [
            'turn_id' => $segmentId,
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 4210,
            'absolute_start_ms' => 1784455200000,
            'absolute_end_ms' => 1784455204210,
            'text' => 'Здравствуйте, чем я могу помочь?',
            'speaker' => 'part:501',
            'channel' => 'left',
            'source_segment_ids' => [$segmentId],
            'source_result_ids' => [501],
        ];

        return [
            'aggregate_id' => 77,
            'call_id' => 'mikopbx-1784455200.42',
            'revision' => 2,
            'content_hash' => str_repeat('a', 64),
            'parts_hash' => str_repeat('b', 64),
            'completeness_status' => 'complete',
            'is_current' => true,
            'created_at' => 1784455400,
            'part_manifest' => [$part],
            'transcript' => [
                'segments' => [$segment],
                'segment_count' => 1,
                'turns' => [$turn],
                'turn_count' => 1,
                'text' => '[part:501] Здравствуйте, чем я могу помочь?',
            ],
        ];
    }
}
