<?php
declare(strict_types=1);

namespace MikoPBX\PBXCoreREST\Lib { class PBXApiResult { public bool $success=false; public int $httpCode=0; public array $data=[]; public array $messages=[]; } }
namespace Modules\ModuleLocalSpeechToText\Models {
    class STTWorker { public int $id=1; public string $worker_uid='worker'; public ?int $api_key_id=null; public function hasProcessingSettingsChanged():bool{return false;} public function touchOnline(string $ip):void{} public function updateModel(string $m):void{} public static function findFirst(array $p):?self{return new self();} }
    class STTJob {
        public int $id=42; public string $call_id='call'; public string $status='processing'; public int $worker_id=1; public int $attempts=0; public string $lease_token='secret-token'; public string $lease_expires_at='9999999999'; public int $recording_duration_sec=10; public bool $released=false;
        public static ?self $claimed=null; public static function claimNextForWorker(STTWorker $w):?self{return self::$claimed=new self();} public static function maxAttempts():int{return 3;}
        public function releaseForWorker(int $workerId,string $token):bool{if($workerId!==1||$token!=='secret-token')return false;$this->released=true;$this->status='pending';$this->worker_id=0;$this->lease_token='';$this->lease_expires_at='';return true;}
    }
    class STTSetting {
        public static function selectedModelDisplayName():string{return 'model';} public static function selectedLanguageHint():string{throw new \RuntimeException('injected formatter failure');}
        public static function workerApiKeyIds():array{return [];} public static function selectedModelRuntimeId():string{return '';} public static function selectedModelRepo():string{return '';} public static function selectedModelEngine():string{return '';} public static function selectedModelArtifactType():string{return '';} public static function selectedModelSourceUrl():string{return '';} public static function selectedVocabularyTerms():array{return [];}
    }
}
namespace Modules\ModuleLocalSpeechToText\Lib\Services { class LocalSpeechToTextLogger { public static array $messages=[]; public static function event(mixed ...$x):void{self::$messages[]=json_encode($x);} } class LocalSpeechToTextLogSanitizer { public static function errorCode(\Throwable $e):string{return 'runtime_exception';} } }
namespace {
    require_once __DIR__.'/../Lib/LocalSpeechToTextMain.php';
    $result=\Modules\ModuleLocalSpeechToText\Lib\LocalSpeechToTextMain::getNextJob(['worker_uid'=>'worker']);
    $job=\Modules\ModuleLocalSpeechToText\Models\STTJob::$claimed;
    if($result->httpCode!==500||$job!==null)throw new RuntimeException('Fallible payload preparation must fail before claim');
    $logs=implode(' ',\Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger::$messages);if(str_contains($logs,'secret-token'))throw new RuntimeException('Compensation logging leaked lease token');
    echo "STT post-claim formatter compensation behavior OK\n";
}
