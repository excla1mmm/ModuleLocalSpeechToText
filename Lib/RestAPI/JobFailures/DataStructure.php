<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobFailures;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;
use Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure as JobDataStructure;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        $definitions = JobDataStructure::getParameterDefinitions();
        $definitions['request']['error_msg'] = [
            'type' => 'string',
            'maxLength' => 1000,
            'default' => 'Worker failed to process audio',
            'description' => 'module_local_speech_to_text_ApiParamFailureErrorMsg',
            'example' => 'Model failed to load',
        ];

        return $definitions;
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaSubmitFailure',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'error'], 'description' => 'module_local_speech_to_text_ApiFieldJobStatus'],
                'attempts' => ['type' => 'integer', 'minimum' => 1, 'description' => 'module_local_speech_to_text_ApiFieldJobAttempts'],
            ],
            'required' => ['status', 'attempts'],
            'example' => ['status' => 'pending', 'attempts' => 1],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
