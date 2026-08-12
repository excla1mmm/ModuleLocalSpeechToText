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
    tags: ['Local Speech To Text'],
    description: 'module_local_speech_to_text_WorkersApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_workers', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: [
        'GET' => ['getList', 'getRecord', 'getProcessingSettings'],
        'POST' => ['create', 'register'],
        'PUT' => ['update'],
        'PATCH' => ['patch'],
        'DELETE' => ['delete'],
    ],
    resourceLevelMethods: ['getRecord', 'update', 'patch', 'delete'],
    collectionLevelMethods: ['getList', 'create', 'register', 'getProcessingSettings'],
    customMethods: ['register', 'getProcessingSettings'],
    idPattern: '[0-9]+'
)]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'list', isArray: true)]
    #[ApiOperation(
        summary: 'List STT workers',
        description: 'Returns registered STT workers with filters and pagination.',
        operationId: 'getLocalSTTWorkers'
    )]
    #[ApiParameterRef('status')]
    #[ApiParameterRef('worker_uid')]
    #[ApiParameterRef('api_version')]
    #[ApiParameterRef('app_version')]
    #[ApiParameterRef('limit')]
    #[ApiParameterRef('offset')]
    #[ApiResponse(200, 'rest_response_200_list')]
    #[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    public function getList(): void
    {
        // Implementation handled by BaseRestController.
    }

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTWorker')]
    #[ApiOperation(
        summary: 'Get an STT worker',
        description: 'Returns one STT worker by its numeric identifier.',
        operationId: 'getLocalSTTWorker'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiResponse(200, 'rest_response_200_get')]
    #[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    public function getRecord(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTWorker')]
    #[ApiOperation(
        summary: 'Register an STT worker',
        description: 'Idempotently creates or refreshes an STT worker by worker_uid.',
        operationId: 'createLocalSTTWorker'
    )]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('name')]
    #[ApiParameterRef('model')]
    #[ApiParameterRef('app_version')]
    #[ApiResponse(200, 'rest_response_200_created')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(409, 'rest_response_409_conflict', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function create(): void
    {
        // Implementation handled by BaseRestController.
    }

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTWorker')]
    #[ApiOperation(
        summary: 'Replace an STT worker',
        description: 'Replaces all administrator-mutable fields of an STT worker.',
        operationId: 'updateLocalSTTWorker'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('name', required: true)]
    #[ApiParameterRef('model', required: true)]
    #[ApiParameterRef('status', required: true)]
    #[ApiResponse(200, 'rest_response_200_updated')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function update(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTWorker')]
    #[ApiOperation(
        summary: 'Patch an STT worker',
        description: 'Updates selected administrator-mutable fields of an STT worker.',
        operationId: 'patchLocalSTTWorker'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('name')]
    #[ApiParameterRef('model')]
    #[ApiParameterRef('status')]
    #[ApiResponse(200, 'rest_response_200_patched')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function patch(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    #[ApiOperation(
        summary: 'Delete an STT worker',
        description: 'Deletes an inactive worker while retaining historical job and result rows.',
        operationId: 'deleteLocalSTTWorker'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiResponse(200, 'rest_response_200_deleted')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    #[ApiResponse(409, 'rest_response_409_conflict', 'PBXApiResult')]
    public function delete(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    /** @route POST /pbxcore/api/v3/module-local-speech-to-text/workers:register */
    public function register(): void
    {
        // Undocumented API v1 tombstone handled by Processor.
    }

    /** @route GET /pbxcore/api/v3/module-local-speech-to-text/workers:getProcessingSettings */
    public function getProcessingSettings(): void
    {
        // Undocumented API v1 tombstone handled by Processor.
    }
}
