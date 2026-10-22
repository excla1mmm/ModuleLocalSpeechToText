<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscriptEvents;

use MikoPBX\PBXCoreREST\Attributes\ApiDataSchema;
use MikoPBX\PBXCoreREST\Attributes\ApiOperation;
use MikoPBX\PBXCoreREST\Attributes\ApiParameterRef;
use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\ApiResponse;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

#[ApiResource(
    path: LocalSpeechToTextMain::API_BASE_PATH . '/call-transcripts/events',
    tags: ['Local STT Call Transcripts'],
    description: 'module_local_speech_to_text_CallTranscriptEventsApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_call_transcript_events', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getList']], resourceLevelMethods: [], collectionLevelMethods: ['getList'], customMethods: [], idPattern: '')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'custom', schemaName: 'LocalSTTCallTranscriptEventListResponse')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_ListCallTranscriptEvents',
        description: 'module_local_speech_to_text_ListCallTranscriptEventsDesc',
        operationId: 'listLocalSpeechToTextCallTranscriptEvents'
    )]
    #[ApiParameterRef('cursor')]
    #[ApiParameterRef('limit')]
    #[ApiParameterRef('include_deleted')]
    #[ApiParameterRef('include_summaries')]
    #[ApiResponse(200, 'rest_response_200_list', PBXApiResult::class)]
    #[ApiResponse(401, 'rest_response_401_unauthorized', PBXApiResult::class)]
    #[ApiResponse(403, 'rest_response_403_forbidden', PBXApiResult::class)]
    #[ApiResponse(500, 'rest_response_500_error', PBXApiResult::class)]
    public function getList(): void
    {
    }
}
