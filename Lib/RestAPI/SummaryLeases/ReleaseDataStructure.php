<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryLeases;

/**
 * DELETE /summary-leases/{id}: the task is back in the queue and no attempt
 * was spent. Request parameters are inherited (see HeartbeatDataStructure).
 */
class ReleaseDataStructure extends DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaSummaryLeaseRelease',
            'required' => ['status'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryReleaseStatus', 'example' => 'pending'],
            ],
            'example' => ['status' => 'pending'],
        ];
    }
}
