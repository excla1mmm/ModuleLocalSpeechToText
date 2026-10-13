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
    tags: ['Local STT Workers'],
    description: 'module_local_speech_to_text_WorkersApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_workers', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: [
        'GET' => ['getList', 'getRecord'],
        'POST' => ['create'],
        'PUT' => ['update'],
        'PATCH' => ['patch'],
        'DELETE' => ['delete'],
    ],
    resourceLevelMethods: ['getRecord', 'update', 'patch', 'delete'],
    collectionLevelMethods: ['getList', 'create'],
    idPattern: '[0-9]+'
)]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'list', isArray: true)]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_ListWorkers',
        description: 'module_local_speech_to_text_ListWorkersDesc',
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
        summary: 'module_local_speech_to_text_GetWorker',
        description: 'module_local_speech_to_text_GetWorkerDesc',
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

    #[ApiDataSchema(schemaClass: RegistrationDataStructure::class, type: 'detail', schemaName: 'LocalSTTWorkerRegistration')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_RegisterWorker',
        description: 'module_local_speech_to_text_RegisterWorkerDesc',
        operationId: 'createLocalSTTWorker'
    )]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('name')]
    #[ApiParameterRef('model')]
    #[ApiParameterRef('app_version')]
    #[ApiParameterRef('platform')]
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
        summary: 'module_local_speech_to_text_UpdateWorker',
        description: 'module_local_speech_to_text_UpdateWorkerDesc',
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
        summary: 'module_local_speech_to_text_PatchWorker',
        description: 'module_local_speech_to_text_PatchWorkerDesc',
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
        summary: 'module_local_speech_to_text_DeleteWorker',
        description: 'module_local_speech_to_text_DeleteWorkerDesc',
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
}
