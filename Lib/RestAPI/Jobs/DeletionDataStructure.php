<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

/**
 * DELETE /jobs and DELETE /jobs/{id}: the status filter of a bulk delete
 * accepts a comma-separated subset of pending and error, and the response
 * lists the deleted job identifiers.
 */
class DeletionDataStructure extends DataStructure
{
    public static function getParameterDefinitions(): array
    {
        $definitions = parent::getParameterDefinitions();
        $definitions['request']['status'] = [
            'type' => 'string',
            'pattern' => '^(pending|error)(,(pending|error))?$',
            'description' => 'module_local_speech_to_text_ApiParamJobDeleteStatus',
            'example' => 'pending,error',
        ];

        return $definitions;
    }

    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaJobDeletion',
            'properties' => [
                'ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'module_local_speech_to_text_ApiFieldDeletedJobIds',
                ],
            ],
            'required' => ['ids'],
            'example' => ['ids' => [41, 42]],
        ];
    }
}
