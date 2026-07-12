<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return ['request' => [
            'id' => ['type' => 'integer', 'required' => true],
            'revision' => ['type' => 'integer', 'required' => false],
        ], 'response' => []];
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'aggregate_id' => ['type' => 'integer'],
            'call_id' => ['type' => 'string'],
            'revision' => ['type' => 'integer'],
            'content_hash' => ['type' => 'string'],
            'parts_hash' => ['type' => 'string'],
            'completeness_status' => ['type' => 'string'],
            'is_current' => ['type' => 'boolean'],
            'part_manifest' => ['type' => 'array'],
            'transcript' => ['type' => 'object'],
        ]];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
