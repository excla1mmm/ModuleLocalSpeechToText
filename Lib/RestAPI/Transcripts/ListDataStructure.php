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
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => DataStructure::getListItemSchema(),
                ],
                'pagination' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer'],
                        'offset' => ['type' => 'integer'],
                        'total' => ['type' => 'integer'],
                        'has_more' => ['type' => 'boolean'],
                    ],
                ],
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
