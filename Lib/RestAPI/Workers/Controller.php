<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Workers;

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
    path: LocalSpeechToTextMain::API_BASE_PATH . '/workers',
    tags: ['Local Speech To Text workers'],
    description: 'module_local_speech_to_text_WorkersApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_workers', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: [
        'POST' => ['register'],
    ],
    resourceLevelMethods: [],
    collectionLevelMethods: ['register'],
    customMethods: ['register'],
    idPattern: '[0-9]+'
)]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    /**
     * @route POST /pbxcore/api/v3/module-local-speech-to-text/workers:register
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_RegisterWorker',
        description: 'module_local_speech_to_text_RegisterWorkerDesc',
        operationId: 'registerLocalSpeechToTextWorker'
    )]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('name')]
    #[ApiParameterRef('model')]
    #[ApiResponse(200, 'rest_response_200_ok')]
    #[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function register(): void
    {
        // Implementation handled by BaseRestController.
    }
}
