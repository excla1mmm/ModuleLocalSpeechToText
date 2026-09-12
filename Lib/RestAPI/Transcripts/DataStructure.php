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
                    'description' => 'module_local_speech_to_text_ApiParamTranscriptId',
                    'example' => 501,
                    'required' => true,
                    'minimum' => 1,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamLimit',
                    'example' => 50,
                    'required' => false,
                    'minimum' => 1,
                    'maximum' => 200,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamOffset',
                    'example' => 0,
                    'required' => false,
                    'minimum' => 0,
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamDateFrom',
                    'example' => '2026-07-01',
                    'required' => false,
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamDateTo',
                    'example' => '2026-07-19T23:59:59+03:00',
                    'required' => false,
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamTranscriptSearch',
                    'example' => 'статус заказа',
                    'required' => false,
                    'maxLength' => 256,
                ],
            ],
            'response' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldTranscriptItems',
                ],
                'pagination' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldPagination',
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
            'description' => 'module_local_speech_to_text_ApiSchemaTranscriptDetail',
            'properties' => self::summarySchema()['properties'] + [
                'technical_info' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldTechnicalInfo',
                    'additionalProperties' => true,
                    'properties' => [
                        'voice_analytics' => [
                            'type' => 'object',
                            'description' => 'module_local_speech_to_text_ApiFieldVoiceAnalytics',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
                'transcript' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldTranscript',
                    'properties' => [
                        'segment_count' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldSegmentCount', 'example' => 2],
                        'turn_count' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldTurnCount', 'example' => 2],
                        'text' => [
                            'type' => 'string',
                            'description' => 'module_local_speech_to_text_ApiFieldTranscriptText',
                            'example' => "operator: Здравствуйте, чем я могу помочь?\ncustomer: Подскажите статус заказа.",
                        ],
                        'turns' => [
                            'type' => 'array',
                            'description' => 'module_local_speech_to_text_ApiFieldTurns',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'turn_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldTurnId', 'example' => 'stt:501:turn:0'],
                                    'index' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldIndex', 'example' => 0],
                                    'start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldStartMs', 'example' => 0],
                                    'end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldEndMs', 'example' => 4210],
                                    'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldText', 'example' => 'Здравствуйте, чем я могу помочь?'],
                                    'channel' => ['type' => 'string', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldChannel', 'example' => 'left'],
                                    'source_segment_ids' => [
                                        'type' => 'array',
                                        'description' => 'module_local_speech_to_text_ApiFieldSourceSegmentIds',
                                        'items' => ['type' => 'string'],
                                        'example' => ['seg-0001'],
                                    ],
                                    'source_indexes' => [
                                        'type' => 'array',
                                        'description' => 'module_local_speech_to_text_ApiFieldSourceIndexes',
                                        'items' => ['type' => 'integer'],
                                        'example' => [0],
                                    ],
                                ],
                            ],
                        ],
                        'segments' => [
                            'type' => 'array',
                            'description' => 'module_local_speech_to_text_ApiFieldSegments',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'segment_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldSegmentId', 'example' => 'seg-0001'],
                                    'index' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldIndex', 'example' => 0],
                                    'start_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldStartMs', 'example' => 0],
                                    'end_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldEndMs', 'example' => 4210],
                                    'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldText', 'example' => 'Здравствуйте, чем я могу помочь?'],
                                    'channel' => ['type' => 'string', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldChannel', 'example' => 'left'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'example' => self::detailExample(),
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }

    public static function getSummaryExample(): array
    {
        return self::summaryExample();
    }

    private static function summarySchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaTranscriptSummary',
            'properties' => [
                'result_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldResultId', 'example' => 501],
                'job_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldJobId', 'example' => 42],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => 'mikopbx-1784455200.42'],
                'cdr_id' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldCdrId', 'example' => 7301],
                'cdr_endtime' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldCdrEndTime', 'example' => 1784455283],
                'call_timestamp' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldCallTimestamp', 'example' => 1784455283],
                'finished_at' => ['type' => 'integer', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldFinishedAt', 'example' => 1784455350],
                'result_created_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldResultCreatedAt', 'example' => 1784455350],
                'duration' => ['type' => 'number', 'nullable' => true, 'description' => 'module_local_speech_to_text_ApiFieldDurationSeconds', 'example' => 83.4],
                'language' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldLanguage', 'example' => 'ru'],
                'model_used' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldModelUsed', 'example' => 'whisper-large-v3'],
                'recording' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldRecording',
                    'properties' => [
                        'available' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldRecordingAvailable', 'example' => true],
                        'name' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldRecordingName', 'example' => 'out-74951234567-101-20260719-130000-1784455200.42.wav'],
                    ],
                ],
                'call' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldCallMetadata',
                    'properties' => [
                        'start' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallStart', 'example' => '2026-07-19 13:00:00'],
                        'endtime' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallEnd', 'example' => '2026-07-19 13:01:23'],
                        'src_num' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldSourceNumber', 'example' => '74951234567'],
                        'dst_num' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldDestinationNumber', 'example' => '101'],
                        'did' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldDid', 'example' => '74950000000'],
                        'disposition' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldDisposition', 'example' => 'ANSWERED'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function summaryExample(): array
    {
        return [
            'result_id' => 501,
            'job_id' => 42,
            'call_id' => 'mikopbx-1784455200.42',
            'cdr_id' => 7301,
            'cdr_endtime' => 1784455283,
            'call_timestamp' => 1784455283,
            'finished_at' => 1784455350,
            'result_created_at' => 1784455350,
            'duration' => 83.4,
            'language' => 'ru',
            'model_used' => 'whisper-large-v3',
            'recording' => ['available' => true, 'name' => 'out-74951234567-101-20260719-130000-1784455200.42.wav'],
            'call' => [
                'start' => '2026-07-19 13:00:00',
                'endtime' => '2026-07-19 13:01:23',
                'src_num' => '74951234567',
                'dst_num' => '101',
                'did' => '74950000000',
                'disposition' => 'ANSWERED',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function detailExample(): array
    {
        $segment = [
            'segment_id' => 'seg-0001',
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 4210,
            'text' => 'Здравствуйте, чем я могу помочь?',
            'channel' => 'left',
        ];
        $turn = [
            'turn_id' => 'stt:501:turn:0',
            'index' => 0,
            'start_ms' => 0,
            'end_ms' => 4210,
            'text' => 'Здравствуйте, чем я могу помочь?',
            'channel' => 'left',
            'source_segment_ids' => ['seg-0001'],
            'source_indexes' => [0],
        ];

        return self::summaryExample() + [
            'technical_info' => ['voice_analytics' => ['speech_ratio' => 0.73]],
            'transcript' => [
                'segments' => [$segment],
                'segment_count' => 1,
                'turns' => [$turn],
                'turn_count' => 1,
                'text' => 'operator: Здравствуйте, чем я могу помочь?',
            ],
        ];
    }
}
