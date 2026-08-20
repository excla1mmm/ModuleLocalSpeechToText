<?php
declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib { class PBXApiResult { public bool$success=false;public ?int$httpCode=null;public array$data=[];public array$messages=[];public string$processor='';public string$function='';public ?array$pagination=null;public string$reload=''; } }
namespace Phalcon\Di { class Injectable {} }
namespace MikoPBX\Core\System { class Directories { public const string AST_MONITOR_DIR='monitor';public static string$monitor='';public static function getDir(string$key):string{return self::$monitor;} } }
namespace MikoPBX\Common\Models { class ApiKeys { public string$full_permissions='0',$allowed_paths='';public static ?self$row=null;public static bool$live=true;public static function findFirstById(int$id):?self{return$id===77&&self::$live?self::$row:null;} } }
namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class WorkerApiKeyService { public const array WORKER_ALLOWED_PATHS=['/api/v3/module-local-speech-to-text/job-recordings'=>'read']; }
    class LocalSpeechToTextLogger {public static function event(string$a,string$b,string$c,int$d,array$e=[]):void{}}
    class LocalSpeechToTextLogSanitizer {public static function errorCode(\Throwable$e):string{return'test';}}
    class ModuleLifecycleGate {public static int$acquired=0,$released=0;public function acquireWriteLease(bool$allowDuringDrain=false):?object{self::$acquired++;return new \stdClass();}public function releaseWriteLease(mixed$lease):void{self::$released++;}}
    class ModuleDatabasePragmas {public static bool$throw=false;public function configureRuntime(mixed$c):void{if(self::$throw)throw new \RuntimeException('pragma test throw');}}
    class ModuleDatabaseWriterMutex {public function synchronized(callable$operation):mixed{return$operation();}}
}
namespace Modules\ModuleLocalSpeechToText\Models {
    class STTSetting {public static int$max=1024;public static bool$allowed=true;public static function isWorkerApiKeyIdAllowed(int$id):bool{return$id===77&&self::$allowed;}public static function isWorkerApiKeyIdKnown(int$id):bool{return$id===77;}public static function workerApiKeyIds():array{return self::$allowed?[77]:[];}public static function recordingDurationAllowed(?int$d):bool{return$d===null||$d<=60;}public static function maxFileSizeBytes():int{return self::$max;}}
    class STTWorker {public static ?self$row=null;public static int$finds=0,$touches=0;public static bool$ambiguous=false;public int$id=1,$api_key_id=77;public string$worker_uid='worker-one';public static function findFirst(array$p):?self{self::$finds++;$b=$p['bind']??[];if(isset($b['worker_uid'])&&$b['worker_uid']!=='worker-one')return null;if(isset($b['api_key_id'])&&(int)$b['api_key_id']!==77)return null;if(isset($b['worker_id'])&&(int)$b['worker_id']===1)return self::$ambiguous?clone self::$row:null;return self::$row;}public function touchOnline(string$ip):void{self::$touches++;}}
    class STTJob {public const string STATUS_PROCESSING='processing';public static ?self$row=null;public static int$finds=0,$terminal=0;public int$id=5,$worker_id=1,$recording_duration_sec=1;public string$status='processing',$lease_token='header-token',$lease_expires_at='',$file_path='';public static function findFirstById(int$id):?self{self::$finds++;return self::$row&&self::$row->id===$id?self::$row:null;}public static function requeueStaleProcessing():void{}public function getWriteConnection():object{return new \stdClass();}public function markTerminalErrorForWorker(int$worker,string$token,string$message):bool{self::$terminal++;return true;}}
    class STTResult{} class STTTranscriptEvent{}
}
namespace {
    use MikoPBX\Common\Models\ApiKeys;use MikoPBX\Core\System\Directories;use Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobRecordings\Processor;use Modules\ModuleLocalSpeechToText\Lib\Services\{ModuleDatabasePragmas,ModuleLifecycleGate,WorkerApiKeyService};use Modules\ModuleLocalSpeechToText\Models\{STTJob,STTSetting,STTWorker};
    function recordingAssert(bool$c,string$m):void{if(!$c)throw new RuntimeException($m);}
    require_once __DIR__.'/../Lib/TranscriptTimeNormalizer.php';require_once __DIR__.'/../Lib/TranscriptQualityFilter.php';require_once __DIR__.'/../Lib/Services/WorkerApiContract.php';require_once __DIR__.'/../Lib/RestAPI/Common/WorkerRequestContext.php';require_once __DIR__.'/../Lib/Services/WorkerBindingDecision.php';require_once __DIR__.'/../Lib/Services/CanonicalJobId.php';require_once __DIR__.'/../Lib/Services/JobLeaseOwnership.php';require_once __DIR__.'/../Lib/LocalSpeechToTextMain.php';require_once __DIR__.'/../Lib/RestAPI/JobRecordings/Processor.php';
    $key=new ApiKeys();$paths=WorkerApiKeyService::WORKER_ALLOWED_PATHS;ksort($paths);$key->allowed_paths=json_encode($paths,JSON_THROW_ON_ERROR);ApiKeys::$row=$key;$worker=new STTWorker();STTWorker::$row=$worker;$job=new STTJob();$job->lease_expires_at=(string)(time()+60);STTJob::$row=$job;
    $root=sys_get_temp_dir().'/stt-composed-'.bin2hex(random_bytes(4));$monitor=$root.'/monitor';$outside=$root.'/outside';mkdir($monitor,0700,true);mkdir($outside);$inside=$monitor.'/inside.wav';$external=$outside.'/outside.wav';file_put_contents($inside,'RIFF-valid');file_put_contents($external,'outside');symlink($external,$monitor.'/escape.wav');Directories::$monitor=$monitor;$job->file_path=$inside;
    try {
    $request=function(array$headers=[],array$data=[],array$session=[]) :array{return['httpMethod'=>'GET','httpHeaders'=>$headers,'sessionContext'=>array_merge(['auth_type'=>'bearer_token','token_id'=>77,'remote_addr'=>'127.0.0.1'],$session),'data'=>array_merge(['id'=>5],$data)];};
    $validHeaders=['x-mikopbx-worker-api-version'=>'2','x-mikopbx-worker-uid'=>'worker-one','x-mikopbx-job-lease-token'=>'header-token'];
    $reset=function()use($job,$inside):void{ModuleLifecycleGate::$acquired=ModuleLifecycleGate::$released=STTWorker::$finds=STTWorker::$touches=STTJob::$finds=STTJob::$terminal=0;ModuleDatabasePragmas::$throw=false;STTSetting::$max=1024;STTSetting::$allowed=true;STTWorker::$ambiguous=false;ApiKeys::$live=true;$job->status='processing';$job->worker_id=1;$job->lease_token='header-token';$job->lease_expires_at=(string)(time()+60);$job->recording_duration_sec=1;$job->file_path=$inside;};
    $reset();$version=Processor::callBack($request());recordingAssert($version->httpCode===426&&STTJob::$finds===0&&ModuleLifecycleGate::$acquired===0,'version gate touched model/lifecycle');
    $denials=[
      'not restricted'=>$request($validHeaders,[],['token_id'=>0]),
      'missing uid'=>$request(array_diff_key($validHeaders,['x-mikopbx-worker-uid'=>1])),
      'missing token'=>$request(array_diff_key($validHeaders,['x-mikopbx-job-lease-token'=>1])),
      'wrong uid'=>$request(array_merge($validHeaders,['x-mikopbx-worker-uid'=>'other'])),
      'wrong token'=>$request(array_merge($validHeaders,['x-mikopbx-job-lease-token'=>'wrong'])),
      'route mismatch'=>$request($validHeaders,['_idFromRequestBody'=>6]),
      'body credentials'=>$request(['x-mikopbx-worker-api-version'=>'2'],['worker_uid'=>'worker-one','lease_token'=>'header-token']),
      'query override'=>$request(array_merge($validHeaders,['x-mikopbx-job-lease-token'=>'wrong']),['worker_uid'=>'worker-one','lease_token'=>'header-token']),
    ];
    foreach($denials as$name=>$candidate){$reset();$result=Processor::callBack($candidate);recordingAssert(!$result->success,"$name authorized");recordingAssert(ModuleLifecycleGate::$acquired===0&&STTWorker::$touches===0,"$name reached lifecycle/liveness");}
    foreach(['revoked','unknown','ambiguous']as$name){$reset();if($name==='revoked')STTSetting::$allowed=false;elseif($name==='unknown')ApiKeys::$live=false;else STTWorker::$ambiguous=true;$result=Processor::callBack($request($validHeaders));recordingAssert(!$result->success,"$name key authorized");recordingAssert(ModuleLifecycleGate::$acquired===0&&STTWorker::$touches===0,"$name key reached lifecycle/liveness");}
    $reset();$ok=Processor::callBack($request($validHeaders));$stream=$ok->data['fpassthru']??[];recordingAssert($ok->success&&$ok->httpCode===200&&$stream['filename']===realpath($inside)&&$stream['need_delete']===false&&$stream['download_name']==='inside.wav'&&$stream['content_type']==='audio/wav','valid request did not return real fpassthru metadata');recordingAssert(ModuleLifecycleGate::$acquired===1&&ModuleLifecycleGate::$released===1&&STTWorker::$touches===1,'success lifecycle/liveness mismatch');
    foreach([['outside',$external,1024,1,404],['symlink',$monitor.'/escape.wav',1024,1,404],['traversal',$monitor.'/../outside/outside.wav',1024,1,404],['oversize',$inside,2,1,413],['duration',$inside,1024,61,413]]as[$name,$path,$max,$duration,$code]){$reset();$job->file_path=$path;STTSetting::$max=$max;$job->recording_duration_sec=$duration;$r=Processor::callBack($request($validHeaders));recordingAssert($r->httpCode===$code,"$name case returned wrong status");recordingAssert(ModuleLifecycleGate::$acquired===1&&ModuleLifecycleGate::$released===1,"$name lifecycle imbalance");}
    $reset();ModuleDatabasePragmas::$throw=true;try{Processor::callBack($request($validHeaders));throw new RuntimeException('throw path did not throw');}catch(RuntimeException$e){recordingAssert($e->getMessage()!=='throw path did not throw',$e->getMessage());}recordingAssert(ModuleLifecycleGate::$acquired===1&&ModuleLifecycleGate::$released===1,'throw lifecycle imbalance');
    if(getenv('WORKER_RECORDING_COMPOSITION_MUTATION')==='1'){$source=file_get_contents(__DIR__.'/../Lib/RestAPI/JobRecordings/Processor.php');recordingAssert(is_string($source),'cannot read Processor mutation fixture');$source=str_replace('class Processor extends Injectable','class MutatedProcessor extends Injectable',$source);$needle="trim((string)(\$data['_lease_token_header'] ?? ''))";recordingAssert(str_contains($source,$needle),'mutation target missing');$source=str_replace($needle,"trim((string)(\$data['lease_token'] ?? ''))",$source);eval('?>'.$source);$reset();$mutated=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobRecordings\MutatedProcessor::callBack($request(array_merge($validHeaders,['x-mikopbx-job-lease-token'=>'wrong']),['lease_token'=>'header-token']));recordingAssert(!$mutated->success,'body/query token-source mutation escaped denial assertion');}
    echo "STT recording Processor-to-download production composition behavior OK\n";
    } finally {
        foreach ([$monitor.'/escape.wav', $inside, $external] as $temporaryFile) {
            if (is_link($temporaryFile) || is_file($temporaryFile)) unlink($temporaryFile);
        }
        rmdir($monitor); rmdir($outside); rmdir($root);
    }
}
