<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

/**
 * Response of PATCH /jobs: the jobs returned to the queue and the action applied.
 */
class BulkActionDataStructure extends DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaJobBulkAction',
            'properties' => [
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'module_local_speech_to_text_ApiParamJobIds',
                ],
                'action' => [
                    'type' => 'string',
                    'enum' => ['retry', 'reset'],
                    'description' => 'module_local_speech_to_text_ApiParamJobAction',
                ],
            ],
            'required' => ['ids', 'action'],
            'example' => ['ids' => [41, 42], 'action' => 'retry'],
        ];
    }
}
