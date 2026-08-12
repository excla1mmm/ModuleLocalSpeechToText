<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobRecordings;

use MikoPBX\PBXCoreREST\Attributes\ApiOperation;
use MikoPBX\PBXCoreREST\Attributes\ApiParameterRef;
use MikoPBX\PBXCoreREST\Attributes\ApiResource;
use MikoPBX\PBXCoreREST\Attributes\ApiResponse;
use MikoPBX\PBXCoreREST\Attributes\HttpMapping;
use MikoPBX\PBXCoreREST\Attributes\ResourceSecurity;
use MikoPBX\PBXCoreREST\Attributes\SecurityType;
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-recordings', tags: ['Local STT Recordings'], description: 'Worker-owned STT recording', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_recordings', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getRecord']], resourceLevelMethods: ['getRecord'], collectionLevelMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;
    #[ApiOperation(summary: 'Download an assigned STT recording', description: 'Returns the recording for the current worker lease.', operationId: 'getLocalSTTJobRecording')]
    #[ApiParameterRef('id', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseRecordingDownloaded')]
    #[ApiResponse(206, 'module_local_speech_to_text_ApiResponseRecordingDownloaded')]
    public function getRecord(string $job_id): void {}
}
