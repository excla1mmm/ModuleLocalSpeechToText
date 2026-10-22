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
    #[ApiParameterRef('worker_platform', required: false)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseSummaryClaimed')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function create(): void {}

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTSummaryLeaseHeartbeat')]
    #[ApiOperation(summary: 'module_local_speech_to_text_HeartbeatSummary', description: 'module_local_speech_to_text_HeartbeatSummaryDesc', operationId: 'patchLocalSTTSummaryLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseLeaseExtended')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function patch(string $summary_id): void {}

    #[ApiOperation(summary: 'module_local_speech_to_text_ReleaseSummary', description: 'module_local_speech_to_text_ReleaseSummaryDesc', operationId: 'deleteLocalSTTSummaryLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseSummaryReleased')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function delete(string $summary_id): void {}
}
