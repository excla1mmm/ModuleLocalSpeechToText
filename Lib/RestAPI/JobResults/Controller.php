<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobResults;
use MikoPBX\PBXCoreREST\Attributes\{ApiDataSchema,ApiOperation,ApiParameterRef,ApiResource,ApiResponse,HttpMapping,ResourceSecurity,SecurityType};
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
#[ApiResource(path: LocalSpeechToTextMain::API_BASE_PATH . '/job-results', tags: ['Local STT Jobs'], description: 'Final STT job results', processor: Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_job_results', requirements: [SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: ['PUT' => ['update']], resourceLevelMethods: ['update'], collectionLevelMethods: [], idPattern: '[0-9]+')]
class Controller extends BaseRestController { protected string $processorClass = Processor::class;
#[ApiDataSchema(schemaClass: DataStructure::class, type: 'detail', schemaName: 'LocalSTTJobResult')]
#[ApiOperation(summary: 'Submit an STT job result', description: 'Idempotently stores the final result.', operationId: 'updateLocalSTTJobResult')]
#[ApiParameterRef('id', required: true)] #[ApiParameterRef('worker_uid', required: true)] #[ApiParameterRef('lease_token', required: true)] #[ApiResponse(200, 'module_local_speech_to_text_ApiResponseResultAccepted')]
public function update(string $job_id): void {} }
