<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryLeases;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

/**
 * Summary task lease: claim (POST), heartbeat (PATCH) and release (DELETE).
 *
 * Standalone on purpose: the summary routes document their own parameters
 * and must not follow changes to the job resource definitions. Values are
 * literals so the file loads without the module autoloader.
 */
class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public const string EXAMPLE_LEASE_TOKEN = '9f86d081884c7d659a2feaa0c55ad015';

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
                    'example' => self::EXAMPLE_LEASE_TOKEN,
                ],
            ],
            'response' => [],
        ];
    }

    public static function getListItemSchema(): array
    {
        return static::getDetailSchema();
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaSummaryLease',
            'required' => ['summary', 'has_settings_changed'],
            'properties' => [
                'summary' => [
                    'type' => ['object', 'null'],
                    'description' => 'module_local_speech_to_text_ApiFieldSummaryTask',
                    'required' => ['id', 'call_id', 'aggregate_id', 'revision', 'result_id', 'lease_token', 'lease_expires_at', 'model_id', 'request'],
                    'properties' => [
                        'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'module_local_speech_to_text_ApiFieldSummaryId', 'example' => 15],
                        'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldSummaryCallId', 'example' => '1784455200.42'],
                        'aggregate_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldAggregateId', 'example' => 77],
                        'revision' => ['type' => 'integer', 'minimum' => 1, 'description' => 'module_local_speech_to_text_ApiFieldSummaryRevision', 'example' => 2],
                        'result_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'module_local_speech_to_text_ApiFieldSummaryResultId', 'example' => 42],
                        'lease_token' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'module_local_speech_to_text_ApiFieldSummaryLeaseToken', 'example' => self::EXAMPLE_LEASE_TOKEN],
                        'lease_expires_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldSummaryLeaseExpiresAt', 'example' => 1784456400],
                        'model_id' => ['type' => 'string', 'enum' => ['gemma-3-4b-it-Q4_K_M'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryModelId', 'example' => 'gemma-3-4b-it-Q4_K_M'],
                        'request' => self::requestSchema(),
                    ],
                ],
                'has_settings_changed' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldSummarySettingsChanged', 'example' => false],
            ],
            'example' => [
                'summary' => [
                    'id' => 15,
                    'call_id' => '1784455200.42',
                    'aggregate_id' => 77,
                    'revision' => 2,
                    'result_id' => 42,
                    'lease_token' => self::EXAMPLE_LEASE_TOKEN,
                    'lease_expires_at' => 1784456400,
                    'model_id' => 'gemma-3-4b-it-Q4_K_M',
                    'request' => [
                        'messages' => [
                            ['role' => 'system', 'content' => 'Ты анализируешь расшифровки телефонных разговоров компании. …'],
                            ['role' => 'user', 'content' => "Расшифровка разговора:\n\nКлиент: Здравствуйте, хочу узнать статус заказа.\n…"],
                        ],
                        'temperature' => 0,
                        'max_tokens' => 300,
                        'json_schema' => [
                            'type' => 'object',
                            'properties' => ['topic' => ['type' => 'string'], 'summary' => ['type' => 'string']],
                            'required' => ['topic', 'summary'],
                        ],
                    ],
                ],
                'has_settings_changed' => false,
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }

    /** @return array<string, mixed> SummaryPromptBuilder::build() */
    private static function requestSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiFieldSummaryRequest',
            'required' => ['messages', 'temperature', 'max_tokens', 'json_schema'],
            'properties' => [
                'messages' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 2,
                    'description' => 'module_local_speech_to_text_ApiFieldSummaryRequestMessages',
                    'items' => [
                        'type' => 'object',
                        'required' => ['role', 'content'],
                        'properties' => [
                            'role' => ['type' => 'string', 'enum' => ['system', 'user']],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                ],
                'temperature' => ['type' => 'number', 'enum' => [0], 'example' => 0],
                'max_tokens' => ['type' => 'integer', 'enum' => [300], 'example' => 300],
                'json_schema' => [
                    'type' => 'object',
                    'description' => 'module_local_speech_to_text_ApiFieldSummaryRequestJsonSchema',
                    'additionalProperties' => true,
                ],
            ],
        ];
    }
}
