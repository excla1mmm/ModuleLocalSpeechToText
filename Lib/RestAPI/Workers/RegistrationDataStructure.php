<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

class RegistrationDataStructure extends DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaWorkerRegistration',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'module_local_speech_to_text_ApiFieldWorkerId'],
            ],
            'required' => ['id'],
            'example' => ['id' => 1],
        ];
    }
}
