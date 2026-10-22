<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallDialogs;

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
            'description' => 'module_local_speech_to_text_ApiSchemaCallDialog',
            'required' => ['source_instance_id', 'contract_version', 'call_id', 'aggregate_id', 'revision', 'turns', 'text'],
            'properties' => [
                'source_instance_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'description' => 'module_local_speech_to_text_ApiFieldSourceInstanceId', 'example' => '0123456789abcdef0123456789abcdef'],
                'contract_version' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldContractVersion', 'example' => 3],
                'call_id' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldCallId', 'example' => 'mikopbx-1784455200.42_a1b2c3'],
                'aggregate_id' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldAggregateId', 'example' => 77],
                'revision' => ['type' => 'integer', 'description' => 'module_local_speech_to_text_ApiFieldDialogRevision', 'example' => 1],
                'completeness_status' => ['type' => 'string', 'enum' => ['complete', 'legacy_unresolved'], 'description' => 'module_local_speech_to_text_ApiFieldCompletenessStatus', 'example' => 'complete'],
                'language' => ['type' => ['string', 'null'], 'pattern' => '^[a-z]{2,3}$', 'description' => 'module_local_speech_to_text_ApiFieldCallTranscriptLanguage', 'example' => 'ru'],
                'direction' => ['type' => ['string', 'null'], 'enum' => ['incoming', 'outgoing', 'internal', null], 'description' => 'module_local_speech_to_text_ApiFieldDialogDirection', 'example' => 'incoming'],
                'turns' => [
                    'type' => 'array',
                    'description' => 'module_local_speech_to_text_ApiFieldDialogTurns',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'start_ms' => ['type' => ['integer', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldStartMs', 'example' => 0],
                            'end_ms' => ['type' => ['integer', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldEndMs', 'example' => 4210],
                            'channel' => ['type' => ['string', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldChannel', 'example' => 'left'],
                            'speaker' => ['type' => ['string', 'null'], 'enum' => ['client', 'employee', null], 'description' => 'module_local_speech_to_text_ApiFieldDialogSpeaker', 'example' => 'employee'],
                            'speaker_name' => ['type' => ['string', 'null'], 'description' => 'module_local_speech_to_text_ApiFieldDialogSpeakerName', 'example' => 'Иван Петров'],
                            'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldText', 'example' => 'Здравствуйте, чем я могу помочь?'],
                        ],
                    ],
                ],
                'text' => ['type' => 'string', 'description' => 'module_local_speech_to_text_ApiFieldDialogText', 'example' => 'Иван Петров: Здравствуйте, чем я могу помочь?'],
            ],
            'example' => [
                'source_instance_id' => '0123456789abcdef0123456789abcdef',
                'contract_version' => 3,
                'call_id' => 'mikopbx-1784455200.42_a1b2c3',
                'aggregate_id' => 77,
                'revision' => 1,
                'completeness_status' => 'complete',
                'language' => 'ru',
                'direction' => 'incoming',
                'turns' => [
                    ['start_ms' => 0, 'end_ms' => 4210, 'channel' => 'left', 'speaker' => 'employee', 'speaker_name' => 'Иван Петров', 'text' => 'Здравствуйте, чем я могу помочь?'],
                    ['start_ms' => 4500, 'end_ms' => 9100, 'channel' => 'right', 'speaker' => 'client', 'speaker_name' => null, 'text' => 'Хочу узнать статус заказа.'],
                ],
                'text' => "Иван Петров: Здравствуйте, чем я могу помочь?\nКлиент: Хочу узнать статус заказа.",
            ],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
