<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases;

class DataStructure extends \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'job' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'call_id' => ['type' => 'string'],
                        'file_url' => ['type' => 'string', 'example' => '/job-recordings/42'],
                        'lease_token' => ['type' => 'string'],
                        'lease_expires_at' => ['type' => 'integer'],
                        'language_hint' => ['type' => 'string'],
                        'model_id' => ['type' => 'string'],
                        'model_repo' => ['type' => 'string'],
                        'model_engine' => ['type' => 'string', 'enum' => ['whisperKit', 'parakeet']],
                        'model_artifact_type' => ['type' => 'string', 'enum' => ['whisperkit-coreml', 'parakeet-coreml']],
                        'whisperkit_model' => ['type' => ['string', 'null']],
                        'model_display_name' => ['type' => 'string'],
                        'model_revision' => ['type' => ['string', 'null']],
                        'model_sha256_manifest' => ['type' => ['array', 'null']],
                        'vocabulary_terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'has_settings_changed' => ['type' => 'boolean'],
            ],
        ];
    }
}
