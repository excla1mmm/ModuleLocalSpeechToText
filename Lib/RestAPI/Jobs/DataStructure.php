<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Jobs;
use MikoPBX\PBXCoreREST\Lib\Common\{AbstractDataStructure,OpenApiSchemaProvider};
class DataStructure extends AbstractDataStructure implements OpenApiSchemaProvider{
 public static function getParameterDefinitions():array{return['request'=>[
  'X-MikoPBX-Worker-API-Version'=>['type'=>'integer','in'=>'header','description'=>'module_local_speech_to_text_ApiVersion','enum'=>[2],'example'=>2],
  'X-MikoPBX-Worker-UID'=>['type'=>'string','in'=>'header','description'=>'module_local_speech_to_text_ApiParamWorkerUid','example'=>'mac-mini-stt-01'],
  'X-MikoPBX-Job-Lease-Token'=>['type'=>'string','in'=>'header','description'=>'module_local_speech_to_text_ApiParamLeaseToken','example'=>'lease-token'],
  'id'=>['type'=>'integer','minimum'=>1],'call_id'=>['type'=>'string','maxLength'=>255],'file_path'=>['type'=>'string','maxLength'=>4096],
  'priority'=>['type'=>'integer','minimum'=>1,'maximum'=>10],'ids'=>['type'=>'array','items'=>['type'=>'integer','minimum'=>1]],
  'action'=>['type'=>'string','enum'=>['retry','reset']],'status'=>['type'=>'string'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200],
  'offset'=>['type'=>'integer','minimum'=>0],
 ],'response'=>[]];}
 public static function getListItemSchema():array{return self::getDetailSchema();}
 public static function getDetailSchema():array{return['type'=>'object','properties'=>[
  'id'=>['type'=>'integer'],'call_id'=>['type'=>'string'],'status'=>['type'=>'string'],'priority'=>['type'=>'integer'],
  'worker_id'=>['type'=>['integer','null']],'attempts'=>['type'=>'integer'],'created_at'=>['type'=>'integer'],'updated_at'=>['type'=>'integer'],
  'started_at'=>['type'=>['integer','null']],'finished_at'=>['type'=>['integer','null']],'error_msg'=>['type'=>['string','null']],
  'terminal_error'=>['type'=>'boolean'],
 ]];}
 public static function getRelatedSchemas():array{return[];}
}
