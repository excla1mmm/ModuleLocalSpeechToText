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
                    'description' => 'module_local_speech_to_text_ApiParamJobId',
                    'example' => 42,
                    'required' => true,
                    'minimum' => 1,
                ],
                'worker_uid' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'example' => 'mac-mini-stt-01',
                    'required' => true,
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'lease_token' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamLeaseToken',
                    'example' => '9d587d4f4c5b4a8fa2423e746dc82f6a',
                    'required' => false,
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'transcript' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiParamTranscript',
                    'example' => [[
                        'segment_id' => 'seg-0001',
                        'index' => 0,
                        'start_ms' => 0,
                        'end_ms' => 4210,
                        'start' => 0.0,
                        'end' => 4.21,
                        'text' => 'Здравствуйте, чем я могу помочь?',
                        'channel' => 'left',
                        'speaker' => 'operator',
                    ]],
                    'required' => true,
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamLanguage',
                    'example' => 'ru',
                    'required' => false,
                    'minLength' => 2,
                    'maxLength' => 16,
                ],
                'duration_ms' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamDurationMs',
                    'example' => 83400,
                    'required' => false,
                    'minimum' => 0,
                ],
                'model_used' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamModelUsed',
                    'example' => 'whisper-large-v3',
                    'required' => false,
                    'maxLength' => 255,
                ],
                'technical_info' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiParamTechnicalInfo',
                    'example' => [
                        'voice_analytics' => [
                            'speech_ratio' => 0.73,
                            'recognized_segments' => 18,
                        ],
                    ],
                    'required' => false,
                ],
                'error_msg' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamErrorMessage',
                    'example' => 'Audio decoding failed',
                    'required' => false,
                    'maxLength' => 1000,
                ],
            ],
            'response' => [
                'job' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldClaimedJob',
                ],
                'has_settings_changed' => [
                    'type' => 'boolean',
                    'description' => 'module_local_speech_to_text_ApiFieldSettingsChanged',
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
            'description' => 'module_local_speech_to_text_ApiSchemaNextJob',
            'properties' => [
                'job' => [
                    'description' => 'module_local_speech_to_text_ApiFieldClaimedJob',
                    'oneOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldJobId', 'example' => 42],
                                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => 'mikopbx-1784455200.42'],
                                'file_url' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldFileUrl', 'example' => '/job-recordings/42'],
                                'lease_token' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldLeaseToken', 'example' => '9d587d4f4c5b4a8fa2423e746dc82f6a'],
                                'lease_expires_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldLeaseExpiresAt', 'example' => 1784456100],
                                'duration_hint' => [
                                    'type' => 'number',
                                    'nullable' => true,
                                    'description' => 'module_local_speech_to_text_ApiFieldDurationHint',
                                    'example' => 83,
                                ],
                                'language_hint' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldLanguageHint', 'example' => 'ru'],
                                'model_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldModelId', 'example' => 'openai_whisper-large-v3-v20240930'],
                                'model_repo' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldModelRepo', 'example' => 'argmaxinc/whisperkit-coreml'],
                                'model_engine' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldModelEngine', 'example' => 'whisperkit'],
                                'model_artifact_type' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldModelArtifactType', 'example' => 'whisperkit-coreml'],
                                'whisperkit_model' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldWhisperKitModel', 'example' => 'openai_whisper-large-v3-v20240930'],
                                'model_display_name' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldModelDisplayName', 'example' => 'Whisper Large v3'],
                                'model_source_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'module_local_speech_to_text_ApiFieldModelSourceUrl', 'example' => 'https://huggingface.co/argmaxinc/whisperkit-coreml'],
                                'vocabulary_terms' => [
                                    'type' => 'array',
                                    'description' => 'module_local_speech_to_text_ApiFieldVocabularyTerms',
                                    'items' => ['type' => 'string'],
                                    'example' => ['MikoPBX', 'SIP'],
                                ],
                            ],
                        ],
                        ['type' => 'null'],
                    ],
                ],
                'has_settings_changed' => [
                    'type' => 'boolean',
                    'description' => 'module_local_speech_to_text_ApiFieldSettingsChanged',
                    'example' => false,
                ],
            ],
            'example' => [
                'job' => [
                    'id' => 42,
                    'call_id' => 'mikopbx-1784455200.42',
                    'file_url' => '/job-recordings/42',
                    'lease_token' => '9d587d4f4c5b4a8fa2423e746dc82f6a',
                    'lease_expires_at' => 1784456100,
                    'duration_hint' => 83,
                    'language_hint' => 'ru',
                    'model_id' => 'openai_whisper-large-v3-v20240930',
                    'model_repo' => 'argmaxinc/whisperkit-coreml',
                    'model_engine' => 'whisperkit',
                    'model_artifact_type' => 'whisperkit-coreml',
                    'whisperkit_model' => 'openai_whisper-large-v3-v20240930',
                    'model_display_name' => 'Whisper Large v3',
                    'model_source_url' => 'https://huggingface.co/argmaxinc/whisperkit-coreml',
                    'vocabulary_terms' => ['MikoPBX', 'SIP'],
                ],
                'has_settings_changed' => false,
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
