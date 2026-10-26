<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryResults;

use MikoPBX\PBXCoreREST\Lib\Common\AbstractDataStructure;
use MikoPBX\PBXCoreREST\Lib\Common\OpenApiSchemaProvider;

/**
 * PUT /summary-results/{id}: the raw model answer for a leased summary task.
 *
 * Standalone and literal-only so it loads without the module autoloader.
 * The limits mirror SummaryContentParser and CallSummaryService.
 */
class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider
{
    public static function getParameterDefinitions(): array
    {
        return [
            'request' => [
                'X-MikoPBX-Worker-API-Version' => [
                    'type' => 'integer',
                    'in' => 'header',
                    'description' => 'module_local_speech_to_text_ApiVersion',
                    'enum' => [2],
                    'example' => 2,
                ],
                'id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'module_local_speech_to_text_ApiParamSummaryId',
                    'example' => 15,
                ],
                'worker_uid' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamWorkerUid',
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$',
                    'maxLength' => 255,
                    'example' => 'mac-mini-stt-01',
                ],
                'lease_token' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamSummaryLeaseToken',
                    'example' => '9f86d081884c7d659a2feaa0c55ad015',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamSummaryContent',
                    'example' => '{"topic": "Статус заказа", "summary": "Клиент уточнил статус заказа; сотрудник обещал перезвонить."}',
                ],
                'model_used' => [
                    'type' => 'string',
                    'description' => 'module_local_speech_to_text_ApiParamSummaryModelUsed',
                    'example' => 'gemma-3-4b-it-Q4_K_M',
                ],
                'duration_ms' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'module_local_speech_to_text_ApiParamSummaryDurationMs',
                    'example' => 8400,
                ],
                'prompt_tokens' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'module_local_speech_to_text_ApiParamSummaryPromptTokens',
                    'example' => 1830,
                ],
                'completion_tokens' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'module_local_speech_to_text_ApiParamSummaryCompletionTokens',
                    'example' => 96,
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
            'description' => 'module_local_speech_to_text_ApiSchemaSummaryResult',
            'required' => ['status'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['done', 'pending', 'error'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryResultStatus', 'example' => 'done'],
                'error_code' => ['type' => 'string', 'enum' => ['summary_content_invalid'], 'description' => 'module_local_speech_to_text_ApiFieldSummaryResultErrorCode', 'example' => 'summary_content_invalid'],
            ],
            'example' => ['status' => 'done'],
        ];
    }

    public static function getRelatedSchemas(): array
    {
        return [];
    }
}
