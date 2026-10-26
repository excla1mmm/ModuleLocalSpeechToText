<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\Services;
use JsonException;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use PDO;
use RuntimeException;
use Throwable;

final class JobSubmissionMutationRejected extends RuntimeException
{
    public function __construct(public readonly PBXApiResult $result) { parent::__construct('STT submission mutation rejected'); }
}

final class JobSubmissionReceiptService
{
    private const array INTERNAL_KEYS = ['id','worker_uid','lease_token','_remote_addr','_auth_type','_api_key_id','_http_method','_worker_uid_header','_lease_token_header','_idFromRequestBody','httpMethod'];

    public static function digest(string $operation, array $data): string
    {
        $semantic = array_diff_key($data, array_flip(self::INTERNAL_KEYS));
        self::sortRecursive($semantic);
        return hash('sha256', $operation . "\0" . json_encode($semantic, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function tokenHash(string $token): string { return hash('sha256', $token); }

    public function replayOrConflict(int $jobId, int $workerId, string $token, string $operation, string $digest, mixed $connection = null): ?PBXApiResult
    {
        $connection ??= (new STTJob())->getReadConnection();
        $rows = $connection->fetchAll('SELECT worker_id, operation, payload_digest, response_json, http_code FROM m_STTJobSubmissionReceipt WHERE job_id = :job_id AND lease_token_hash = :token_hash LIMIT 1', PDO::FETCH_ASSOC, ['job_id'=>$jobId,'token_hash'=>self::tokenHash($token)]);
        if ($rows === []) return null;
        $row = $rows[0];
        if ((int)$row['worker_id'] !== $workerId || !hash_equals((string)$row['operation'], $operation) || !hash_equals((string)$row['payload_digest'], $digest)) return self::conflict();
        $stored = json_decode((string)$row['response_json'], true, 32, JSON_THROW_ON_ERROR);
        $result = new PBXApiResult(); $result->success = (bool)($stored['success'] ?? false); $result->httpCode = (int)$row['http_code']; $result->data = $stored['data'] ?? []; $result->messages = is_array($stored['messages'] ?? null) ? $stored['messages'] : [];
        $result->processor = (string)($stored['processor'] ?? ''); $result->function = (string)($stored['function'] ?? '');
        $result->pagination = is_array($stored['pagination'] ?? null) ? $stored['pagination'] : null;
        $result->reload = (string)($stored['reload'] ?? '');
        return $result;
    }

    public function execute(int $jobId, int $workerId, string $token, string $operation, string $digest, callable $mutation): PBXApiResult
    {
        $connection = (new STTJob())->getWriteConnection();
        return $this->executeOnConnection($connection, $jobId, $workerId, $token, $operation, $digest, $mutation);
    }

    public function executeOnConnection(mixed $connection, int $jobId, int $workerId, string $token, string $operation, string $digest, callable $mutation): PBXApiResult
    {
        try {
            return (new ModuleTransaction())->run($connection, function (mixed $connection) use ($jobId,$workerId,$token,$operation,$digest,$mutation): PBXApiResult {
                $existing = $this->replayOrConflict($jobId,$workerId,$token,$operation,$digest,$connection);
                if ($existing !== null) return $existing;
                $result = $mutation();
                if (!$result instanceof PBXApiResult || !$result->success) throw new JobSubmissionMutationRejected($result);
                $response = json_encode(['success'=>$result->success,'data'=>$result->data,'messages'=>$result->messages,'processor'=>$result->processor,'function'=>$result->function,'pagination'=>$result->pagination,'reload'=>$result->reload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $ok = $connection->execute('INSERT INTO m_STTJobSubmissionReceipt (job_id,worker_id,lease_token_hash,operation,payload_digest,response_json,http_code,created_at) VALUES (:job_id,:worker_id,:token_hash,:operation,:digest,:response,:http_code,:created_at)', ['job_id'=>$jobId,'worker_id'=>$workerId,'token_hash'=>self::tokenHash($token),'operation'=>$operation,'digest'=>$digest,'response'=>$response,'http_code'=>(int)$result->httpCode,'created_at'=>time()]);
                if ($ok !== true) throw new RuntimeException('Unable to persist STT submission receipt');
                return $result;
            });
        } catch (JobSubmissionMutationRejected $rejected) {
            return $rejected->result;
        }
    }

    private static function conflict(): PBXApiResult { $r=new PBXApiResult(); $r->success=false; $r->httpCode=409; $r->data=['error'=>'job_submission_conflict','error_code'=>'job_submission_conflict']; $r->messages['error'][]='A different terminal submission already exists for this lease'; return $r; }
    private static function sortRecursive(array &$value): void { foreach ($value as &$item) if (is_array($item)) self::sortRecursive($item); unset($item); if (!array_is_list($value)) ksort($value, SORT_STRING); }
}
