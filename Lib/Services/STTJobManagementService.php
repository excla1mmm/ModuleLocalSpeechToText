<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Core\System\Directories;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Throwable;

final class STTJobManagementService
{
    private const array INTERNAL = ['id','httpMethod','_remote_addr','_auth_type','_api_key_id','_http_method','_auth_user_name','_auth_session_id','_idFromRequestBody'];

    public function getList(array $data): PBXApiResult
    {
        $conditions=[];$bind=[];
        foreach (['status','call_id'] as $field) if (isset($data[$field]) && is_scalar($data[$field]) && trim((string)$data[$field])!=='') {$conditions[]="$field = :$field:";$bind[$field]=trim((string)$data[$field]);}
        $params=['order'=>'id DESC','limit'=>min(200,max(1,(int)($data['limit']??50))),'offset'=>max(0,(int)($data['offset']??0))];
        if ($conditions!==[]) {$params['conditions']=implode(' AND ',$conditions);$params['bind']=$bind;}
        $items=[]; foreach (STTJob::find($params) as $job) if ($job instanceof STTJob) $items[]=self::format($job);
        return self::ok($items);
    }

    public function getRecord(int $id): PBXApiResult
    { $job=$id>0?STTJob::findFirstById($id):null; return $job instanceof STTJob?self::ok(self::format($job)):self::error('job_not_found','Job not found',404); }

    public function create(array $data): PBXApiResult
    {
        $callId=is_scalar($data['call_id']??null)?trim((string)$data['call_id']):'';
        $path=is_scalar($data['file_path']??null)?trim((string)$data['file_path']):'';
        if ($callId==='' || $path==='') return self::error('validation_error','call_id and file_path are required',422);
        if (array_key_exists('priority',$data) && !is_int($data['priority'])) return self::error('validation_error','priority must be an integer',422);
        $priority=$data['priority']??5; if ($priority<1||$priority>10) return self::error('validation_error','priority must be between 1 and 10',422);
        $base=realpath(Directories::getDir(Directories::AST_MONITOR_DIR)); $canonical=realpath($path);
        if ($base===false||$canonical===false||!is_file($canonical)||!is_readable($canonical)||!str_starts_with($canonical,rtrim($base,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) return self::error('invalid_recording_path','Recording must be a readable file under the monitor directory',422);
        try { if (STTJob::existsForFilePath($canonical)) return self::error('duplicate_job','A job already exists for this recording',409); return self::ok(self::format(STTJob::createPending($callId,$canonical,$priority)),201); }
        catch (Throwable) { return STTJob::existsForFilePath($canonical)?self::error('duplicate_job','A job already exists for this recording',409):self::error('internal_error','Internal server error',500); }
    }

    public function patch(int $id,array $data): PBXApiResult { return $this->transition([$id],$data,false); }
    public function patchCollection(array $data): PBXApiResult
    {
        $ids=$data['ids']??null; if (!is_array($ids)||$ids===[]) return self::error('validation_error','ids must be a non-empty array',422);
        $normalized=[]; foreach($ids as $id) { if (!is_int($id)||$id<=0||isset($normalized[$id])) return self::error('validation_error','ids must be unique positive integers',422); $normalized[$id]=true; }
        return $this->transition(array_keys($normalized),$data,true);
    }

    private function transition(array $ids,array $data,bool $bulk): PBXApiResult
    {
        $action=is_scalar($data['action']??null)?trim((string)$data['action']):''; if(!in_array($action,['retry','reset'],true)) return self::error('validation_error','action must be retry or reset',422);
        $connection=(new STTJob())->getWriteConnection();
        try { return (new ModuleTransaction())->run($connection,function()use($ids,$action,$bulk){$jobs=[];foreach($ids as $id){$job=STTJob::findFirstById((int)$id);if(!$job instanceof STTJob)return self::error('job_not_found','Job not found',404);if((string)$job->status!==STTJob::STATUS_ERROR)return self::error('job_transition_forbidden','Only error jobs may be retried or reset',409);$jobs[]=$job;}foreach($jobs as $job){$job->status=STTJob::STATUS_PENDING;$job->worker_id=null;$job->started_at=null;$job->lease_token=null;$job->lease_expires_at=null;$job->finished_at=null;$job->error_msg=null;$job->updated_at=(string)time();if($action==='reset')$job->attempts='0';if(!$job->save())throw new \RuntimeException('Unable to update job');}return self::ok($bulk?['ids'=>$ids,'action'=>$action]:self::format($jobs[0]));}); }
        catch(Throwable){return self::error('internal_error','Internal server error',500);}
    }

    public function delete(int $id): PBXApiResult { return $this->deleteIds([$id]); }
    public function deleteCollection(array $data): PBXApiResult
    {
        $raw=$data['status']??null; $statuses=is_array($raw)?$raw:(is_scalar($raw)?explode(',',(string)$raw):[]);$statuses=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),$statuses),static fn($v)=>$v!=='')));
        if($statuses===[]||array_diff($statuses,[STTJob::STATUS_PENDING,STTJob::STATUS_ERROR])!==[])return self::error('validation_error','status must be a non-empty subset of pending,error',422);
        $ids=[];foreach(STTJob::find(['conditions'=>'status IN ({statuses:array})','bind'=>['statuses'=>$statuses]]) as $job)if($job instanceof STTJob)$ids[]=(int)$job->id;
        return $this->deleteIds($ids);
    }

    private function deleteIds(array $ids): PBXApiResult
    {
        $connection=(new STTJob())->getWriteConnection();
        try{return(new ModuleTransaction())->run($connection,function($db)use($ids){foreach($ids as$id){$job=STTJob::findFirstById((int)$id);if(!$job instanceof STTJob)return self::error('job_not_found','Job not found',404);if(!in_array((string)$job->status,[STTJob::STATUS_PENDING,STTJob::STATUS_ERROR],true))return self::error('job_delete_forbidden','Only pending or error jobs may be deleted',409);foreach(['m_STTResult'=>'job_id','m_STTJobSubmissionReceipt'=>'job_id'] as$table=>$column){$row=$db->fetchOne("SELECT 1 AS present FROM $table WHERE $column = :id LIMIT 1",\PDO::FETCH_ASSOC,['id'=>(int)$id]);if(is_array($row)&&$row!==[])return self::error('job_history_preserved','Job has preserved result or receipt history',409);}}foreach($ids as$id){if($db->execute('DELETE FROM m_STTJob WHERE id = :id AND status IN (:pending,:error)',['id'=>(int)$id,'pending'=>STTJob::STATUS_PENDING,'error'=>STTJob::STATUS_ERROR])!==true)throw new \RuntimeException('Unable to delete job');}return self::ok(['ids'=>$ids]);});}catch(Throwable){return self::error('internal_error','Internal server error',500);}
    }

    public static function format(STTJob $job): array { return ['id'=>(int)$job->id,'call_id'=>(string)$job->call_id,'file_path'=>(string)$job->file_path,'status'=>(string)$job->status,'priority'=>(int)$job->priority,'worker_id'=>$job->worker_id===null?null:(int)$job->worker_id,'attempts'=>(int)$job->attempts,'created_at'=>(int)$job->created_at,'updated_at'=>(int)$job->updated_at,'started_at'=>$job->started_at===null?null:(int)$job->started_at,'finished_at'=>$job->finished_at===null?null:(int)$job->finished_at,'error_msg'=>$job->error_msg]; }
    private static function ok(array $data,int $code=200):PBXApiResult{$r=new PBXApiResult();$r->success=true;$r->httpCode=$code;$r->data=$data;return$r;}
    private static function error(string $code,string $message,int $http):PBXApiResult{$r=new PBXApiResult();$r->success=false;$r->httpCode=$http;$r->data=['error'=>$code,'error_code'=>$code];$r->messages['error'][]=$message;return$r;}
}
