<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;

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
    path: LocalSpeechToTextMain::API_BASE_PATH . '/jobs',
    tags: ['Local Speech To Text jobs'],
    description: 'module_local_speech_to_text_JobsApiDescription',
    processor: Processor::class
)]
#[ResourceSecurity('module_local_speech_to_text_jobs', requirements: [SecurityType::LOCALHOST, SecurityType::BEARER_TOKEN])]
#[HttpMapping(
    mapping: [
        'GET' => ['getNext', 'download'],
        'POST' => ['submitResult', 'submitError', 'heartbeat', 'release'],
    ],
    resourceLevelMethods: ['download', 'submitResult', 'submitError', 'heartbeat', 'release'],
    collectionLevelMethods: ['getNext'],
    customMethods: ['getNext', 'download', 'submitResult', 'submitError', 'heartbeat', 'release'],
    idPattern: '[0-9]+'
)]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;

    /**
     * @route GET /pbxcore/api/v3/module-local-speech-to-text/jobs:getNext
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_GetNextJob',
        description: 'module_local_speech_to_text_GetNextJobDesc',
        operationId: 'getNextLocalSpeechToTextJob'
    )]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiResponse(200, 'rest_response_200_ok')]
    #[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    public function getNext(): void
    {
        // Implementation handled by BaseRestController.
    }

    /**
     * @route GET /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:download
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_DownloadJobFile',
        description: 'module_local_speech_to_text_DownloadJobFileDesc',
        operationId: 'downloadLocalSpeechToTextJobFile'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'rest_response_200_ok')]
    #[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
    public function download(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    /**
     * @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:submitResult
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_SubmitResult',
        description: 'module_local_speech_to_text_SubmitResultDesc',
        operationId: 'submitLocalSpeechToTextResult'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiParameterRef('transcript', required: true)]
    #[ApiParameterRef('language')]
    #[ApiParameterRef('duration_ms')]
    #[ApiParameterRef('model_used')]
    #[ApiResponse(200, 'rest_response_200_ok')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(409, 'rest_response_409_conflict', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function submitResult(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    /**
     * @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:submitError
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_SubmitError',
        description: 'module_local_speech_to_text_SubmitErrorDesc',
        operationId: 'submitLocalSpeechToTextError'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiParameterRef('error_msg')]
    #[ApiResponse(200, 'rest_response_200_ok')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(409, 'rest_response_409_conflict', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function submitError(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    /**
     * @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:heartbeat
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_HeartbeatJob',
        description: 'module_local_speech_to_text_HeartbeatJobDesc',
        operationId: 'heartbeatLocalSpeechToTextJob'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'rest_response_200_ok')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(409, 'rest_response_409_conflict', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function heartbeat(string $id): void
    {
        // Implementation handled by BaseRestController.
    }

    /**
     * @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:release
     */
    #[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail')]
    #[ApiOperation(
        summary: 'module_local_speech_to_text_ReleaseJob',
        description: 'module_local_speech_to_text_ReleaseJobDesc',
        operationId: 'releaseLocalSpeechToTextJob'
    )]
    #[ApiParameterRef('id', required: true)]
    #[ApiParameterRef('worker_uid', required: true)]
    #[ApiParameterRef('lease_token', required: true)]
    #[ApiResponse(200, 'rest_response_200_ok')]
    #[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
    #[ApiResponse(409, 'rest_response_409_conflict', 'PBXApiResult')]
    #[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
    public function release(string $id): void
    {
        // Implementation handled by BaseRestController.
    }
}
