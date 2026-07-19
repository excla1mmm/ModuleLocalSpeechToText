<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class SubmitErrorResponseDataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
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
            'description' => 'module_local_speech_to_text_ApiSchemaSubmitError',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'error'],
                    'description' => 'module_local_speech_to_text_ApiFieldJobStatusAfterError',
                    'example' => 'pending',
                ],
                'attempts' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'module_local_speech_to_text_ApiFieldJobAttempts',
                    'example' => 1,
                ],
            ],
            'example' => ['status' => 'pending', 'attempts' => 1],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
