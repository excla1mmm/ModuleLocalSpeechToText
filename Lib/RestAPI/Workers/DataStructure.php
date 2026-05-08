<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'worker_uid' => [
                    'type' => 'string',
                    'description' => 'Stable worker identifier.',
                    'example' => 'mac-mini-stt-01',
                    'required' => true,
                    'maxLength' => 255,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Human-readable worker name.',
                    'example' => 'Mac mini STT',
                    'required' => false,
                    'maxLength' => 255,
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'Speech recognition model name.',
                    'example' => 'whisper-large-v3',
                    'required' => false,
                    'maxLength' => 255,
                ],
            ],
            'response' => [
                'worker_id' => [
                    'type' => 'integer',
                    'description' => 'Internal worker record id.',
                    'example' => 1,
                ],
                'poll_interval' => [
                    'type' => 'integer',
                    'description' => 'Recommended polling interval in seconds.',
                    'example' => 30,
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
            'properties' => [
                'worker_id' => ['type' => 'integer'],
                'poll_interval' => ['type' => 'integer'],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
