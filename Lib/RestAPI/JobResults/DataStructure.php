<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure as JobDataStructure;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return JobDataStructure::getParameterDefinitions();
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
            'description' => 'module_local_speech_to_text_ApiSchemaSubmitResult',
            'example' => []
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
