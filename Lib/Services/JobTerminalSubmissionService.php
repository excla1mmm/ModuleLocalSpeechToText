<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Lib\Services;
final class JobTerminalSubmissionService
{
    public function transactionalResult(callable $pipeline): mixed { return $pipeline(); }
    public function result(callable $normalize, callable $saveResult, callable $publishEvent, callable $completeJob, callable $recordRevision, callable $response): mixed
    { $normalize(); $saveResult(); $publishEvent(); $completeJob(); $recordRevision(); return $response(); }
    public function failure(callable $failJob, callable $response): mixed
    { if ($failJob() === false) return false; return $response(); }
}
