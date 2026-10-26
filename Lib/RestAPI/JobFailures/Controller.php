<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobFailures;

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

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-failures', tags: ['Local STT Jobs'], description: 'module_local_speech_to_text_JobFailuresApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_failures', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['PUT' => ['update']], resourceLevelMethods: ['update'], collectionLevelMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobFailure')]
    #[ApiOperation(summary: 'module_local_speech_to_text_SubmitError', description: 'module_local_speech_to_text_SubmitErrorDesc', operationId: 'updateLocalSTTJobFailure')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiParameterRef('error_msg')]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseErrorAccepted')]
    #[ApiResponse(403, 'module_local_speech_to_text_ApiResponseWorkerForbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'module_local_speech_to_text_ApiResponseJobNotFound', 'PBXApiResult')]
    #[ApiResponse(409, 'module_local_speech_to_text_ApiResponseLeaseConflict', 'PBXApiResult')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    #[ApiResponse(500, 'rest_response_500_error', 'PBXApiResult')]
    #[ApiResponse(503, 'module_local_speech_to_text_ApiResponseServiceUnavailable', 'PBXApiResult')]
    public function update(string $job_id): void
    {
    }
}
