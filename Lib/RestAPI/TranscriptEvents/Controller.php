<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\TranscriptEvents;

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
    path: LocalSpeechToTextMain::API_BASE_PATH . '/transcripts/events',
    tags: ['Local Speech To Text transcript events'],
    description: 'Completed transcript event cursor API',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_transcript_events', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: [
        'GET' => ['getList'],
    ],
    resourceLevelMethods: [],
    collectionLevelMethods: ['getList'],
    customMethods: [],
    idPattern: ''
)]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    /**
     * @route GET /pbxcore/api/v3/module-local-speech-to-text/transcripts/events
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'list')]
    #[ApiOperation(
        summary: 'List completed transcript events',
        description: 'Returns idempotent transcript.completed events after a cursor.',
        operationId: 'listLocalSpeechToTextTranscriptEvents'
    )]
    #[ApiParameterRef('cursor')]
    #[ApiParameterRef('limit')]
    #[ApiResponse(200, 'rest_response_200_list')]
    #[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    public function getList(): void
    {
        // Implementation handled by BaseRestController.
    }
}
