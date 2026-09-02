<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

class RegistrationDataStructure extends DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['id'],
            'example' => ['id' => 1],
        ];
    }
}
