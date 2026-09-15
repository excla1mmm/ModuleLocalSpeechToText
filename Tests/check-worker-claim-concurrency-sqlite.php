<?php
declare(strict_types=1);

namespace Phalcon\Mvc\Model { final class Relation { public const int NO_ACTION=0; } }
namespace MikoPBX\Modules\Models {
    use PDO;
    final class ClaimConnection { private int $affected=0; public function __construct(private PDO $pdo){} public function execute(string $sql,array $bind=[]):bool{$s=$this->pdo->prepare($sql);$ok=$s->execute($bind);$this->affected=$s->rowCount();return$ok;} public function affectedRows():int{return$this->affected;} }
    abstract class ModulesModelsBase {
        protected static ?PDO $pdo=null; public static function useTestPdo(PDO $pdo):void{self::$pdo=$pdo;} public function getWriteConnection():ClaimConnection{return new ClaimConnection(self::$pdo??throw new \RuntimeException('pdo'));}
        public static function findFirst(mixed $p):?static{$pdo=self::$pdo??throw new \RuntimeException('pdo');if(is_int($p)||(is_string($p)&&ctype_digit($p))){$s=$pdo->prepare('SELECT * FROM m_STTJob WHERE id=:id');$s->execute(['id'=>(int)$p]);}else{$s=$pdo->prepare('SELECT * FROM m_STTJob WHERE status=:status ORDER BY priority ASC,cdr_endtime ASC,id ASC LIMIT 1');$s->execute(['status'=>$p['bind']['status']]);}$r=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($r))return null;$m=new static();foreach($r as $k=>$v){if($v!==null&&!in_array($k,['id','worker_id','cdr_id'],true))$v=(string)$v;$m->{$k}=$v;}return$m;} public static function findFirstById(mixed $id):?static{return static::findFirst((int)$id);} public function setSource(string $x):void{} public function initialize():void{} public function hasOne(mixed ...$x):void{}
    }
}
namespace Modules\ModuleLocalSpeechToText\Models { final class STTSetting { public const string PROCESSING_TIMEOUT_SEC='processing_timeout_sec';public static function getValue(string $k,string $d=''):string{return'60';} } final class STTWorker { public int $id=0; } }
namespace {
    use MikoPBX\Modules\Models\ModulesModelsBase;use Modules\ModuleLocalSpeechToText\Models\STTJob;use Modules\ModuleLocalSpeechToText\Models\STTWorker;
    require_once __DIR__.'/../Models/STTJob.php';
    if(!extension_loaded('pdo_sqlite')||!function_exists('pcntl_fork')){fwrite(STDERR,"pdo_sqlite and pcntl required\n");exit(1);}
    $dir=sys_get_temp_dir().'/stt-claim-'.bin2hex(random_bytes(6));mkdir($dir,0700);$db=$dir.'/queue.sqlite';$start=$dir.'/start';
    $pdo=new PDO('sqlite:'.$db);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA journal_mode=WAL');$pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE m_STTWorker(id INTEGER PRIMARY KEY)');$pdo->exec('CREATE TABLE m_STTJob(id INTEGER PRIMARY KEY,call_id TEXT,file_path TEXT,status TEXT,priority INTEGER,worker_id INTEGER,attempts INTEGER,created_at INTEGER,updated_at INTEGER,cdr_endtime INTEGER,started_at INTEGER,lease_token TEXT,lease_expires_at INTEGER,finished_at INTEGER,error_msg TEXT)');$pdo->exec('INSERT INTO m_STTWorker VALUES(1),(2)');$pdo->exec("INSERT INTO m_STTJob(id,call_id,file_path,status,priority,attempts,created_at,updated_at,cdr_endtime) VALUES(1,'c','/tmp/c.wav','pending',1,0,1,1,1)");unset($pdo);
    $pids=[];foreach([1,2] as $wid){$pid=pcntl_fork();if($pid===0){while(!file_exists($start))usleep(1000);$child=new PDO('sqlite:'.$db);$child->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$child->exec('PRAGMA busy_timeout=5000');ModulesModelsBase::useTestPdo($child);$w=new STTWorker();$w->id=$wid;$claim=STTJob::claimNextForWorker($w);file_put_contents($dir.'/result-'.$wid,$claim instanceof STTJob?'claimed':'empty');exit(0);}$pids[]=$pid;}file_put_contents($start,'go');foreach($pids as $pid)pcntl_waitpid($pid,$status);
    $results=[file_get_contents($dir.'/result-1'),file_get_contents($dir.'/result-2')];sort($results);$verify=new PDO('sqlite:'.$db);$row=$verify->query('SELECT status,worker_id,lease_token FROM m_STTJob WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);
    if($results!==['claimed','empty']||$row['status']!=='processing'||!in_array((int)$row['worker_id'],[1,2],true)||(string)$row['lease_token']==='')throw new RuntimeException('Concurrent claim must produce exactly one lease');
    echo "STT two-process single-queue claim behavior OK\n";
}
