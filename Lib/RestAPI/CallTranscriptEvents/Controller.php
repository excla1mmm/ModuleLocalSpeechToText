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
    tags: ['Local Speech To Text call transcript events'],
    description: 'Revisioned logical-call transcript event cursor API',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_call_transcript_events', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getList']], resourceLevelMethods: [], collectionLevelMethods: ['getList'], customMethods: [], idPattern: '')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'list')]
    #[ApiOperation(summary: 'List call transcript revision events', description: 'Returns idempotent call-level events.', operationId: 'listLocalSpeechToTextCallTranscriptEvents')]
    #[ApiParameterRef('cursor')]
    #[ApiParameterRef('limit')]
    #[ApiResponse(200, 'rest_response_200_list', PBXApiResult::class)]
    public function getList(): void
    {
    }
}
