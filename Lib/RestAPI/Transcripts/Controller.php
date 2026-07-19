<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Transcripts;

use MikoPBX\PBXCoreREST\Attributes\ApiDataSchema;
use MikoPBX\PBXCoreREST\Attributes\ApiOperation;
use MikoPBX\PBXCoreREST\Attributes\ApiParameterRef;
use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\ApiResponse;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

#[ApiResource(
    path: LocalSpeechToTextMain::API_BASE_PATH . '/transcripts',
    tags: ['Local Speech To Text'],
    description: 'module_local_speech_to_text_TranscriptsApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_transcripts', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: [
        'GET' => ['getList', 'getRecord'],
    ],
    resourceLevelMethods: ['getRecord'],
    collectionLevelMethods: ['getList'],
    customMethods: [],
    idPattern: '[0-9]+'
)]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    /**
     * @route GET /pbxcore/api/v3/module-local-speech-to-text/transcripts
     */
    #[ApiDataSchema(schemaClass: ListDataStructure::class, type: 'custom', schemaName: 'LocalSTTTranscriptListResponse')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_ListTranscripts',
        description: 'module_local_speech_to_text_ListTranscriptsDesc',
        operationId: 'listLocalSpeechToTextTranscripts'
    )]
    #[ApiParameterRef('limit')]
    #[ApiParameterRef('offset')]
    #[ApiParameterRef('date_from')]
    #[ApiParameterRef('date_to')]
    #[ApiResponse(200, 'rest_response_200_list')]
    #[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(500, 'rest_response_500_error', 'PBXApiResult')]
    public function getList(): void
    {
        // Implementation handled by BaseRestController.
    }

    /**
     * @route GET /pbxcore/api/v3/module-local-speech-to-text/transcripts/{id}
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTTranscript')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_GetTranscript',
        description: 'module_local_speech_to_text_GetTranscriptDesc',
        operationId: 'getLocalSpeechToTextTranscript'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiResponse(200, 'rest_response_200_get')]
    #[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    #[ApiResponse(500, 'rest_response_500_error', 'PBXApiResult')]
    public function getRecord(string $id): void
    {
        // Implementation handled by BaseRestController.
    }
}
