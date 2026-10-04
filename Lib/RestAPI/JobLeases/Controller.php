<?php
declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases;

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

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-leases', tags: ['Local STT Jobs'], description: 'module_local_speech_to_text_JobLeasesApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_leases', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['POST' => ['create'], 'PATCH' => ['patch'], 'DELETE' => ['delete']], resourceLevelMethods: ['patch', 'delete'], collectionLevelMethods: ['create'], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobLease')]
    #[ApiOperation(summary: 'module_local_speech_to_text_GetNextJob', description: 'module_local_speech_to_text_GetNextJobDesc', operationId: 'createLocalSTTJobLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('worker_platform', required: false)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseJobClaimed')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function create(): void {}

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobLeaseHeartbeat')]
    #[ApiOperation(summary: 'module_local_speech_to_text_HeartbeatJob', description: 'module_local_speech_to_text_HeartbeatJobDesc', operationId: 'patchLocalSTTJobLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseLeaseExtended')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function patch(string $job_id): void {}

    #[ApiOperation(summary: 'module_local_speech_to_text_ReleaseJob', description: 'module_local_speech_to_text_ReleaseJobDesc', operationId: 'deleteLocalSTTJobLease')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseJobReleased')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function delete(string $job_id): void {}
}
