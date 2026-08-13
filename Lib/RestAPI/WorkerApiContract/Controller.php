<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerApiContract;

use MikoPBX\PBXCoreREST\Attributes\ApiDataSchema;
use MikoPBX\PBXCoreREST\Attributes\ApiOperation;
use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\ApiResponse;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

#[ApiResource(
    path: LocalSpeechToTextMain::API_BASE_PATH . '/worker-api-contract',
    tags: ['Local STT Workers'],
    description: 'Worker API compatibility contract',
    processor: Processor::class,
)]
#[ResourceSecurity(
    'module_local_speech_to_text_worker_api_contract',
    requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN],
)]
#[HttpMapping(
    mapping: ['GET' => ['getList']],
    resourceLevelMethods: [],
    collectionLevelMethods: ['getList'],
    customMethods: [],
    idPattern: '',
)]
final class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(
        schemaClass: DataStructure::class,
        type: 'custom',
        schemaName: 'LocalSTTWorkerApiContract',
    )]
    #[ApiOperation(
        summary: 'Get worker API compatibility contract',
        description: 'Returns the worker API version and supported resource capabilities',
        operationId: 'getLocalSTTWorkerApiContract',
    )]
    #[ApiResponse(200, 'rest_response_200_get', PBXApiResult::class)]
    #[ApiResponse(401, 'rest_response_401_unauthorized', PBXApiResult::class)]
    #[ApiResponse(403, 'rest_response_403_forbidden', PBXApiResult::class)]
    #[ApiResponse(500, 'rest_response_500_error', PBXApiResult::class)]
    public function getList(): void
    {
    }
}
