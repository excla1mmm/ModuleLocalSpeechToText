<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryLeases;

class DataStructure extends \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'result_id' => ['type' => 'integer'],
                        'lease_token' => ['type' => 'string'],
                        'lease_expires_at' => ['type' => 'integer'],
                        'model_id' => ['type' => 'string', 'example' => 'gemma-3-4b-it-Q4_K_M'],
                        'request' => [
                            'type' => 'object',
                            'description' => 'module_local_speech_to_text_ApiFieldSummaryRequest',
                        ],
                    ],
                ],
                'has_settings_changed' => ['type' => 'boolean'],
            ],
        ];
    }
}
