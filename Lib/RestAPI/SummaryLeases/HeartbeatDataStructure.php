<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryLeases;

/**
 * PATCH /summary-leases/{id}: the extended lease. Request parameters are
 * inherited because Core resolves an operation's ApiParameterRef against the
 * class named in its ApiDataSchema.
 */
class HeartbeatDataStructure extends DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaSummaryLeaseHeartbeat',
            'required' => ['status', 'lease_expires_at'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['processing'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryLeaseStatus', 'example' => 'processing'],
                'lease_expires_at' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldSummaryLeaseExpiresAt', 'example' => 1784456400],
            ],
            'example' => ['status' => 'processing', 'lease_expires_at' => 1784456400],
        ];
    }
}
