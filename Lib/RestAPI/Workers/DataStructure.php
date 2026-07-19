<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'worker_uid' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'example' => 'mac-mini-stt-01',
                    'required' => true,
                    'minLength' => 1,
                    'maxLength' => 255,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerName',
                    'example' => 'Mac mini STT',
                    'required' => false,
                    'maxLength' => 255,
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerModel',
                    'example' => 'whisper-large-v3',
                    'required' => false,
                    'maxLength' => 255,
                ],
            ],
            'response' => [
                'worker_id' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiFieldWorkerId',
                    'example' => 1,
                ],
                'poll_interval' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiFieldPollInterval',
                    'example' => 30,
                ],
            ],
        ];
    }

    public static function getListItemSchema(): array
    {
        return self::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaWorkerRegistration',
            'properties' => [
                'worker_id' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiFieldWorkerId',
                    'example' => 1,
                ],
                'poll_interval' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiFieldPollInterval',
                    'example' => 30,
                ],
            ],
            'example' => ['worker_id' => 1, 'poll_interval' => 30],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
