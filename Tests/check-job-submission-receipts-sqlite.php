<?php
declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib { class PBXApiResult { public bool $success=false; public ?int $httpCode=null; public array $data=[]; public array $messages=[]; public string $processor=''; public string $function=''; public ?array $pagination=null; public string $reload=''; public function getResult():array{$r=['result'=>$this->success,'data'=>$this->data,'messages'=>$this->messages,'function'=>$this->function,'processor'=>$this->processor,'pid'=>getmypid()];if($this->httpCode!==null)$r['httpCode']=$this->httpCode;if($this->pagination!==null)$r['pagination']=$this->pagination;if($this->reload!=='')$r['reload']=$this->reload;return$r;} } }
namespace {
use Modules\ModuleLocalSpeechToText\Lib\Services\JobSubmissionReceiptMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\JobSubmissionReceiptService;
require_once __DIR__.'/../Lib/Services/TransactionRecoveryException.php';
require_once __DIR__.'/../Lib/Services/ModuleTransaction.php';
require_once __DIR__.'/../Lib/Services/JobSubmissionReceiptMigration.php';
require_once __DIR__.'/../Lib/Services/JobSubmissionReceiptService.php';

final class ReceiptDb { public function __construct(public PDO $pdo) {} public function isUnderTransaction(): bool{return $this->pdo->inTransaction();} public function begin(): bool{return $this->pdo->beginTransaction();} public function commit(): bool{return $this->pdo->commit();} public function rollback(): bool{return $this->pdo->rollBack();} public function execute(string $sql,array $bind=[]): bool{$s=$this->pdo->prepare($sql);return $s->execute($bind);} public function fetchAll(string $sql,int $mode=PDO::FETCH_ASSOC,array $bind=[]): array{$s=$this->pdo->prepare($sql);$s->execute($bind);return $s->fetchAll($mode);} }
function ok(bool $v,string $m): void { if(!$v) throw new RuntimeException($m); }
$db=new ReceiptDb(new PDO('sqlite::memory:')); $db->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->begin(); (new JobSubmissionReceiptMigration())->migrate($db); (new JobSubmissionReceiptMigration())->migrate($db); $db->commit();
$rollbackDb=new ReceiptDb(new PDO('sqlite::memory:')); $rollbackDb->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $rollbackDb->begin(); (new JobSubmissionReceiptMigration())->migrate($rollbackDb); $rollbackDb->rollback(); ok($rollbackDb->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='m_STTJobSubmissionReceipt'")===[], 'failed migration transaction must roll back receipt schema');
$service=new JobSubmissionReceiptService(); $payload=['transcript'=>[['text'=>'hello','start'=>0.0]],'technical_info'=>['b'=>2,'a'=>1],'worker_uid'=>'w','lease_token'=>'raw-secret'];
$digest=JobSubmissionReceiptService::digest('result',$payload); ok($digest===JobSubmissionReceiptService::digest('result',['technical_info'=>['a'=>1,'b'=>2],'transcript'=>[['start'=>0.0,'text'=>'hello']],'lease_token'=>'different','worker_uid'=>'w']), 'canonical digest must ignore token and key order');
$mutations=0; $make=function()use(&$mutations){$mutations++;$r=new \MikoPBX\PBXCoreREST\Lib\PBXApiResult();$r->success=true;$r->httpCode=200;$r->data=['status'=>'done'];$r->messages=['info'=>['accepted']];$r->processor='ResultProcessor';$r->function='update';$r->pagination=['total'=>1];$r->reload='/stable';return $r;};
$first=$service->executeOnConnection($db,7,3,'raw-secret','result',$digest,$make); $retry=$service->executeOnConnection($db,7,3,'raw-secret','result',$digest,$make);
ok($first->success && $retry->success && $mutations===1,'identical retry must replay without mutation');
$a=$first->getResult();$b=$retry->getResult();unset($a['pid'],$b['pid']);ok($a===$b,'first and receipt replay Core result envelopes must match');
$conflict=$service->executeOnConnection($db,7,3,'raw-secret','result',JobSubmissionReceiptService::digest('result',['transcript'=>[['text'=>'different']]]),$make);
ok($conflict->httpCode===409 && ($conflict->data['error_code']??'')==='job_submission_conflict' && $mutations===1,'different retry must conflict without mutation');
$stored=$db->fetchAll('SELECT * FROM m_STTJobSubmissionReceipt'); ok(count($stored)===1 && !str_contains(json_encode($stored),'raw-secret') && !str_contains(json_encode($stored),'hello'),'receipt must store neither raw token nor payload');
echo "STT job submission receipt SQLite behavior OK\n";
}
