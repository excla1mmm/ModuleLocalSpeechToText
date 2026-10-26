<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerProcessingSettings;

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

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/worker-processing-settings', tags: ['Local STT Workers'], description: 'module_local_speech_to_text_WorkerProcessingSettingsApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_worker_processing_settings', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getList']], collectionLevelMethods: ['getList'])]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTWorkerProcessingSettings')]
    #[ApiOperation(summary: 'module_local_speech_to_text_GetProcessingSettings', description: 'module_local_speech_to_text_GetProcessingSettingsDesc', operationId: 'getLocalSTTWorkerProcessingSettings')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('X-MikoPBX-Worker-UID', required: true)]
    #[ApiResponse(200, 'rest_response_200_get')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(409, 'module_local_speech_to_text_ApiResponseWorkerKeyAmbiguous', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    #[ApiResponse(500, 'rest_response_500_error', 'PBXApiResult')]
    #[ApiResponse(503, 'module_local_speech_to_text_ApiResponseServiceUnavailable', 'PBXApiResult')]
    public function getList(): void {}
}
