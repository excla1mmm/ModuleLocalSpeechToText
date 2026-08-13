<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;
use MikoPBX\PBXCoreREST\Attributes\{ApiDataSchema,ApiOperation,ApiParameterRef,ApiResource,ApiResponse,HttpMapping,ResourceSecurity,SecurityType};
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
#[ApiResource(path:LocalSpeechToTextMain::API_BASE_PATH.'/jobs',tags:['Local STT Jobs'],description:'module_local_speech_to_text_AdminJobsApiDescription',processor:Processor::class)]
#[ResourceSecurity('module_local_speech_to_text_jobs',requirements:[SecurityType::LOCALHOST,SecurityType::BEARER_TOKEN])]
#[HttpMapping(mapping: [
    'GET' => ['getList', 'getRecord', 'getNext', 'download'],
    'POST' => ['create', 'submitResult', 'submitError', 'heartbeat', 'release'],
    'PATCH' => ['patch', 'patchCollection'],
    'DELETE' => ['delete', 'deleteCollection'],
], resourceLevelMethods: ['getRecord','patch','delete','download','submitResult','submitError','heartbeat','release'], collectionLevelMethods: ['getList','create','patchCollection','deleteCollection','getNext'], customMethods: ['getNext','download','submitResult','submitError','heartbeat','release'], idPattern: '[0-9]+')]
class Controller extends BaseRestController {
 protected string $processorClass=Processor::class;
 protected function getActionMapping(): array { return [
  'GET' => ['collection' => 'getList', 'resource' => 'getRecord'],
  'POST' => ['collection' => 'create'],
  'PATCH' => ['collection' => 'patchCollection', 'resource' => 'patch'],
  'DELETE' => ['collection' => 'deleteCollection', 'resource' => 'delete'],
 ]; }
 public function handleCRUDRequest(?string $id=null): void {
  if($this->request->getMethod()==='POST'&&$id!==null&&$id!==''){$this->sendErrorResponse('POST is not allowed for an individual STT job',405);return;}
  $rawMerged=$this->request->getData();$rawQuery=$this->request->getQuery();$rawQuery=is_array($rawQuery)?$rawQuery:[];$rawBody=$this->rawBodyData($rawMerged,$rawQuery);
  $requestData=self::sanitizeData($rawMerged,$this->filter);
  if(array_key_exists('id',$rawBody))$requestData['_idFromRequestBody']=$rawBody['id'];
  if($id!==null&&$id!=='')$requestData['id']=$id;
  $httpMethod=$this->request->getMethod();$action=$this->mapHttpMethodToAction($httpMethod,$id!==null);
  if($action===null){$this->sendErrorResponse("Invalid HTTP method: $httpMethod",405);return;}
  if($httpMethod==='POST'&&array_key_exists('priority',$rawMerged))$requestData['priority']=$rawMerged['priority'];
  if($httpMethod==='PATCH'&&$id===null){foreach(['ids','action']as$field)if(array_key_exists($field,$rawMerged))$requestData[$field]=$rawMerged[$field];}
  if(in_array($httpMethod,['POST','PUT','PATCH'],true))$requestData['httpMethod']=$httpMethod;
  if($httpMethod==='DELETE'&&$id===null){$requestData['_query_status_present']=array_key_exists('status',$rawQuery);$requestData['_query_status']=$requestData['_query_status_present']?$rawQuery['status']:null;}
  $this->sendRequestToBackendWorker($this->processorClass,$action,$requestData);
 }
 private function rawBodyData(array $merged,array $query):array {
  $contentType=(string)$this->request->getContentType();
  if(str_contains(strtolower($contentType),'application/json')){$decoded=json_decode((string)$this->request->getRawBody(),true);return is_array($decoded)?$decoded:[];}
  return array_diff_key($merged,$query);
 }
 #[ApiDataSchema(schemaClass:DataStructure::class,type:'list',isArray:true)] #[ApiOperation(summary:'module_local_speech_to_text_ListJobs',description:'module_local_speech_to_text_ListJobsDesc',operationId:'getLocalSTTJobs')] #[ApiParameterRef('status')] #[ApiParameterRef('call_id')] #[ApiParameterRef('limit')] #[ApiParameterRef('offset')] public function getList():void{}
 #[ApiDataSchema(schemaClass:DataStructure::class,type:'detail')] #[ApiOperation(summary:'module_local_speech_to_text_GetJob',description:'module_local_speech_to_text_GetJobDesc',operationId:'getLocalSTTJob')] #[ApiParameterRef('id',required:true)] public function getRecord(string $id):void{}
 #[ApiDataSchema(schemaClass:DataStructure::class,type:'detail')] #[ApiOperation(summary:'module_local_speech_to_text_CreateJob',description:'module_local_speech_to_text_CreateJobDesc',operationId:'createLocalSTTJob')] #[ApiParameterRef('call_id',required:true)] #[ApiParameterRef('file_path',required:true)] #[ApiParameterRef('priority')] public function create():void{}
 #[ApiOperation(summary:'module_local_speech_to_text_RetryJob',description:'module_local_speech_to_text_RetryJobDesc',operationId:'patchLocalSTTJob')] #[ApiParameterRef('id',required:true)] #[ApiParameterRef('action',required:true)] public function patch(string $id):void{}
 #[ApiOperation(summary:'module_local_speech_to_text_RetryJobs',description:'module_local_speech_to_text_RetryJobsDesc',operationId:'patchLocalSTTJobs')] #[ApiParameterRef('ids',required:true)] #[ApiParameterRef('action',required:true)] public function patchCollection():void{}
 #[ApiOperation(summary:'module_local_speech_to_text_DeleteJob',description:'module_local_speech_to_text_DeleteJobDesc',operationId:'deleteLocalSTTJob')] #[ApiParameterRef('id',required:true)] public function delete(string $id):void{}
 #[ApiOperation(summary:'module_local_speech_to_text_DeleteJobs',description:'module_local_speech_to_text_DeleteJobsDesc',operationId:'deleteLocalSTTJobs')] #[ApiParameterRef('status',required:true)] public function deleteCollection():void{}
 /** @route GET /pbxcore/api/v3/module-local-speech-to-text/jobs:getNext */ public function getNext(): void { /* Undocumented API v1 tombstone. */ }
 /** @route GET /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:download */ public function download(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:submitResult */ public function submitResult(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:submitError */ public function submitError(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:heartbeat */ public function heartbeat(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:release */ public function release(string $id): void { /* Undocumented API v1 tombstone. */ }
}
