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

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-leases', tags: ['Local STT Jobs'], description: 'Worker job lease lifecycle', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_leases', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['POST' => ['create'], 'PATCH' => ['patch'], 'DELETE' => ['delete']], resourceLevelMethods: ['patch', 'delete'], collectionLevelMethods: ['create'], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobLease')]
    #[ApiOperation(summary: 'Claim the next STT job', description: 'Creates one processing lease or returns job null.', operationId: 'createLocalSTTJobLease')]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseJobClaimed')]
    public function create(): void {}

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobLeaseHeartbeat')]
    #[ApiOperation(summary: 'Heartbeat an STT job lease', description: 'Idempotently extends the current processing lease.', operationId: 'patchLocalSTTJobLease')]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseLeaseExtended')]
    public function patch(string $job_id): void {}

    #[ApiOperation(summary: 'Release an STT job lease', description: 'Returns the job to pending without consuming an attempt.', operationId: 'deleteLocalSTTJobLease')]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseJobReleased')]
    public function delete(string $job_id): void {}
}
