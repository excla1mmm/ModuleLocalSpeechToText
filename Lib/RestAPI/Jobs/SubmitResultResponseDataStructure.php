<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class SubmitResultResponseDataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
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
            'type' => 'array',
            'items' => false,
            'maxItems' => 0,
            'description' => 'Empty data returned after accepting a transcript.',
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
