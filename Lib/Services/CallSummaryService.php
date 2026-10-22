<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use DomainException;
use JsonException;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTSummary;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

/**
 * Worker-facing summary operations: claim, heartbeat, release, result and
 * failure. Summaries are the lowest-priority work: none is handed out while a
 * transcription job is pending, and a worker asks only after its job lease
 * came back empty.
 */
final class CallSummaryService
{
    /** @param array<string, mixed> $data */
    public function claim(array $data): PBXApiResult
    {
        $worker = $this->authorizedWorker($data);
        if ($worker instanceof PBXApiResult) {
            return $worker;
        }

        $summary = null;
        try {
            $hasSettingsChanged = $worker->hasProcessingSettingsChanged();
            $worker->touchOnline(self::stringField($data, '_remote_addr'));
            $summary = $this->nextSummary($worker);
            if ($summary === null) {
                return JobApiResponseBuilder::success(['summary' => null, 'has_settings_changed' => $hasSettingsChanged]);
            }
            LocalSpeechToTextLogger::event('queue', 'summary_claimed', 'Summary task claimed by a worker', LOG_INFO, [
                'summary_id' => (int)$summary['id'],
                'result_id' => (int)$summary['result_id'],
                'worker_uid' => (string)$worker->worker_uid,
            ]);

            return JobApiResponseBuilder::success(['summary' => $summary, 'has_settings_changed' => $hasSettingsChanged]);
        } catch (Throwable $exception) {
            // A lease the worker never received goes straight back to the queue.
            if (is_array($summary)) {
                try {
                    STTSummary::findFirstById((int)$summary['id'])?->releaseForWorker((int)$worker->id, (string)$summary['lease_token']);
                } catch (Throwable) {
                    // The lease then expires on its own.
                }
            }

            return $this->internalError(__METHOD__, $exception);
        }
    }

    /** @param array<string, mixed> $data */
    public function heartbeat(array $data): PBXApiResult
    {
        return $this->withLease($data, static function (STTSummary $summary, STTWorker $worker, string $leaseToken): PBXApiResult {
            if (!$summary->refreshLeaseForWorker((int)$worker->id, $leaseToken)) {
                return self::leaseConflict();
            }

            return JobApiResponseBuilder::success([
                'status' => (string)$summary->status,
                'lease_expires_at' => (int)$summary->lease_expires_at,
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    public function release(array $data): PBXApiResult
    {
        return $this->withLease($data, static function (STTSummary $summary, STTWorker $worker, string $leaseToken): PBXApiResult {
            if (!$summary->releaseForWorker((int)$worker->id, $leaseToken)) {
                return self::leaseConflict();
            }

            return JobApiResponseBuilder::success(['status' => (string)$summary->status]);
        });
    }

    /** @param array<string, mixed> $data */
    public function submitResult(array $data): PBXApiResult
    {
        return $this->withLease($data, static function (STTSummary $summary, STTWorker $worker, string $leaseToken) use ($data): PBXApiResult {
            $content = is_string($data['content'] ?? null) ? $data['content'] : '';
            $parsed = SummaryContentParser::parse($content);
            if ($parsed === null) {
                if (!$summary->markFailedForWorker((int)$worker->id, $leaseToken, 'Model answer is not a valid summary')) {
                    return self::leaseConflict();
                }
                LocalSpeechToTextLogger::event('queue', 'summary_rejected', 'Worker returned an unusable summary', LOG_WARNING, [
                    'summary_id' => (int)$summary->id,
                    'worker_uid' => (string)$worker->worker_uid,
                    'attempt' => (int)$summary->attempts,
                ]);

                return JobApiResponseBuilder::success(['status' => (string)$summary->status, 'error_code' => 'summary_content_invalid']);
            }

            $modelUsed = mb_substr(self::stringField($data, 'model_used', SummaryModel::MODEL_ID), 0, 128);
            if (!$summary->markDoneForWorker((int)$worker->id, $leaseToken, $parsed['topic'], $parsed['summary'], $modelUsed)) {
                return self::leaseConflict();
            }
            LocalSpeechToTextLogger::event('queue', 'summary_completed', 'Call summary completed', LOG_NOTICE, [
                'summary_id' => (int)$summary->id,
                'result_id' => (int)$summary->result_id,
                'worker_uid' => (string)$worker->worker_uid,
                'duration_ms' => is_numeric($data['duration_ms'] ?? null) ? (int)$data['duration_ms'] : null,
                'prompt_tokens' => is_numeric($data['prompt_tokens'] ?? null) ? (int)$data['prompt_tokens'] : null,
                'completion_tokens' => is_numeric($data['completion_tokens'] ?? null) ? (int)$data['completion_tokens'] : null,
            ]);

            return JobApiResponseBuilder::success(['status' => (string)$summary->status]);
        });
    }

    /** @param array<string, mixed> $data */
    public function submitFailure(array $data): PBXApiResult
    {
        return $this->withLease($data, static function (STTSummary $summary, STTWorker $worker, string $leaseToken) use ($data): PBXApiResult {
            $message = self::stringField($data, 'error_msg', 'Worker failed to summarize the call');
            if (!$summary->markFailedForWorker((int)$worker->id, $leaseToken, $message)) {
                return self::leaseConflict();
            }
            $failed = (string)$summary->status === STTSummary::STATUS_ERROR;
            LocalSpeechToTextLogger::event(
                'queue',
                $failed ? 'summary_failed' : 'summary_retry',
                $failed ? 'Worker reported a terminal summary error' : 'Worker reported a retryable summary error',
                $failed ? LOG_ERR : LOG_WARNING,
                [
                    'summary_id' => (int)$summary->id,
                    'worker_uid' => (string)$worker->worker_uid,
                    'attempt' => (int)$summary->attempts,
                    'message' => LocalSpeechToTextLogSanitizer::message($message),
                ]
            );

            return JobApiResponseBuilder::success(['status' => (string)$summary->status, 'attempts' => (int)$summary->attempts]);
        });
    }

    /** @return array<string, mixed>|null */
    private function nextSummary(STTWorker $worker): ?array
    {
        if (!STTSetting::summarizationEnabled() || $this->transcriptionPending()) {
            return null;
        }
        STTSummary::enqueueMissing(STTSetting::summarizationEnabledAt());

        // A summary whose transcript can never be turned into a prompt ends in
        // error and the next one is tried, so one bad row never blocks the
        // queue. A failure that may pass (a busy PBX database) returns the
        // lease without spending an attempt.
        $tried = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $summary = STTSummary::claimNextForWorker((int)$worker->id, $tried);
            if ($summary === null) {
                return null;
            }
            $tried[] = (int)$summary->id;
            try {
                return [
                    'id' => (int)$summary->id,
                    'result_id' => (int)$summary->result_id,
                    'lease_token' => (string)$summary->lease_token,
                    'lease_expires_at' => (int)$summary->lease_expires_at,
                    'model_id' => SummaryModel::MODEL_ID,
                    'request' => $this->request((int)$summary->result_id),
                ];
            } catch (JsonException|DomainException $exception) {
                $summary->markFailedForWorker((int)$worker->id, (string)$summary->lease_token, 'Summary prompt could not be built', true);
                LocalSpeechToTextLogger::event('queue', 'summary_prompt_failed', 'Summary prompt could not be built', LOG_WARNING, [
                    'summary_id' => (int)$summary->id,
                    'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                ]);
            } catch (Throwable $exception) {
                $summary->releaseForWorker((int)$worker->id, (string)$summary->lease_token);
                LocalSpeechToTextLogger::event('queue', 'summary_prompt_deferred', 'Summary prompt deferred after a PBX read failure', LOG_WARNING, [
                    'summary_id' => (int)$summary->id,
                    'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                ]);

                return null;
            }
        }

        return null;
    }

    private function transcriptionPending(): bool
    {
        return STTJob::findFirst([
            'conditions' => 'status = :status:',
            'bind' => ['status' => STTJob::STATUS_PENDING],
            'columns' => 'id',
        ]) !== null;
    }

    /** @return array<string, mixed> */
    private function request(int $resultId): array
    {
        $result = STTResult::findFirstById($resultId);
        if (!$result instanceof STTResult) {
            throw new DomainException('Summarized transcript no longer exists');
        }

        // The prompt is Russian whatever the admin UI language is; the answer
        // language follows the conversation.
        $translation = new class {
            public function _(string $key): string
            {
                return match ($key) {
                    'module_local_speech_to_text_Client' => SummaryPromptBuilder::CLIENT_LABEL,
                    'module_local_speech_to_text_Employee' => SummaryPromptBuilder::EMPLOYEE_LABEL,
                    default => $key,
                };
            }
        };
        $recordings = new CallRecordingService($translation, null, null, null);
        $directions = new CallDirectionResolver(CallDirectionResolver::loadInternalNumbers());
        $job = $result->STTJob;
        $cdr = $job instanceof STTJob ? $recordings->cdrRecordForJob($job) : null;
        $cdr ??= $recordings->cdrRecordForResult($result);
        $direction = $directions->resolve($cdr)['direction'];
        $labels = (new ConversationSpeakerLabelResolver($translation, $directions, $recordings->employeeName(...)))->resolve($cdr);
        $language = trim((string)$result->language);
        if ($language === '' || $language === STTSetting::AUTO_LANGUAGE) {
            $language = STTSetting::selectedLanguageId();
        }

        return (new SummaryPromptBuilder())->build(
            (string)$result->transcript,
            $direction === CallDirectionResolver::DIRECTION_UNKNOWN ? '' : $direction,
            $labels,
            $language === STTSetting::AUTO_LANGUAGE ? '' : $language
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(STTSummary, STTWorker, string): PBXApiResult $operation
     */
    private function withLease(array $data, callable $operation): PBXApiResult
    {
        $summaryId = (int)($data['id'] ?? 0);
        $leaseToken = self::stringField($data, 'lease_token');
        if ($summaryId <= 0 || $leaseToken === '') {
            return JobApiResponseBuilder::error('validation_error', 'Summary id, worker_uid and lease_token are required', 422);
        }
        $worker = $this->authorizedWorker($data);
        if ($worker instanceof PBXApiResult) {
            return $worker;
        }

        try {
            $summary = STTSummary::findFirstById($summaryId);
            if (!$summary instanceof STTSummary) {
                return JobApiResponseBuilder::error('summary_not_found', "Summary #$summaryId not found", 404);
            }
            if (!$summary->isLeasedTo((int)$worker->id, $leaseToken) || (int)$summary->lease_expires_at < time()) {
                return self::leaseConflict();
            }
            $result = $operation($summary, $worker, $leaseToken);
            try {
                $worker->touchOnline(self::stringField($data, '_remote_addr'));
            } catch (Throwable) {
                // Liveness is best effort once the lease transition succeeded.
            }

            return $result;
        } catch (Throwable $exception) {
            return $this->internalError(__METHOD__, $exception, ['summary_id' => $summaryId]);
        }
    }

    /** @param array<string, mixed> $data */
    private function authorizedWorker(array $data): STTWorker|PBXApiResult
    {
        $workerUid = self::stringField($data, 'worker_uid');
        if ($workerUid === '') {
            return JobApiResponseBuilder::error('validation_error', 'worker_uid is required', 422);
        }
        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }
        $worker = WorkerAuthorizationValidator::findWorker($workerUid);
        if ($worker === null) {
            return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
        }

        return WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data) ?? $worker;
    }

    private static function leaseConflict(): PBXApiResult
    {
        return JobApiResponseBuilder::error('summary_lease_conflict', 'Summary lease is invalid or expired', 409);
    }

    /** @param array<string, mixed> $context */
    private function internalError(string $operation, Throwable $exception, array $context = []): PBXApiResult
    {
        LocalSpeechToTextLogger::event('api', 'operation_failed', 'STT API operation failed', LOG_ERR, $context + [
            'operation' => $operation,
            'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
            'message' => $exception->getMessage(),
        ]);

        return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
    }

    /** @param array<string, mixed> $data */
    private static function stringField(array $data, string $field, string $default = ''): string
    {
        $value = $data[$field] ?? $default;

        return is_scalar($value) ? trim((string)$value) : $default;
    }
}
