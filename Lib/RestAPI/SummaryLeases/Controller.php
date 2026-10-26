<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\SummaryLeases;

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

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/summary-leases', tags: ['Local STT Summaries'], description: 'module_local_speech_to_text_SummaryLeasesApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_summary_leases', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['POST' => ['create'], 'PATCH' => ['patch'], 'DELETE' => ['delete']], resourceLevelMethods: ['patch', 'delete'], collectionLevelMethods: ['create'], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTSummaryLease')]
    #[ApiOperation(summary: 'module_local_speech_to_text_ClaimSummary', description: 'module_local_speech_to_text_ClaimSummaryDesc', operationId: 'createLocalSTTSummaryLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseSummaryClaimed')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(409, 'rest_response_409_conflict', 'PBXApiResult')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    #[ApiResponse(500, 'rest_response_500_error', 'PBXApiResult')]
    #[ApiResponse(503, 'module_local_speech_to_text_ApiResponseWorkerRetryLater', 'PBXApiResult')]
    public function create(): void {}

    #[ApiDataSchema(schemaClass: HeartbeatDataStructure::class, type: 'detail', schemaName: 'LocalSTTSummaryLeaseHeartbeat')]
    #[ApiOperation(summary: 'module_local_speech_to_text_HeartbeatSummary', description: 'module_local_speech_to_text_HeartbeatSummaryDesc', operationId: 'patchLocalSTTSummaryLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseSummaryLeaseExtended')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    #[ApiResponse(409, 'module_local_speech_to_text_ApiResponseSummaryLeaseConflict', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    #[ApiResponse(500, 'rest_response_500_error', 'PBXApiResult')]
    #[ApiResponse(503, 'module_local_speech_to_text_ApiResponseWorkerRetryLater', 'PBXApiResult')]
    public function patch(string $summary_id): void {}

    #[ApiDataSchema(schemaClass: ReleaseDataStructure::class, type: 'detail', schemaName: 'LocalSTTSummaryLeaseRelease')]
    #[ApiOperation(summary: 'module_local_speech_to_text_ReleaseSummary', description: 'module_local_speech_to_text_ReleaseSummaryDesc', operationId: 'deleteLocalSTTSummaryLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseSummaryReleased')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    #[ApiResponse(409, 'module_local_speech_to_text_ApiResponseSummaryLeaseConflict', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    #[ApiResponse(500, 'rest_response_500_error', 'PBXApiResult')]
    #[ApiResponse(503, 'module_local_speech_to_text_ApiResponseWorkerRetryLater', 'PBXApiResult')]
    public function delete(string $summary_id): void {}
}
