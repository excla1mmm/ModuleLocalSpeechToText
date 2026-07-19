<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class ListDataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
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
            'description' => 'module_local_speech_to_text_ApiSchemaTranscriptList',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldTranscriptItems',
                    'items' => DataStructure::getListItemSchema(),
                ],
                'pagination' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldPagination',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldLimit', 'example' => 50],
                        'offset' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldOffset', 'example' => 0],
                        'total' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldTotal', 'example' => 137],
                        'has_more' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldHasMore', 'example' => true],
                    ],
                ],
            ],
            'example' => [
                'items' => [DataStructure::getSummaryExample()],
                'pagination' => ['limit' => 50, 'offset' => 0, 'total' => 137, 'has_more' => true],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
