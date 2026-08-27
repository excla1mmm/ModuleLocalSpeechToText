<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerApiContract;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;

final class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [];
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_WorkerApiContractApiDescription',
            'required' => ['contract', 'api_version', 'module', 'module_version', 'capabilities'],
            'properties' => [
                'contract' => [
                    'type' => 'string',
                    'enum' => [WorkerApiContract::CONTRACT],
                    'example' => WorkerApiContract::CONTRACT,
                ],
                'api_version' => [
                    'type' => 'integer',
                    'enum' => [WorkerApiContract::API_VERSION],
                    'example' => WorkerApiContract::API_VERSION,
                ],
                'module' => [
                    'type' => 'string',
                    'enum' => [WorkerApiContract::MODULE_ID],
                    'example' => WorkerApiContract::MODULE_ID,
                ],
                'module_version' => [
                    'type' => 'string',
                    'example' => '1.45',
                ],
                'capabilities' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'example' => WorkerApiContract::CAPABILITIES,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
