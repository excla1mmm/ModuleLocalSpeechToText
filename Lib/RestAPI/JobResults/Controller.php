<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults;
use MikoPBX\PBXCoreREST\Attributes\{ApiDataSchema,ApiOperation,ApiParameterRef,ApiResource,ApiResponse,HttpMapping,ResourceSecurity,SecurityType};
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-results', tags: ['Local STT Jobs'], description: 'module_local_speech_to_text_JobResultsApiDescription', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_results', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['PUT' => ['update']], resourceLevelMethods: ['update'], collectionLevelMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController { protected string $processorClass = Processor::class;
#[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobResult')]
#[ApiOperation(summary: 'module_local_speech_to_text_SubmitResult', description: 'module_local_speech_to_text_SubmitResultDesc', operationId: 'updateLocalSTTJobResult')]
#[ApiParameterRef('X-MikoPBX-Worker-API-Version', required: true)] #[ApiParameterRef('id', required: true)] #[ApiParameterRef('worker_uid', required: true)] #[ApiParameterRef('lease_token', required: true)] #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseResultAccepted')] #[ApiResponse(426, 'module_local_speech_to_text_ApiResponseUpgradeRequired', 'PBXApiResult')]
public function update(string $job_id): void {} }
