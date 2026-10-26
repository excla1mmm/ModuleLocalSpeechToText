<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

/**
 * Parameters shared by the administrative job queue (/jobs) and the worker
 * job resources (/job-leases, /job-results, /job-failures, /job-recordings),
 * plus the administrative job record schema.
 */
class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public const array JOB_STATUSES = ['pending', 'processing', 'done', 'error'];

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
                'X-MikoPBX-Worker-UID' => [
                    'type' => 'string',
                    'in' => 'header',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'example' => 'mac-mini-stt-01',
                ],
                'X-MikoPBX-Job-Lease-Token' => [
                    'type' => 'string',
                    'in' => 'header',
                    'description' => 'module_local_speech_to_text_ApiParamLeaseToken',
                    'example' => 'lease-token',
                ],
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'module_local_speech_to_text_ApiParamJobId',
                    'example' => 42,
                ],
                'worker_uid' => [
                    'type' => 'string',
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$',
                    'maxLength' => 255,
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'example' => 'mac-mini-stt-01',
                ],
                'lease_token' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamLeaseToken',
                    'example' => 'lease-token',
                ],
                'call_id' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'module_local_speech_to_text_ApiFieldCallId',
                    'example' => '1786512000.42',
                ],
                'file_path' => [
                    'type' => 'string',
                    'maxLength' => 4096,
                    'description' => 'module_local_speech_to_text_ApiParamJobFilePath',
                    'example' => '/storage/usbdisk1/mikopbx/astspool/monitor/2026/10/26/10/mikopbx-1786512000.42_out.mp3',
                ],
                'priority' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'default' => 5,
                    'description' => 'module_local_speech_to_text_ApiParamJobPriority',
                    'example' => 5,
                ],
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'module_local_speech_to_text_ApiParamJobIds',
                    'example' => [41, 42],
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['retry', 'reset'],
                    'description' => 'module_local_speech_to_text_ApiParamJobAction',
                    'example' => 'retry',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => self::JOB_STATUSES,
                    'description' => 'module_local_speech_to_text_ApiParamJobStatus',
                    'example' => 'error',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
                    'description' => 'module_local_speech_to_text_ApiParamLimit',
                    'example' => 50,
                ],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'module_local_speech_to_text_ApiParamOffset',
                    'example' => 0,
                ],
                'worker_platform' => [
                    'type' => 'string',
                    'enum' => ['macos', 'linux', 'windows'],
                    'default' => 'macos',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerPlatform',
                    'example' => 'linux',
                ],
                'worker_capabilities' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'],
                    'maxItems' => 32,
                    'description' => 'module_local_speech_to_text_ApiParamLeaseWorkerCapabilities',
                    'example' => ['call-summaries'],
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
        $example = [
            'id' => 42,
            'call_id' => '1786512000.42',
            'status' => 'error',
            'priority' => 5,
            'worker_id' => null,
            'attempts' => 3,
            'created_at' => 1786512000,
            'updated_at' => 1786512600,
            'started_at' => null,
            'finished_at' => 1786512600,
            'error_msg' => 'Worker failed to process audio',
            'terminal_error' => false,
        ];

        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaJob',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiParamJobId'],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId'],
                'status' => ['type' => 'string', 'enum' => self::JOB_STATUSES, 'description' => 'module_local_speech_to_text_ApiFieldJobStatus'],
                'priority' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'module_local_speech_to_text_ApiParamJobPriority'],
                'worker_id' => ['type' => ['integer', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldJobWorkerId'],
                'attempts' => ['type' => 'integer', 'minimum' => 0, 'description' => 'module_local_speech_to_text_ApiFieldJobAttempts'],
                'created_at' => ['type' => 'integer'],
                'updated_at' => ['type' => 'integer'],
                'started_at' => ['type' => ['integer', 'null']],
                'finished_at' => ['type' => ['integer', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldFinishedAt'],
                'error_msg' => ['type' => ['string', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldJobErrorMsg'],
                'terminal_error' => ['type' => 'boolean', 'description' => 'module_local_speech_to_text_ApiFieldJobTerminalError'],
            ],
            'required' => array_keys($example),
            'example' => $example,
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
