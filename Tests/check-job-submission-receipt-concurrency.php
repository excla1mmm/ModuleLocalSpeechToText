<?php
declare(strict_types=1);
namespace MikoPBX\PBXCoreREST\Lib { class PBXApiResult {public bool $success=false;public ?int $httpCode=null;public array $data=[];public array $messages=[];public string $processor='';public string $function='';public ?array $pagination=null;public string $reload='';} }
namespace {
use Modules\ModuleLocalSpeechToText\Lib\Services\{JobSubmissionReceiptMigration,JobSubmissionReceiptService,JobTerminalSubmissionService};
require_once __DIR__.'/../Lib/Services/TransactionRecoveryException.php';require_once __DIR__.'/../Lib/Services/ModuleTransaction.php';require_once __DIR__.'/../Lib/Services/JobSubmissionReceiptMigration.php';require_once __DIR__.'/../Lib/Services/JobSubmissionReceiptService.php';require_once __DIR__.'/../Lib/Services/JobTerminalSubmissionService.php';
final class Db
{
    private bool $underTransaction = false;

    public function __construct(public PDO $p) {}

    public function isUnderTransaction(): bool
    {
        return $this->underTransaction;
    }

    public function begin(): bool
    {
        $began = $this->p->exec('BEGIN IMMEDIATE') !== false;
        if ($began) {
            $this->underTransaction = true;
        }
        return $began;
    }

    public function commit(): bool
    {
        $committed = $this->p->exec('COMMIT') !== false;
        if ($committed) {
            $this->underTransaction = false;
        }
        return $committed;
    }

    public function rollback(): bool
    {
        $rolledBack = $this->p->exec('ROLLBACK') !== false;
        if ($rolledBack) {
            $this->underTransaction = false;
        }
        return $rolledBack;
    }

    public function execute(string $s, array $b = []): bool
    {
        $q = $this->p->prepare($s);
        return $q->execute($b);
    }

    public function fetchAll(string $s, int $m = PDO::FETCH_ASSOC, array $b = []): array
    {
        $q = $this->p->prepare($s);
        $q->execute($b);
        return $q->fetchAll($m);
    }
}
function db(string $f):Db{$p=new PDO('sqlite:'.$f);$p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$p->exec('PRAGMA busy_timeout=5000');return new Db($p);} function e(bool$c,string$m):void{if(!$c)throw new RuntimeException($m);}
if(($argv[1]??'')==='child'){$d=db($argv[2]);while(!is_file($argv[3]))usleep(1000);$svc=new JobSubmissionReceiptService();$payload=$argv[4];$digest=JobSubmissionReceiptService::digest('result',['transcript'=>[['text'=>$payload]]]);$r=$svc->executeOnConnection($d,1,1,'same-lease','result',$digest,function()use($d,$payload){return(new JobTerminalSubmissionService())->result(fn()=>null,fn()=>$d->execute('INSERT INTO m_STTResult(payload) VALUES (:p)',['p'=>$payload]),fn()=>$d->execute('INSERT INTO m_STTTranscriptEvent(payload) VALUES (:p)',['p'=>$payload]),fn()=>$d->execute("UPDATE m_STTJob SET status='done' WHERE id=1"),fn()=>$d->execute('INSERT INTO m_STTCallTranscriptRevision(payload) VALUES (:p)',['p'=>$payload]),function()use($payload){$r=new \MikoPBX\PBXCoreREST\Lib\PBXApiResult();$r->success=true;$r->httpCode=200;$r->data=['winner'=>$payload];$r->processor='result';$r->function='update';return$r;});});echo json_encode(['code'=>$r->httpCode,'data'=>$r->data]);exit;}
$dir=sys_get_temp_dir().'/stt-receipt-race-'.bin2hex(random_bytes(4));mkdir($dir);$file=$dir.'/db.sqlite';$d=db($file);$d->execute("CREATE TABLE m_STTJob(id INTEGER PRIMARY KEY,status TEXT)");$d->execute("INSERT INTO m_STTJob VALUES(1,'processing')");foreach(['m_STTResult','m_STTTranscriptEvent','m_STTCallTranscriptRevision']as$t)$d->execute("CREATE TABLE $t(id INTEGER PRIMARY KEY,payload TEXT)");$d->begin();(new JobSubmissionReceiptMigration())->migrate($d);$d->commit();
$barrier=$dir.'/go';$spawn=function(string$p)use($file,$barrier){$cmd=[PHP_BINARY,__FILE__,'child',$file,$barrier,$p];$pipes=[];$proc=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);return[$proc,$pipes];};
foreach([['same','same'],['left','right']]as$i=>[$a,$b]){if($i===1){$d->execute('DELETE FROM m_STTJobSubmissionReceipt');foreach(['m_STTResult','m_STTTranscriptEvent','m_STTCallTranscriptRevision']as$t)$d->execute("DELETE FROM $t");$d->execute("UPDATE m_STTJob SET status='processing'");@unlink($barrier);}[$p1,$x1]=$spawn($a);[$p2,$x2]=$spawn($b);touch($barrier);$o1=stream_get_contents($x1[1]);$e1=stream_get_contents($x1[2]);$o2=stream_get_contents($x2[1]);$e2=stream_get_contents($x2[2]);$c1=proc_close($p1);$c2=proc_close($p2);e($c1===0&&$c2===0,"child failure $e1 $e2");$r1=json_decode($o1,true);$r2=json_decode($o2,true);foreach(['m_STTResult','m_STTTranscriptEvent','m_STTCallTranscriptRevision']as$t)e(count($d->fetchAll("SELECT * FROM $t"))===1,"race duplicated $t");e(count($d->fetchAll('SELECT * FROM m_STTJobSubmissionReceipt'))===1,'race created multiple receipts');if($i===0)e($r1===$r2,'identical race responses differ');else e(in_array(409,[$r1['code'],$r2['code']],true),'different race lacks conflict');}
echo "STT submission receipt two-process production transaction behavior OK\n";
}
