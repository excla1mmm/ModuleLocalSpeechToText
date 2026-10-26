<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallSummaries;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'id' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamDialogCallId',
                    'example' => 'mikopbx-1784455200.42_a1b2c3',
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$',
                    'required' => true,
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
        return [
            'type' => 'object',
            'description' => 'module_local_speech_to_text_ApiSchemaCallSummary',
            'required' => ['source_instance_id', 'contract_version', 'call_id', 'aggregate_id', 'revision', 'status', 'topic', 'summary', 'model', 'finished_at'],
            'properties' => [
                'source_instance_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'module_local_speech_to_text_ApiFieldSourceInstanceId', 'example' => '0123456789abcdef0123456789abcdef'],
                'contract_version' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldContractVersion', 'example' => 3],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => 'mikopbx-1784455200.42_a1b2c3'],
                'aggregate_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldAggregateId', 'example' => 77],
                'revision' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldDialogRevision', 'example' => 1],
            ] + self::summaryProperties(),
            'example' => [
                'source_instance_id' => '0123456789abcdef0123456789abcdef',
                'contract_version' => 3,
                'call_id' => 'mikopbx-1784455200.42_a1b2c3',
                'aggregate_id' => 77,
                'revision' => 1,
            ] + self::summaryExample(),
        ];
    }

    /** @return array<string, mixed> */
    private static function summaryProperties(): array
    {
        return [
            'status' => ['type' => 'string', 'enum' => ['done', 'pending', 'error', 'skipped', 'unavailable'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryStatus', 'example' => 'done'],
            'topic' => ['type' => ['string', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryTopic', 'example' => 'Статус заказа'],
            'summary' => ['type' => ['string', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryText', 'example' => 'Клиент уточнил статус заказа; сотрудник обещал перезвонить.'],
            'model' => ['type' => ['string', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryModel', 'example' => 'gemma-3-4b-it-Q4_K_M'],
            'finished_at' => ['type' => ['integer', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryFinishedAt', 'example' => 1784455900],
        ];
    }

    /** @return array<string, mixed> */
    private static function summaryExample(): array
    {
        return [
            'status' => 'done',
            'topic' => 'Статус заказа',
            'summary' => 'Клиент уточнил статус заказа; сотрудник обещал перезвонить.',
            'model' => 'gemma-3-4b-it-Q4_K_M',
            'finished_at' => 1784455900,
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
