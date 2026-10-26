<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryFailures;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

/**
 * PUT /summary-failures/{id}: a failed attempt reported by the worker. Every
 * call spends an attempt; the third one ends the task with an error.
 *
 * Standalone and literal-only so it loads without the module autoloader.
 */
class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'X-MikoPBX-Worker-API-Version' => [
                    'type' => 'integer',
                    'in' => 'header',
                    'description' => 'module_local_speech_to_text_ApiVersion',
                    'enum' => [2],
                    'example' => 2,
                ],
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'module_local_speech_to_text_ApiParamSummaryId',
                    'example' => 15,
                ],
                'worker_uid' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$',
                    'maxLength' => 255,
                    'example' => 'mac-mini-stt-01',
                ],
                'lease_token' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamSummaryLeaseToken',
                    'example' => '9f86d081884c7d659a2feaa0c55ad015',
                ],
                'error_msg' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamSummaryErrorMsg',
                    'example' => 'llama-server returned HTTP 503',
                ],
            ],
            'response' => [],
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
            'description' => 'module_local_speech_to_text_ApiSchemaSummaryFailure',
            'required' => ['status', 'attempts'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'error'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryFailureStatus', 'example' => 'pending'],
                'attempts' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3, 'description' => 'module_local_speech_to_text_ApiFieldSummaryAttempts', 'example' => 1],
            ],
            'example' => ['status' => 'pending', 'attempts' => 1],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
