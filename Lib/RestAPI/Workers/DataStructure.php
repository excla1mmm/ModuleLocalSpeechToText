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
                'id' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiFieldWorkerId',
                    'example' => 1,
                    'required' => true,
                    'minimum' => 1,
                ],
                'worker_uid' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'example' => 'mac-mini-stt-01',
                    'required' => true,
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$',
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
                'platform' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerPlatform',
                    'example' => 'linux',
                    'required' => false,
                    'enum' => ['macos', 'linux', 'windows'],
                ],
                'status' => [
                    'type' => 'string',
                    'example' => 'online',
                    'required' => false,
                    'enum' => ['online', 'offline'],
                ],
                'api_version' => [
                    'type' => 'integer',
                    'example' => 2,
                    'required' => false,
                    'minimum' => 1,
                ],
                'app_version' => [
                    'type' => 'string',
                    'example' => '2.5.0',
                    'required' => false,
                    'maxLength' => 255,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamLimit',
                    'example' => 50,
                    'required' => false,
                    'minimum' => 1,
                    'maximum' => 200,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'module_local_speech_to_text_ApiParamOffset',
                    'example' => 0,
                    'required' => false,
                    'minimum' => 0,
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
                'id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldWorkerId', 'example' => 1],
                'worker_uid' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiParamWorkerUid', 'example' => 'mac-mini-stt-01'],
                'name' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiParamWorkerName', 'example' => 'Mac mini STT'],
                'ip' => ['type' => 'string', 'example' => '192.0.2.10'],
                'status' => ['type' => 'string', 'enum' => ['online', 'offline'], 'example' => 'online'],
                'model' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiParamWorkerModel', 'example' => 'Whisper Large v3'],
                'api_key_id' => ['type' => 'integer', 'nullable' => true, 'example' => 7],
                'api_version' => ['type' => 'integer', 'example' => 2],
                'app_version' => ['type' => 'string', 'example' => '2.5.0'],
                'compatible' => ['type' => 'boolean', 'example' => true],
                'last_seen' => ['type' => 'integer', 'nullable' => true, 'example' => 1786512000],
                'created_at' => ['type' => 'integer', 'example' => 1786500000],
                'updated_at' => ['type' => 'integer', 'example' => 1786512000],
            ],
            'required' => [
                'id', 'worker_uid', 'name', 'ip', 'status', 'model', 'api_key_id', 'api_version',
                'app_version', 'compatible', 'last_seen', 'created_at', 'updated_at',
            ],
            'example' => [
                'id' => 1,
                'worker_uid' => 'mac-mini-stt-01',
                'name' => 'Mac mini STT',
                'ip' => '192.0.2.10',
                'status' => 'online',
                'model' => 'Whisper Large v3',
                'api_key_id' => 7,
                'api_version' => 2,
                'app_version' => '2.5.0',
                'compatible' => true,
                'last_seen' => 1786512000,
                'created_at' => 1786500000,
                'updated_at' => 1786512000,
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
