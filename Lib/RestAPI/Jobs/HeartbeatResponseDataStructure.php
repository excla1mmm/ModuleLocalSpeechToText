<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class HeartbeatResponseDataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return DataStructure::getParameterDefinitions();
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaHeartbeat',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['processing'],
                    'description' => 'module_local_speech_to_text_ApiFieldHeartbeatStatus',
                    'example' => 'processing',
                ],
                'lease_expires_at' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiFieldLeaseExpiresAt',
                    'example' => 1784456400,
                ],
            ],
            'example' => ['status' => 'processing', 'lease_expires_at' => 1784456400],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
