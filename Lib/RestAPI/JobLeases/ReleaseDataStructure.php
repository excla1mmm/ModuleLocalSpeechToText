<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases;

/**
 * DELETE /job-leases/{id}: the released job is pending again.
 */
class ReleaseDataStructure extends DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaLeaseRelease',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending'], 'description' => 'module_local_speech_to_text_ApiFieldJobStatus'],
            ],
            'required' => ['status'],
            'example' => ['status' => 'pending'],
        ];
    }
}
