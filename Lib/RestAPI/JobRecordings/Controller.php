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

#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-recordings', tags: ['Local STT Jobs'], description: 'module_local_speech_to_text_JobRecordingsApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_recordings', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['GET' => ['getRecord']], resourceLevelMethods: ['getRecord'], collectionLevelMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController
{
    protected string $processorClass = Processor::class;
    #[ApiOperation(summary: 'module_local_speech_to_text_DownloadJobFile', description: 'module_local_speech_to_text_DownloadJobFileDesc', operationId: 'getLocalSTTJobRecording')]
    #[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)]
    #[ApiParameterRef('X-MikoPBX-Worker-UID', required: true)]
    #[ApiParameterRef('X-MikoPBX-Job-Lease-Token', required: true)]
    #[ApiParameterRef('id', required: true)]
    #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseRecordingDownloaded')]
    #[ApiResponse(206, 'module_local_speech_to_text_ApiResponseRecordingDownloaded')]
    #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
    public function getRecord(string $job_id): void {}
}
