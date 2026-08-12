<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/Lib/Services/JobTerminalSubmissionService.php';
if(!is_file($path))throw new RuntimeException('JobTerminalSubmissionService is missing');
require_once $path;
$order=[];$service=new \Modules\ModuleLocalSpeechToText\Lib\Services\JobTerminalSubmissionService();
$step=function(string $name)use(&$order){return function()use(&$order,$name){$order[]=$name;};};$result=$service->result($step('normalize'),$step('result'),$step('event'),$step('cas'),$step('revision'),fn()=>['ok'=>true]);
if($order!==['normalize','result','event','cas','revision']||$result!==['ok'=>true])throw new RuntimeException('Result terminal production order changed');
if(getenv('WORKER_TERMINAL_SERVICE_MUTATION')==='1'){array_splice($order,2,1);if($order===['normalize','result','event','cas','revision'])throw new RuntimeException('mutation not applied');throw new RuntimeException('Mutation detected: completion event boundary skipped');}
$order=[];$failure=$service->failure($step('cas'),fn()=>['status'=>'pending']);if($order!==['cas']||$failure!==['status'=>'pending'])throw new RuntimeException('Failure terminal production order changed');
echo "STT terminal submission orchestration behavior OK\n";
