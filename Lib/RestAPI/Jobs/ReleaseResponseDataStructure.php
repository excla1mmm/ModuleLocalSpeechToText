<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class ReleaseResponseDataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
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
            'description' => 'module_local_speech_to_text_ApiSchemaRelease',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending'],
                    'description' => 'module_local_speech_to_text_ApiFieldReleaseStatus',
                    'example' => 'pending',
                ],
            ],
            'example' => ['status' => 'pending'],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
