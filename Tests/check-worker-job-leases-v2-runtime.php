<?php
declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib { class PBXApiResult { public bool $success=false; public int $httpCode=0; public array $data=[]; public array $messages=[]; public string $processor=''; public string $function=''; } }
namespace Phalcon\Di { class Injectable {} }
namespace Modules\ModuleLocalSpeechToText\Models {
    class STTWorker {
        public static array $rows=[]; public int $id=0; public string $worker_uid=''; public int $api_key_id=0; public int $flag=1; public int $touches=0;
        public static function findFirst(array $p): ?self { $b=$p['bind']??[]; foreach(self::$rows as $w){ if(isset($b['worker_uid'])&&$w->worker_uid!==$b['worker_uid'])continue; if(isset($b['api_key_id'])&&$w->api_key_id!==(int)$b['api_key_id'])continue; if(isset($b['worker_id'])&&$w->id===(int)$b['worker_id'])continue; return $w;} return null; }
        public function getWriteConnection(): object { return new \stdClass(); }
    }
    class STTJob {
        public const string STATUS_PROCESSING='processing'; public static array $rows=[]; public int $id=0; public string $status='pending'; public ?int $worker_id=null; public ?string $lease_token=null; public ?string $lease_expires_at=null; public int $attempts=0;
        public static function findFirstById(int $id): ?self { return self::$rows[$id]??null; }
        public function getWriteConnection(): object { return new \stdClass(); }
    }
}
namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
    class WorkerApiContract { public static function requireVersion(array $r): ?PBXApiResult { if(($r['httpHeaders']['x-mikopbx-worker-api-version']??null)==='2')return null; $x=new PBXApiResult();$x->httpCode=426;$x->data=['error_code'=>'worker_upgrade_required'];return $x; } }
    class ModuleLifecycleGate { public static int $acquires=0; public static int $releases=0; public static array $allow=[]; public function acquireWriteLease(bool $allowDuringDrain=false): object {self::$acquires++;self::$allow[]=$allowDuringDrain;return new \stdClass();} public function releaseWriteLease(mixed $x):void{self::$releases++;} }
    class ModuleDatabasePragmas { public function configureRuntime(mixed $x):void{} }
}
namespace Modules\ModuleLocalSpeechToText\Lib\RestAPI\Common {
    class WorkerRequestContext { public static function data(array $r):array{return $r['data']??[];} public static function isRestrictedWorkerKey(array $d):bool{return ($d['_restricted']??false)===true;} }
}
namespace Modules\ModuleLocalSpeechToText\Lib {
    use MikoPBX\PBXCoreREST\Lib\PBXApiResult; use Modules\ModuleLocalSpeechToText\Models\STTJob; use Modules\ModuleLocalSpeechToText\Models\STTWorker;
    class LocalSpeechToTextMain {
        public static int $calls=0;
        public static bool $throwDomain=false;
        private static function ok(array $d):PBXApiResult{$r=new PBXApiResult();$r->success=true;$r->httpCode=200;$r->data=$d;return$r;}
        public static function getWorkerProcessingSettings(array $d):PBXApiResult{self::$calls++;$w=STTWorker::findFirst(['bind'=>['worker_uid'=>$d['worker_uid']]]);$w->flag=0;return self::ok(['schema_version'=>1]);}
        public static function getNextJob(array $d):PBXApiResult{self::$calls++;foreach(STTJob::$rows as $j){if($j->status==='pending'){$w=STTWorker::findFirst(['bind'=>['worker_uid'=>$d['worker_uid']]]);$j->status='processing';$j->worker_id=$w->id;$j->lease_token='lease';$j->lease_expires_at=(string)(time()+60);return self::ok(['job'=>['id'=>$j->id,'file_url'=>'/job-recordings/'.$j->id]]);}}return self::ok(['job'=>null]);}
        public static function heartbeatJob(array $d):PBXApiResult{self::$calls++;if(self::$throwDomain)throw new \RuntimeException('domain');return self::ok(['status'=>'processing']);}
        public static function releaseJob(array $d):PBXApiResult{self::$calls++;$j=STTJob::$rows[$d['id']];$j->status='pending';$j->worker_id=null;$j->lease_token=null;$j->lease_expires_at=null;return self::ok(['status'=>'pending']);}
    }
}
namespace {
    use Modules\ModuleLocalSpeechToText\Models\STTJob; use Modules\ModuleLocalSpeechToText\Models\STTWorker; use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain; use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
    require_once __DIR__.'/../Lib/Services/WorkerBindingDecision.php'; require_once __DIR__.'/../Lib/Services/JobLeaseOwnership.php'; require_once __DIR__.'/../Lib/Services/CanonicalJobId.php'; require_once __DIR__.'/../Lib/RestAPI/WorkerProcessingSettings/Processor.php'; require_once __DIR__.'/../Lib/RestAPI/JobLeases/Processor.php';
    function ex(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);} function worker(int $id,string $uid,int $key):STTWorker{$w=new STTWorker();$w->id=$id;$w->worker_uid=$uid;$w->api_key_id=$key;STTWorker::$rows[$id]=$w;return$w;} function job(int $id,string $status='pending',?int $wid=null,?string $token=null,int $expiry=0):STTJob{$j=new STTJob();$j->id=$id;$j->status=$status;$j->worker_id=$wid;$j->lease_token=$token;$j->lease_expires_at=$expiry?(string)$expiry:null;STTJob::$rows[$id]=$j;return$j;}
    function req(string $action,array $data=[],string $version='2',array $headers=[]):array{return ['action'=>$action,'httpHeaders'=>array_merge(['x-mikopbx-worker-api-version'=>$version],$headers),'data'=>array_merge(['_restricted'=>true,'_api_key_id'=>77],$data)];}
    $one=worker(1,'one',77); job(1);
    $snap=serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls]);
    foreach(['','1'] as $v){$r=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('create',['worker_uid'=>'one'],$v));ex($r->httpCode===426,'version denial');ex(serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls])===$snap,'version denial mutated');}
    $settings=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerProcessingSettings\Processor::callBack(req('getList',['worker_uid'=>'body-other','_worker_uid_header'=>'one'])); ex($settings->success&&$one->flag===0,'settings caller flag');
    $one->flag=1;$other=worker(2,'other',77);$before=serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls]);
    foreach([['settings','one'],['settings','other']] as [$kind,$uid]){$r=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\WorkerProcessingSettings\Processor::callBack(req('getList',['_worker_uid_header'=>$uid]));ex($r->httpCode===409,'ambiguous binding');ex(serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls])===$before,'ambiguous mutated');}
    unset(STTWorker::$rows[2]);$before=serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls]);
    foreach([req('create',['worker_uid'=>'other']),req('create',['worker_uid'=>'one','_api_key_id'=>88])] as $q){$r=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack($q);ex($r->httpCode===403,'wrong UID/key denial');ex(serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls])===$before,'wrong UID/key mutated');}
    $claim=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('create',['worker_uid'=>'one']));ex($claim->success&&$claim->data['job']['file_url']==='/job-recordings/1','claim path');
    $headerIgnored=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('create',['worker_uid'=>'one'], '2', ['x-mikopbx-worker-uid'=>'other']));ex($headerIgnored->success,'optional lease UID header must not become authority');
    $empty=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('create',['worker_uid'=>'one']));ex($empty->success&&$empty->data['job']===null,'empty queue');
    $denials=[req('patch',['id'=>1,'_idFromRequestBody'=>2,'worker_uid'=>'one','lease_token'=>'lease']),req('patch',['id'=>1,'worker_uid'=>'one','lease_token'=>'wrong']),req('patch',['id'=>1,'worker_uid'=>'one','lease_token'=>'lease'])]; STTJob::$rows[1]->lease_expires_at=(string)(time()-1);
    foreach($denials as $i=>$q){$before=serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls]);$r=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack($q);ex($r->httpCode===409,"denial $i");ex(serialize([STTWorker::$rows,STTJob::$rows,ModuleLifecycleGate::$acquires,LocalSpeechToTextMain::$calls])===$before,"denial $i mutated");}
    foreach([null,true,false,1.0,1.5,[],new stdClass(),0,-1,'0','-1','01',' 1','1 ','+1','1garbage',str_repeat('9',40),2] as $badId){STTJob::$rows[1]->status='processing';STTJob::$rows[1]->worker_id=1;STTJob::$rows[1]->lease_token='lease';STTJob::$rows[1]->lease_expires_at=(string)(time()+60);$beforeAcquire=ModuleLifecycleGate::$acquires;$beforeCalls=LocalSpeechToTextMain::$calls;$r=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('patch',['id'=>1,'_idFromRequestBody'=>$badId,'worker_uid'=>'one','lease_token'=>'lease']));ex($r->httpCode===409&&($r->data['error_code']??'')==='job_id_mismatch'&&ModuleLifecycleGate::$acquires===$beforeAcquire&&LocalSpeechToTextMain::$calls===$beforeCalls,'noncanonical body id');}
    foreach([1,'1'] as $validId){STTJob::$rows[1]->status='processing';STTJob::$rows[1]->worker_id=1;STTJob::$rows[1]->lease_token='lease';STTJob::$rows[1]->lease_expires_at=(string)(time()+60);$beforeCalls=LocalSpeechToTextMain::$calls;$r=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('patch',['id'=>1,'_idFromRequestBody'=>$validId,'worker_uid'=>'one','lease_token'=>'lease']));ex($r->success&&LocalSpeechToTextMain::$calls===$beforeCalls+1,'canonical body id dispatch');}
    STTJob::$rows[1]->lease_expires_at=(string)(time()+60);$hb=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('patch',['id'=>1,'worker_uid'=>'one','lease_token'=>'lease']));$hbAgain=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('patch',['id'=>1,'worker_uid'=>'one','lease_token'=>'lease']));ex($hb->success&&$hbAgain->success,'heartbeat idempotency');$attempts=STTJob::$rows[1]->attempts;$rel=\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('delete',['id'=>1,'worker_uid'=>'one','lease_token'=>'lease']));ex($rel->success&&STTJob::$rows[1]->status==='pending'&&STTJob::$rows[1]->worker_id===null&&STTJob::$rows[1]->attempts===$attempts,'release invariant');
    ex(ModuleLifecycleGate::$acquires===ModuleLifecycleGate::$releases,'lifecycle leak');ex(in_array(false,ModuleLifecycleGate::$allow,true)&&in_array(true,ModuleLifecycleGate::$allow,true),'drain flags');
    STTJob::$rows[1]->status='processing';STTJob::$rows[1]->worker_id=1;STTJob::$rows[1]->lease_token='lease';STTJob::$rows[1]->lease_expires_at=(string)(time()+60);LocalSpeechToTextMain::$throwDomain=true;$beforeRelease=ModuleLifecycleGate::$releases;try{\Modules\ModuleLocalSpeechToText\Lib\RestAPI\JobLeases\Processor::callBack(req('patch',['id'=>1,'worker_uid'=>'one','lease_token'=>'lease']));}catch(RuntimeException){}ex(ModuleLifecycleGate::$releases===$beforeRelease+1,'lifecycle finally on domain exception');
    echo "STT worker job lease v2 executable processor behavior OK\n";
}
