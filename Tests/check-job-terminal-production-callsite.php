<?php
declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib {
    class PBXApiResult { public bool $success=false; public ?int $httpCode=null; public array $data=[]; public array $messages=[]; public string $processor=''; public string $function=''; public ?array $pagination=null; public string $reload=''; }
}
namespace MikoPBX\Core\System { class Directories { public const string AST_MONITOR_DIR='monitor'; public static function getDir(string $key): string { return ''; } } }
namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    class LocalSpeechToTextLogger { public static function event(string $a,string $b,string $c,int $d,array $e=[]): void {} }
    class LocalSpeechToTextLogSanitizer { public static string $last=''; public static function errorCode(\Throwable $e): string { self::$last=$e::class.':'.$e->getMessage(); return 'test'; } }
    class CallTranscriptRevisionService { public function recordCompletedPart(mixed $result,mixed $job): array { TerminalProbe::$order[]='revision'; TerminalProbe::$db->execute('INSERT INTO m_STTCallTranscriptRevision(job_id) VALUES (:id)',['id'=>(int)$job->id]); return ['state'=>'recorded']; } }
    final class TerminalProbe { public static array $order=[]; public static TerminalDb $db; }
    final class TerminalDb {
        public bool $failReceipt=false; public bool $failCommit=false;
        public function __construct(public \PDO $pdo) {}
        public function isUnderTransaction(): bool { return $this->pdo->inTransaction(); }
        public function begin(): bool { return $this->pdo->beginTransaction(); }
        public function commit(): bool { if($this->failCommit){$this->failCommit=false;return false;} return $this->pdo->commit(); }
        public function rollback(): bool { return $this->pdo->rollBack(); }
        public function execute(string $sql,array $bind=[]): bool { if($this->failReceipt && str_contains($sql,'m_STTJobSubmissionReceipt')){$this->failReceipt=false;return false;} $s=$this->pdo->prepare($sql); return $s->execute($bind); }
        public function fetchAll(string $sql,int $mode=\PDO::FETCH_ASSOC,array $bind=[]): array { $s=$this->pdo->prepare($sql);$s->execute($bind);return $s->fetchAll($mode); }
    }
}
namespace Modules\ModuleLocalSpeechToText\Models {
    use Modules\ModuleLocalSpeechToText\Lib\Services\{TerminalDb,TerminalProbe};
    class STTSetting { public const string CALL_TRANSCRIPT_STABILITY_SEC='call'; public static function workerApiKeyIds():array{return[77];} public static function getValue(string $k,string $d=''):string{return$d;} public static function maxAttempts():int{return 3;} }
    class STTWorker { public int$id=1; public string$worker_uid='worker-one'; public int$api_key_id=77; public static ?self$row=null; public static function findFirst(array $p):?self{return self::$row;} public function touchOnline(string $ip):void{} }
    class STTJob {
        public const string STATUS_PROCESSING='processing',STATUS_ERROR='error'; public static ?self$row=null;
        public int$id=9,$worker_id=1,$attempts=0,$started_at=1,$finished_at=2,$recording_duration_sec=1,$cdr_id=0,$cdr_start_ms=0,$cdr_end_ms=0; public string$status='processing',$lease_token='lease-secret',$lease_expires_at='',$call_id='',$recording_fingerprint='',$cdr_uniqueid='',$file_path='';
        public static function findFirstById(int$id):?self{return self::$row&&self::$row->id===$id?self::$row:null;} public static function requeueStaleProcessing():void{} public static function maxAttempts():int{return 3;}
        public function getWriteConnection():TerminalDb{return TerminalProbe::$db;} public function getReadConnection():TerminalDb{return TerminalProbe::$db;}
        public function markDoneForWorker(int$worker,string$token):bool { TerminalProbe::$order[]='cas'; if($token==='false-cas')return false; $ok=TerminalProbe::$db->execute("UPDATE m_STTJob SET status='done' WHERE id=:id AND status='processing'",['id'=>$this->id]); if($ok){$this->status='done';} return $ok; }
        public function markFailedForWorker(int$worker,string$token,string$error):bool { TerminalProbe::$order[]='failure-cas'; if($token==='false-cas')return false; $ok=TerminalProbe::$db->execute("UPDATE m_STTJob SET status='error' WHERE id=:id AND status='processing'",['id'=>$this->id]); if($ok){$this->status='error';$this->attempts++;} return $ok; }
    }
    class STTResult { public int$id=0; public string$transcript=''; public static function saveForJob(STTJob$job,string$transcript,?string$lang,?float$duration,?string$model,?string$technical):self { TerminalProbe::$order[]='result'; TerminalProbe::$db->execute('INSERT INTO m_STTResult(job_id,transcript) VALUES (:id,:t)',['id'=>$job->id,'t'=>$transcript]);$x=new self();$x->id=(int)TerminalProbe::$db->pdo->lastInsertId();$x->transcript=$transcript;return$x; } }
    class STTTranscriptEvent { public int$id=0; public static function ensureCompletedForResult(STTResult$r,STTJob$j):self { TerminalProbe::$order[]='event';TerminalProbe::$db->execute('INSERT INTO m_STTTranscriptEvent(job_id) VALUES (:id)',['id'=>$j->id]);$x=new self();$x->id=(int)TerminalProbe::$db->pdo->lastInsertId();return$x; } }
}
namespace {
    use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
    use Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain;
    use Modules\ModuleLocalSpeechToText\Lib\Services\{JobSubmissionReceiptMigration,JobSubmissionReceiptService,TerminalDb,TerminalProbe};
    use Modules\ModuleLocalSpeechToText\Models\{STTJob,STTWorker};
    function assertTerminal(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
    require_once __DIR__.'/../Lib/Exceptions/AssignedJobConflictException.php';
    require_once __DIR__.'/../Lib/Exceptions/WorkerApiKeyBindingConflictException.php';
    require_once __DIR__.'/../Lib/TranscriptTimeNormalizer.php';
    require_once __DIR__.'/../Lib/TranscriptQualityFilter.php';
    require_once __DIR__.'/../Lib/Services/TransactionRecoveryException.php';
    require_once __DIR__.'/../Lib/Services/ModuleTransaction.php';
    require_once __DIR__.'/../Lib/Services/JobTerminalSubmissionService.php';
    require_once __DIR__.'/../Lib/Services/JobSubmissionReceiptMigration.php';
    require_once __DIR__.'/../Lib/Services/JobSubmissionReceiptService.php';
    require_once __DIR__.'/../Lib/LocalSpeechToTextMain.php';
    $pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db=new TerminalDb($pdo);TerminalProbe::$db=$db;
    foreach(['CREATE TABLE m_STTJob(id INTEGER PRIMARY KEY,status TEXT)','CREATE TABLE m_STTResult(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INTEGER,transcript TEXT)','CREATE TABLE m_STTTranscriptEvent(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INTEGER)','CREATE TABLE m_STTCallTranscriptRevision(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INTEGER)']as$sql)$db->execute($sql);
    $db->begin();(new JobSubmissionReceiptMigration())->migrate($db);$db->commit();$worker=new STTWorker();STTWorker::$row=$worker;
    $reset=function(string$token='lease-secret')use($db):STTJob{$db->execute('DELETE FROM m_STTJob');foreach(['m_STTResult','m_STTTranscriptEvent','m_STTCallTranscriptRevision','m_STTJobSubmissionReceipt']as$t)$db->execute("DELETE FROM $t");$db->execute("INSERT INTO m_STTJob(id,status) VALUES (9,'processing')");$j=new STTJob();$j->lease_token=$token;$j->lease_expires_at=(string)(time()+60);STTJob::$row=$j;TerminalProbe::$order=[];return$j;};
    $payload=fn(string$token='lease-secret'):array=>['id'=>9,'worker_uid'=>'worker-one','lease_token'=>$token,'transcript'=>[['text'=>'hello','start_ms'=>0,'end_ms'=>1000]],'duration_ms'=>1000,'_auth_type'=>'bearer_token','_api_key_id'=>77];
    $execute=function(string$operation,array$data,callable$mutation)use($db):PBXApiResult{$service=new JobSubmissionReceiptService();return $service->executeOnConnection($db,9,1,(string)$data['lease_token'],$operation,JobSubmissionReceiptService::digest($operation,$data),$mutation);};
    $reset();$result=$execute('result',$payload(),fn()=>LocalSpeechToTextMain::submitResult($payload()));assertTerminal($result->success,'real submitResult failed');assertTerminal(TerminalProbe::$order===['result','event','cas','revision'],'real submitResult order mismatch: '.json_encode(TerminalProbe::$order));$storedTranscript=json_decode((string)$db->fetchAll('SELECT transcript FROM m_STTResult')[0]['transcript'],true);assertTerminal(($storedTranscript[0]['start_ms']??null)===0&&($storedTranscript[0]['end_ms']??null)===1000,'normalization did not precede result persistence');foreach(['m_STTResult','m_STTTranscriptEvent','m_STTCallTranscriptRevision','m_STTJobSubmissionReceipt']as$t)assertTerminal((int)$db->fetchAll("SELECT COUNT(*) c FROM $t")[0]['c']===1,"missing committed $t boundary");
    $reset('false-cas');$conflict=$execute('result',$payload('false-cas'),fn()=>LocalSpeechToTextMain::submitResult($payload('false-cas')));assertTerminal($conflict->httpCode===409,'false CAS did not return conflict');foreach(['m_STTResult','m_STTTranscriptEvent','m_STTCallTranscriptRevision','m_STTJobSubmissionReceipt']as$t)assertTerminal((int)$db->fetchAll("SELECT COUNT(*) c FROM $t")[0]['c']===0,"false CAS retained $t");assertTerminal($db->fetchAll('SELECT status FROM m_STTJob')[0]['status']==='processing','false CAS retained job transition');
    foreach(['receipt','commit']as$failure){$reset();$db->{$failure==='receipt'?'failReceipt':'failCommit'}=true;try{$execute('result',$payload(),fn()=>LocalSpeechToTextMain::submitResult($payload()));throw new RuntimeException("$failure failure did not throw");}catch(RuntimeException $e){assertTerminal(!str_contains($e->getMessage(),'did not throw'),$e->getMessage());}foreach(['m_STTResult','m_STTTranscriptEvent','m_STTCallTranscriptRevision','m_STTJobSubmissionReceipt']as$t)assertTerminal((int)$db->fetchAll("SELECT COUNT(*) c FROM $t")[0]['c']===0,"$failure failure retained $t");assertTerminal($db->fetchAll('SELECT status FROM m_STTJob')[0]['status']==='processing',"$failure failure retained job transition");}
    $reset();$failurePayload=['id'=>9,'worker_uid'=>'worker-one','lease_token'=>'lease-secret','error_msg'=>'bad','_auth_type'=>'bearer_token','_api_key_id'=>77];$failure=$execute('failure',$failurePayload,fn()=>LocalSpeechToTextMain::submitError($failurePayload));assertTerminal($failure->success&&TerminalProbe::$order===['failure-cas'],'real submitError did not execute failure CAS: '.json_encode([$failure->success,$failure->httpCode,$failure->data,$failure->messages,TerminalProbe::$order,\Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer::$last]));$reset('false-cas');$failurePayload['lease_token']='false-cas';$failureConflict=$execute('failure',$failurePayload,fn()=>LocalSpeechToTextMain::submitError($failurePayload));assertTerminal($failureConflict->httpCode===409&&(int)$db->fetchAll('SELECT COUNT(*) c FROM m_STTJobSubmissionReceipt')[0]['c']===0,'submitError false CAS did not conflict without receipt');
    if(getenv('WORKER_TERMINAL_CALLSITE_MUTATION')==='1'){$source=file_get_contents(__DIR__.'/../Lib/LocalSpeechToTextMain.php');assertTerminal(is_string($source),'cannot load mutation fixture');$source=str_replace('class LocalSpeechToTextMain','class MutatedLocalSpeechToTextMain',$source);$needle='STTTranscriptEvent::ensureCompletedForResult($state[\'result\'], $job);';assertTerminal(str_contains($source,$needle),'mutation target missing');$source=str_replace($needle,'$state[\'result\'];',$source);eval('?>'.$source);$reset();$execute('result',$payload(),fn()=>\Modules\ModuleLocalSpeechToText\Lib\MutatedLocalSpeechToTextMain::submitResult($payload()));assertTerminal(TerminalProbe::$order===['result','event','cas','revision'],'production mutation escaped order assertion');}
    echo "STT terminal production callsites and receipt transaction behavior OK\n";
}
