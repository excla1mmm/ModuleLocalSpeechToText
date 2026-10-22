<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryFailures;

class DataStructure extends \Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs\DataStructure
{
    public static function getDetailSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'done', 'error']],
            ],
        ];
    }
}
