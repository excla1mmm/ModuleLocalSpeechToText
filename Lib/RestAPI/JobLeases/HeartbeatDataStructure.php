<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases;

/**
 * PATCH /job-leases/{id}: the job stays processing with a new lease expiry.
 */
class HeartbeatDataStructure extends DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaLeaseHeartbeat',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['processing'], 'description' => 'module_local_speech_to_text_ApiFieldJobStatus'],
                'lease_expires_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldLeaseExpiresAt'],
            ],
            'required' => ['status', 'lease_expires_at'],
            'example' => ['status' => 'processing', 'lease_expires_at' => 1786513800],
        ];
    }
}
