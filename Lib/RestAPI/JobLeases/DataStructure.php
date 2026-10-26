<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases;

/**
 * POST /job-leases: the leased job (or null) and the processing-settings flag.
 */
class DataStructure extends \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaJobLease',
            'properties' => [
                'job' => [
                    'type' => ['object', 'null'],
                    'description' => 'module_local_speech_to_text_ApiFieldLeasedJob',
                    'properties' => [
                        'id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldJobId'],
                        'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId'],
                        'file_url' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldJobFileUrl', 'example' => '/job-recordings/42'],
                        'lease_token' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiParamLeaseToken'],
                        'lease_expires_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldLeaseExpiresAt'],
                        'language_hint' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldLanguageHint'],
                        'model_id' => ['type' => 'string'],
                        'model_repo' => ['type' => 'string'],
                        'model_engine' => ['type' => 'string', 'enum' => ['whisperKit', 'parakeet', 'fasterWhisper', 'gigaam', 'tOne', 'whisperCpp']],
                        'model_artifact_type' => ['type' => 'string', 'enum' => ['whisperkit-coreml', 'parakeet-coreml', 'ctranslate2', 'gigaam-onnx', 'parakeet-onnx', 't-one-onnx', 'whisper-ggml']],
                        'whisperkit_model' => ['type' => ['string', 'null']],
                        'model_display_name' => ['type' => 'string'],
                        'model_revision' => ['type' => ['string', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldModelRevision'],
                        'model_sha256_manifest' => ['type' => ['array', 'null']],
                        'vocabulary_terms' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'module_local_speech_to_text_ApiFieldVocabularyTerms'],
                    ],
                ],
                'has_settings_changed' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldHasSettingsChanged'],
                'platform_mismatch' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldPlatformMismatch'],
            ],
            'required' => ['job', 'has_settings_changed'],
        ];
    }
}
