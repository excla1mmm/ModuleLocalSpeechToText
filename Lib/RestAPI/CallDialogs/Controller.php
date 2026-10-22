<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\CallDialogs;

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

/**
 * One call for third-party services, looked up by the PBX call id: the latest
 * published revision, turns signed with the speaker role, and the summary.
 */
#[ApiResource(
    path: LocalSpeechToTextMain::API_BASE_PATH . '/call-dialogs',
    tags: ['Local STT Call Transcripts'],
    description: 'module_local_speech_to_text_CallDialogsApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_call_dialogs', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getRecord']], resourceLevelMethods: ['getRecord'], collectionLevelMethods: [], customMethods: [], idPattern: '[A-Za-z0-9][A-Za-z0-9._-]{0,254}')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTCallDialog')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_GetCallDialog',
        description: 'module_local_speech_to_text_GetCallDialogDesc',
        operationId: 'getLocalSpeechToTextCallDialog'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiResponse(200, 'rest_response_200_get', PBXApiResult::class)]
    #[ApiResponse(401, 'rest_response_401_unauthorized', PBXApiResult::class)]
    #[ApiResponse(403, 'rest_response_403_forbidden', PBXApiResult::class)]
    #[ApiResponse(404, 'rest_response_404_not_found', PBXApiResult::class)]
    #[ApiResponse(500, 'rest_response_500_error', PBXApiResult::class)]
    public function getRecord(string $id): void
    {
    }
}
