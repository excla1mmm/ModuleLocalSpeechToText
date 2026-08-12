<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;
use MikoPBX\PBXCoreREST\Attributes\{ApiDataSchema,ApiOperation,ApiParameterRef,ApiResource,ApiResponse,HttpMapping,ResourceSecurity,SecurityType};
use MikoPBX\PBXCoreREST\Controllers\BaseRestController;
use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
#[ApiResource(path:LocalSpeechToTextMain::API_BASE_PATH.'/jobs',tags:['Local Speech To Text'],description:'Administrative STT queue',processor:Processor::class)]
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
  $requestData=self::sanitizeData($this->request->getData(),$this->filter);
  if(array_key_exists('id',$requestData))$requestData['_idFromRequestBody']=$requestData['id'];
  if($id!==null&&$id!=='')$requestData['id']=$id;
  $httpMethod=$this->request->getMethod();$action=$this->mapHttpMethodToAction($httpMethod,$id!==null);
  if($action===null){$this->sendErrorResponse("Invalid HTTP method: $httpMethod",405);return;}
  if(in_array($httpMethod,['POST','PUT','PATCH'],true))$requestData['httpMethod']=$httpMethod;
  if($httpMethod==='DELETE'&&$id===null){$query=$this->request->getQuery();$requestData['_query_status_present']=is_array($query)&&array_key_exists('status',$query);$requestData['_query_status']=$requestData['_query_status_present']?$query['status']:null;}
  $this->sendRequestToBackendWorker($this->processorClass,$action,$requestData);
 }
 #[ApiDataSchema(schemaClass:DataStructure::class,type:'list',isArray:true)] #[ApiOperation(summary:'List STT jobs',operationId:'getLocalSTTJobs')] #[ApiParameterRef('status')] #[ApiParameterRef('call_id')] #[ApiParameterRef('limit')] #[ApiParameterRef('offset')] public function getList():void{}
 #[ApiDataSchema(schemaClass:DataStructure::class,type:'detail')] #[ApiOperation(summary:'Get STT job',operationId:'getLocalSTTJob')] #[ApiParameterRef('id',required:true)] public function getRecord(string $id):void{}
 #[ApiDataSchema(schemaClass:DataStructure::class,type:'detail')] #[ApiOperation(summary:'Create STT job',operationId:'createLocalSTTJob')] #[ApiParameterRef('call_id',required:true)] #[ApiParameterRef('file_path',required:true)] #[ApiParameterRef('priority')] public function create():void{}
 #[ApiOperation(summary:'Retry or reset STT job',operationId:'patchLocalSTTJob')] #[ApiParameterRef('id',required:true)] #[ApiParameterRef('action',required:true)] public function patch(string $id):void{}
 #[ApiOperation(summary:'Retry or reset STT jobs',operationId:'patchLocalSTTJobs')] #[ApiParameterRef('ids',required:true)] #[ApiParameterRef('action',required:true)] public function patchCollection():void{}
 #[ApiOperation(summary:'Delete STT job',operationId:'deleteLocalSTTJob')] #[ApiParameterRef('id',required:true)] public function delete(string $id):void{}
 #[ApiOperation(summary:'Delete filtered STT jobs',operationId:'deleteLocalSTTJobs')] #[ApiParameterRef('status',required:true)] public function deleteCollection():void{}
 /** @route GET /pbxcore/api/v3/module-local-speech-to-text/jobs:getNext */ public function getNext(): void { /* Undocumented API v1 tombstone. */ }
 /** @route GET /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:download */ public function download(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:submitResult */ public function submitResult(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:submitError */ public function submitError(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:heartbeat */ public function heartbeat(string $id): void { /* Undocumented API v1 tombstone. */ }
 /** @route POST /pbxcore/api/v3/module-local-speech-to-text/jobs/{id}:release */ public function release(string $id): void { /* Undocumented API v1 tombstone. */ }
}
