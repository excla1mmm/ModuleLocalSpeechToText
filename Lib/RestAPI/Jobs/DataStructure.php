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
  'id'=>['type'=>'integer'],'call_id'=>['type'=>'string'],'file_path'=>['type'=>'string'],'status'=>['type'=>'string'],'priority'=>['type'=>'integer'],
  'worker_id'=>['type'=>['integer','null']],'attempts'=>['type'=>'integer'],'created_at'=>['type'=>'integer'],'updated_at'=>['type'=>'integer'],
  'started_at'=>['type'=>['integer','null']],'finished_at'=>['type'=>['integer','null']],'error_msg'=>['type'=>['string','null']],
  'model_id'=>['type'=>'string','example'=>'parakeet-tdt-0.6b-v3'],
  'model_repo'=>['type'=>'string','example'=>'FluidInference/parakeet-tdt-0.6b-v3-coreml'],
  'model_engine'=>['type'=>'string','enum'=>['whisperKit','parakeet'],'example'=>'parakeet'],
  'model_artifact_type'=>['type'=>'string','enum'=>['whisperkit-coreml','parakeet-coreml'],'example'=>'parakeet-coreml'],
  'whisperkit_model'=>['type'=>['string','null'],'example'=>null],
  'model_display_name'=>['type'=>'string','example'=>'FluidInference/parakeet-tdt-0.6b-v3-coreml/parakeet-tdt-0.6b-v3'],
  'model_source_url'=>['type'=>'string','example'=>'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml'],
  'model_revision'=>['type'=>['string','null'],'pattern'=>'^[a-f0-9]{40}$','example'=>null],
  'model_sha256_manifest'=>['type'=>['array','null'],'minItems'=>1,'maxItems'=>128,'items'=>[
   'type'=>'object','required'=>['path','size','sha256'],'properties'=>[
    'path'=>['type'=>'string','minLength'=>1,'maxLength'=>1024],
    'size'=>['type'=>'integer','minimum'=>1],
    'sha256'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
   ],'additionalProperties'=>false,
  ]],
  'vocabulary_terms'=>['type'=>'array','items'=>['type'=>'string'],'example'=>['МикоПБХ']],
 ]];}
 public static function getRelatedSchemas():array{return[];}
}
