<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallTranscripts;

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
    path: LocalSpeechToTextMain::API_BASE_PATH . '/call-transcripts',
    tags: ['Local STT Call Transcripts'],
    description: 'module_local_speech_to_text_CallTranscriptsApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_call_transcripts', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getRecord']], resourceLevelMethods: ['getRecord'], collectionLevelMethods: [], customMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTCallTranscript')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_GetCallTranscript',
        description: 'module_local_speech_to_text_GetCallTranscriptDesc',
        operationId: 'getLocalSpeechToTextCallTranscript'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('revision')]
    #[ApiResponse(200, 'rest_response_200_get', PBXApiResult::class)]
    #[ApiResponse(401, 'rest_response_401_unauthorized', PBXApiResult::class)]
    #[ApiResponse(403, 'rest_response_403_forbidden', PBXApiResult::class)]
    #[ApiResponse(404, 'rest_response_404_not_found', PBXApiResult::class)]
    #[ApiResponse(500, 'rest_response_500_error', PBXApiResult::class)]
    public function getRecord(string $id): void
    {
    }
}
