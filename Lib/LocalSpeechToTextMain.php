<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use JsonException;
use InvalidArgumentException;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\AssignedJobConflictException;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\ResultPersistenceValidationException;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\WorkerApiKeyBindingConflictException;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\RecordingDurationProbe;
use Modules\ModuleLocalSpeechToText\Lib\Services\RecordingPathGuard;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerRegistrationTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\JobApiResponseBuilder;
use Modules\ModuleLocalSpeechToText\Lib\Services\JobResultPayloadNormalizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\JobTerminalSubmissionService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerAuthorizationValidator;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use RuntimeException;
use Throwable;

class LocalSpeechToTextMain
{
    public const string API_BASE_PATH = '/pbxcore/api/v3/module-local-speech-to-text';

    public static function registerWorker(array $data): PBXApiResult
    {
        $workerUid = self::stringField($data, 'worker_uid');
        if ($workerUid === '') {
            return JobApiResponseBuilder::error('validation_error', 'worker_uid is required', 422);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/', $workerUid) !== 1) {
            return JobApiResponseBuilder::error('validation_error', 'worker_uid format is invalid', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        $registrationBindingError = WorkerAuthorizationValidator::validateWorkerRegistrationBinding($workerUid, $data);
        if ($registrationBindingError !== null) {
            return $registrationBindingError;
        }

        try {
            $apiKeyId = WorkerAuthorizationValidator::requestApiKeyId($data);
            $connection = (new STTWorker())->getWriteConnection();
            $worker = (new WorkerRegistrationTransaction())->run(
                $connection,
                $apiKeyId,
                $workerUid,
                static fn (): STTWorker => STTWorker::registerWorker(
                    $workerUid,
                    self::stringField($data, 'name', $workerUid),
                    self::currentWorkerModelName(),
                    self::clientIp($data),
                    $apiKeyId,
                    WorkerApiContract::API_VERSION,
                    self::stringField($data, 'app_version')
                )
            );
            LocalSpeechToTextLogger::event(
                'worker',
                'worker_registered',
                'STT worker registered',
                LOG_NOTICE,
                [
                    'worker_id' => (int)$worker->id,
                    'worker_uid' => $workerUid,
                ]
            );

            return JobApiResponseBuilder::success(['id' => (int)$worker->id]);
        } catch (WorkerApiKeyBindingConflictException $exception) {
            $result = JobApiResponseBuilder::error($exception->errorCode, $exception->getMessage(), 409);
            $result->data['error_code'] = $exception->errorCode;

            return $result;
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function getNextJob(array $data): PBXApiResult
    {
        $claimedJob = null;
        $claimedWorkerId = 0;
        $claimedLeaseToken = '';
        $workerUid = self::stringField($data, 'worker_uid');
        if ($workerUid === '') {
            return JobApiResponseBuilder::error('validation_error', 'worker_uid is required', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $worker = WorkerAuthorizationValidator::findWorker($workerUid);
            if ($worker === null) {
                return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $hasSettingsChanged = $worker->hasProcessingSettingsChanged();
            $worker->touchOnline(self::clientIp($data));
            self::syncWorkerModel($worker);
            $jobSnapshot = self::prepareJobSnapshot();
            $job = STTJob::claimNextForWorker($worker);
            if ($job === null) {
                return JobApiResponseBuilder::success([
                    'job' => null,
                    'has_settings_changed' => $hasSettingsChanged,
                ]);
            }
            $claimedJob = $job;
            $claimedWorkerId = (int)$worker->id;
            $claimedLeaseToken = (string)$job->lease_token;
            $payload = self::formatJob($job, $jobSnapshot);
            self::bestEffortEvent(
                'queue',
                'job_claimed',
                'Transcription job claimed by a worker',
                LOG_INFO,
                [
                    'job_id' => (int)$job->id,
                    'worker_uid' => $workerUid,
                    'attempt' => (int)$job->attempts,
                    'max_attempts' => STTJob::maxAttempts(),
                ]
            );

            return JobApiResponseBuilder::success([
                'job' => $payload,
                'has_settings_changed' => $hasSettingsChanged,
            ]);
        } catch (Throwable $exception) {
            if ($claimedJob instanceof STTJob && $claimedWorkerId > 0 && $claimedLeaseToken !== '') {
                try {
                    $compensated = $claimedJob->releaseForWorker($claimedWorkerId, $claimedLeaseToken);
                    self::bestEffortEvent('queue', $compensated ? 'job_claim_compensated' : 'job_claim_compensation_failed', $compensated ? 'Unexpected post-claim failure was compensated' : 'Unexpected post-claim failure could not be compensated', $compensated ? LOG_WARNING : LOG_ERR, ['job_id' => (int)$claimedJob->id, 'worker_uid' => $workerUid]);
                } catch (Throwable) {
                    self::bestEffortEvent('queue', 'job_claim_compensation_failed', 'Unexpected post-claim failure compensation threw', LOG_ERR, ['job_id' => (int)$claimedJob->id, 'worker_uid' => $workerUid]);
                }
            }
            self::logException(
                __METHOD__,
                $exception,
                $claimedJob instanceof STTJob ? ['job_id' => (int)$claimedJob->id] : []
            );
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function getWorkerProcessingSettings(array $data): PBXApiResult
    {
        $workerUid = self::stringField($data, 'worker_uid');
        if ($workerUid === '') {
            return JobApiResponseBuilder::error('validation_error', 'worker_uid is required', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::requireBearerWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $worker = WorkerAuthorizationValidator::findWorker($workerUid);
            if ($worker === null) {
                return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $worker->touchOnline(self::clientIp($data));
            $payload = (new WorkerProcessingSettingsService())->fetchForWorker($worker);

            return JobApiResponseBuilder::success($payload);
        } catch (InvalidArgumentException $exception) {
            return JobApiResponseBuilder::error('validation_error', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function downloadJobFile(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return JobApiResponseBuilder::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return JobApiResponseBuilder::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = WorkerAuthorizationValidator::findWorker($workerUid);
            if ($worker === null) {
                return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }
            $ownershipError = WorkerAuthorizationValidator::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }
            // Ownership must be established before this best-effort liveness mutation.
            self::bestEffortWorkerTouch($worker, self::clientIp($data), $jobId, $workerUid);

            $filePath = self::safeRecordingPath((string)$job->file_path);
            if ($filePath === null || !is_readable($filePath)) {
                if (!$job->markTerminalErrorForWorker(
                    (int)$worker->id,
                    $leaseToken,
                    'Recording file is not available'
                )) {
                    return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
                }
                self::logRecordingAccessFailure($jobId, $workerUid, 'recording_unavailable');

                return JobApiResponseBuilder::error('file_not_found', 'Recording file is not available', 404);
            }

            $durationSeconds = self::jobDurationSeconds($job);
            if ($durationSeconds === null) {
                $probe = (new RecordingDurationProbe())->probe($filePath);
                if ($probe['status'] !== RecordingDurationProbe::STATUS_OK || $probe['duration_sec'] === null) {
                    self::logRecordingAccessFailure($jobId, $workerUid, 'duration_probe_failed');

                    return JobApiResponseBuilder::error('duration_probe_failed', 'Recording duration could not be determined', 503);
                }
                $durationSeconds = $probe['duration_sec'];
            }
            if (!STTSetting::recordingDurationAllowed($durationSeconds)) {
                $message = 'Recording duration exceeds fixed maximum';
                if (!$job->markTerminalErrorForWorker((int)$worker->id, $leaseToken, $message)) {
                    return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
                }
                self::logRecordingAccessFailure($jobId, $workerUid, 'duration_too_long');

                return JobApiResponseBuilder::error('duration_too_long', $message, 413);
            }
            if (!self::fileWithinSizeLimit($filePath)) {
                if (!$job->markTerminalErrorForWorker(
                    (int)$worker->id,
                    $leaseToken,
                    'Recording file exceeds fixed maximum size'
                )) {
                    return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
                }
                self::logRecordingAccessFailure($jobId, $workerUid, 'file_too_large');

                return JobApiResponseBuilder::error('file_too_large', 'Recording file exceeds fixed maximum size', 413);
            }

            return self::fileResponse($filePath);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception, ['job_id' => $jobId]);
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function submitResult(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');

        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '' || !isset($data['transcript']) || !is_array($data['transcript'])) {
            return JobApiResponseBuilder::error('validation_error', 'job id, worker_uid, lease_token and transcript are required', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return JobApiResponseBuilder::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = WorkerAuthorizationValidator::findWorker($workerUid);
            if ($worker === null) {
                return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $ownershipError = WorkerAuthorizationValidator::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            $completionEventId = 0;
            $segmentCount = 0;
            $language = null;
            $model = null;
            $connection = $job->getWriteConnection();
            try {
                $state = [];
                $terminal = new JobTerminalSubmissionService();
                $storeResult = static function () use ($terminal, $data, $job, $worker, $leaseToken, &$state, &$completionEventId, &$segmentCount, &$language, &$model): void {
                    $terminal->result(
                    static function () use ($data, &$state, &$language, &$model, &$segmentCount): void {
                        $technicalInfo = is_array($data['technical_info'] ?? null)
                            ? $data['technical_info']
                            : null;
                        $transcript = TranscriptTimeNormalizer::normalizeTranscriptSegments($data['transcript']);
                        $language = self::nullableStringField($data, 'language');
                        $filterLanguage = $language
                            ?? self::nullableStringField($data, 'language_hint')
                            ?? JobResultPayloadNormalizer::languageHintFromTechnicalInfo($technicalInfo);
                        $filterResult = TranscriptQualityFilter::filterSegments($transcript, $filterLanguage);
                        $state['transcript'] = $filterResult['segments'];
                        $state['technical_info'] = JobResultPayloadNormalizer::mergeTranscriptQualityInfo($technicalInfo, $filterResult['stats']);
                        $state['duration'] = TranscriptTimeNormalizer::durationSecondsFromPayload($data);
                        $model = self::nullableStringField($data, 'model_used');
                        $segmentCount = count($state['transcript']);
                    },
                    static function () use ($job, &$state, &$language, &$model): void {
                        $state['result'] = STTResult::saveForJob(
                            $job,
                            JobResultPayloadNormalizer::encodeTranscript($state['transcript']),
                            $language,
                            $state['duration'],
                            $model,
                            JobResultPayloadNormalizer::encodeTechnicalInfo($state['technical_info'])
                        );
                    },
                    static function () use ($job, &$state, &$completionEventId): void {
                        $completionEvent = STTTranscriptEvent::ensureCompletedForResult($state['result'], $job);
                        $completionEventId = (int)$completionEvent->id;
                    },
                    static function () use ($job, $worker, $leaseToken): void {
                        if (!$job->markDoneForWorker((int)$worker->id, $leaseToken)) {
                            throw new AssignedJobConflictException('Assigned STT job changed during result submission');
                        }
                    },
                    static function () use ($job, &$state): void { (new CallTranscriptRevisionService())->recordCompletedPart($state['result'], $job); },
                    static fn (): null => null,
                    );
                };
                if ($connection->isUnderTransaction()) {
                    // submitResult() is only ever invoked in production through
                    // Lib/RestAPI/JobResults/Processor.php, which already runs
                    // inside a transaction owned by JobSubmissionReceiptService.
                    // $storeResult() cannot be allowed to leave partial writes
                    // (e.g. STTResult saved but STTTranscriptEvent/CallTranscript
                    // recording failed) sitting uncommitted-but-present for the
                    // ambient transaction to later commit alongside a terminal
                    // job-error write. A SAVEPOINT makes this inner sequence
                    // atomic on its own: any Throwable rolls back only these
                    // writes, not the receipt/ownership work the ambient
                    // transaction is also responsible for.
                    (new ModuleTransaction())->runNested($connection, static fn () => $storeResult(), 'stt_submit_result');
                } else {
                    (new ModuleTransaction())->run($connection, $storeResult);
                }
            } catch (AssignedJobConflictException) {
                return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
            } catch (ResultPersistenceValidationException $exception) {
                return self::handleDeterministicResultPersistenceFailure(
                    $job,
                    $worker,
                    $leaseToken,
                    $jobId,
                    $workerUid,
                    $exception
                );
            }
            $durationMs = (int)$job->started_at > 0 && (int)$job->finished_at > 0
                ? max(0, (int)$job->finished_at - (int)$job->started_at) * 1000
                : null;
            $recordingDurationMs = (int)$job->recording_duration_sec > 0
                ? (int)$job->recording_duration_sec * 1000
                : null;
            LocalSpeechToTextLogger::event(
                'queue',
                'transcription_completed',
                'Transcription job completed',
                LOG_NOTICE,
                [
                    'job_id' => $jobId,
                    'worker_uid' => $workerUid,
                    'duration_ms' => $durationMs,
                    'recording_duration_ms' => $recordingDurationMs,
                    'segment_count' => $segmentCount,
                    'language' => $language,
                    'model' => $model,
                ]
            );
            LocalSpeechToTextLogger::event(
                'transcript',
                'transcript_completed_published',
                'Transcript completion event published',
                LOG_INFO,
                [
                    'job_id' => $jobId,
                    'published_event_id' => $completionEventId,
                ]
            );

            return JobApiResponseBuilder::success();
        } catch (InvalidArgumentException | JsonException $exception) {
            return JobApiResponseBuilder::error('validation_error', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception, ['job_id' => $jobId]);
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    /**
     * Handles a deterministic result-persistence failure (a Phalcon model
     * validation rejection, not a transient DB error) by moving the job to a
     * terminal error state instead of leaving it "processing" for the worker
     * to retry identically. See the call site in submitResult() for why this
     * must return a success=true PBXApiResult.
     */
    private static function handleDeterministicResultPersistenceFailure(
        STTJob $job,
        STTWorker $worker,
        string $leaseToken,
        int $jobId,
        string $workerUid,
        Throwable $exception
    ): PBXApiResult {
        // $job may carry in-memory mutations from steps that ran (and were
        // then rolled back to the SAVEPOINT) before the failure -- e.g. a
        // markDoneForWorker() call that updated $job->status/lease_token in
        // PHP before the DB write was undone. Reload from the
        // now-rolled-back DB state so markTerminalErrorForWorker()'s CAS
        // check is not evaluated against stale in-memory fields.
        $reloadedJob = STTJob::findFirstById((int)$job->id);
        if ($reloadedJob instanceof STTJob) {
            $job = $reloadedJob;
        }

        $sanitizedMessage = LocalSpeechToTextLogSanitizer::message($exception->getMessage());
        $errorMsg = 'Result submission could not be persisted (' . $sanitizedMessage . ')';
        if (!$job->markTerminalErrorForWorker((int)$worker->id, $leaseToken, $errorMsg)) {
            return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
        }

        LocalSpeechToTextLogger::event(
            'queue',
            'job_failed',
            'Result submission failed deterministic validation; job moved to a terminal error state',
            LOG_ERR,
            [
                'job_id' => $jobId,
                'worker_uid' => $workerUid,
                'error_code' => 'result_persistence_failed',
                'message' => $sanitizedMessage,
            ]
        );

        // success=true: this must be a "the submission was handled, do not
        // retry" response, not a transient failure. Returning success=false
        // would make Lib/Services/JobSubmissionReceiptService roll back this
        // whole transaction (JobSubmissionMutationRejected), undoing the
        // markTerminalErrorForWorker() write above and leaving the job stuck
        // "processing" again -- reproducing the original incident.
        return JobApiResponseBuilder::success([
            'status' => (string)$job->status,
            'error_code' => 'result_persistence_failed',
        ]);
    }

    public static function submitError(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return JobApiResponseBuilder::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return JobApiResponseBuilder::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = WorkerAuthorizationValidator::findWorker($workerUid);
            if ($worker === null) {
                return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $ownershipError = WorkerAuthorizationValidator::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            $failureAccepted = (new JobTerminalSubmissionService())->failure(
                static fn (): bool => $job->markFailedForWorker(
                    (int)$worker->id,
                    $leaseToken,
                    self::stringField($data, 'error_msg', 'Worker failed to process audio')
                ),
                static fn (): bool => true,
            );
            if (!$failureAccepted) {
                return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
            }
            $failed = (string)$job->status === STTJob::STATUS_ERROR;
            LocalSpeechToTextLogger::event(
                'queue',
                $failed ? 'job_failed' : 'job_retry',
                $failed
                    ? 'Worker reported a terminal transcription error'
                    : 'Worker reported a retryable transcription error',
                $failed ? LOG_ERR : LOG_WARNING,
                [
                    'job_id' => $jobId,
                    'worker_uid' => $workerUid,
                    'attempt' => (int)$job->attempts,
                    'max_attempts' => STTJob::maxAttempts(),
                    'error_code' => 'worker_reported_error',
                ]
            );

            return JobApiResponseBuilder::success([
                'status' => (string)$job->status,
                'attempts' => (int)$job->attempts,
            ]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception, ['job_id' => $jobId]);
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function heartbeatJob(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return JobApiResponseBuilder::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return JobApiResponseBuilder::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = WorkerAuthorizationValidator::findWorker($workerUid);
            if ($worker === null) {
                return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $ownershipError = WorkerAuthorizationValidator::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!$job->refreshProcessingLeaseForWorker((int)$worker->id, $leaseToken)) {
                return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
            }
            self::bestEffortWorkerTouch($worker, self::clientIp($data), $jobId, $workerUid);

            return JobApiResponseBuilder::success([
                'status' => (string)$job->status,
                'lease_expires_at' => (int)$job->lease_expires_at,
            ]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception, ['job_id' => $jobId]);
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function releaseJob(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return JobApiResponseBuilder::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = WorkerAuthorizationValidator::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return JobApiResponseBuilder::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = WorkerAuthorizationValidator::findWorker($workerUid);
            if ($worker === null) {
                return JobApiResponseBuilder::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = WorkerAuthorizationValidator::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $ownershipError = WorkerAuthorizationValidator::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!$job->releaseForWorker((int)$worker->id, $leaseToken)) {
                return JobApiResponseBuilder::assignedJobConflict($jobId, $worker, $leaseToken);
            }
            self::bestEffortWorkerTouch($worker, self::clientIp($data), $jobId, $workerUid);
            self::bestEffortEvent(
                'queue',
                'job_released',
                'Worker released a transcription job',
                LOG_NOTICE,
                [
                    'job_id' => $jobId,
                    'worker_uid' => $workerUid,
                    'attempt' => (int)$job->attempts,
                ]
            );

            return JobApiResponseBuilder::success([
                'status' => (string)$job->status,
            ]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception, ['job_id' => $jobId]);
            return JobApiResponseBuilder::error('internal_error', 'Internal server error', 500);
        }
    }

    private static function prepareJobSnapshot(): array
    {
        $modelPreset = STTSetting::selectedModelPreset();
        $modelEngine = (string)$modelPreset['engine'];
        $modelRuntimeId = (string)$modelPreset['model'];
        $modelRepo = (string)$modelPreset['repo'];

        return [
            'language_hint' => STTSetting::selectedLanguageHint(),
            'model_id' => $modelRuntimeId,
            'model_repo' => $modelRepo,
            'model_engine' => $modelEngine,
            'model_artifact_type' => (string)$modelPreset['artifact_type'],
            'whisperkit_model' => $modelEngine === 'whisperKit' ? $modelRuntimeId : null,
            'model_display_name' => $modelRepo . '/' . $modelRuntimeId,
            'model_revision' => $modelEngine === 'whisperKit' ? (string)($modelPreset['revision'] ?? '') : null,
            'model_sha256_manifest' => $modelEngine === 'whisperKit' ? ($modelPreset['sha256_manifest'] ?? []) : null,
            'vocabulary_terms' => STTSetting::selectedVocabularyTerms(),
        ];
    }

    private static function formatJob(STTJob $job, array $snapshot): array
    {
        return [
            'id' => (int)$job->id,
            'call_id' => (string)$job->call_id,
            'file_url' => '/job-recordings/'.(int)$job->id,
            'lease_token' => (string)$job->lease_token,
            'lease_expires_at' => (int)$job->lease_expires_at,
            'language_hint' => $snapshot['language_hint'],
            'model_id' => $snapshot['model_id'],
            'model_repo' => $snapshot['model_repo'],
            'model_engine' => $snapshot['model_engine'],
            'model_artifact_type' => $snapshot['model_artifact_type'],
            'whisperkit_model' => $snapshot['whisperkit_model'],
            'model_display_name' => $snapshot['model_display_name'],
            'model_revision' => $snapshot['model_revision'],
            'model_sha256_manifest' => $snapshot['model_sha256_manifest'],
            'vocabulary_terms' => $snapshot['vocabulary_terms'],
        ];
    }

    private static function bestEffortWorkerTouch(STTWorker $worker, string $ip, int $jobId, string $workerUid): void
    {
        try { $worker->touchOnline($ip); } catch (Throwable) {
            self::bestEffortEvent('queue', 'worker_liveness_update_failed', 'Lease transition succeeded but worker liveness update failed', LOG_WARNING, ['job_id' => $jobId, 'worker_uid' => $workerUid]);
        }
    }

    private static function bestEffortEvent(string $channel, string $event, string $message, int $priority, array $context): void
    {
        try { LocalSpeechToTextLogger::event($channel, $event, $message, $priority, $context); } catch (Throwable) {}
    }

    private static function jobDurationSeconds(STTJob $job): ?int
    {
        $duration = (int)$job->recording_duration_sec;

        return $duration > 0 ? $duration : null;
    }

    private static function currentWorkerModelName(): string
    {
        return STTSetting::selectedModelDisplayName();
    }

    private static function syncWorkerModel(STTWorker $worker): void
    {
        $worker->updateModel(self::currentWorkerModelName());
    }

    private static function safeRecordingPath(string $filePath): ?string
    {
        return RecordingPathGuard::resolve($filePath);
    }

    private static function fileWithinSizeLimit(string $filePath): bool
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        clearstatcache(true, $filePath);
        $fileSize = filesize($filePath);

        return $fileSize !== false && $fileSize <= STTSetting::maxFileSizeBytes();
    }

    /**
     * Kept as a real method (not just a call site) because
     * Tests/check-recording-runtime-security.php exercises it directly via
     * ReflectionMethod.
     */
    private static function fileResponse(string $filePath): PBXApiResult
    {
        return JobApiResponseBuilder::fileResponse($filePath);
    }

    private static function stringField(array $data, string $field, string $default = ''): string
    {
        $value = $data[$field] ?? $default;

        return is_scalar($value) ? trim((string)$value) : $default;
    }

    private static function nullableStringField(array $data, string $field): ?string
    {
        $value = self::stringField($data, $field);

        return $value === '' ? null : $value;
    }

    private static function clientIp(array $data): string
    {
        return self::stringField($data, '_remote_addr');
    }

    /**
     * @param array<string, bool|float|int|string|null> $extraContext Additional
     *   identifying context (e.g. job_id). Values pass through the same
     *   sanitizer as $message before being logged.
     */
    private static function logException(string $context, Throwable $exception, array $extraContext = []): void
    {
        // The exception message is the only place the actual root cause (e.g. a
        // Phalcon model validation failure like "search_text is required") is
        // available. Losing it here previously meant production incidents
        // surfaced only the exception class name, which was not enough to
        // diagnose the failure. LocalSpeechToTextLogSanitizer strips
        // transcript text, paths, tokens and phone numbers before it is logged.
        LocalSpeechToTextLogger::event(
            'api',
            'operation_failed',
            'STT API operation failed',
            LOG_ERR,
            array_merge(
                [
                    'operation' => $context,
                    'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
                    'message' => $exception->getMessage(),
                ],
                $extraContext
            )
        );
    }

    private static function logRecordingAccessFailure(int $jobId, string $workerUid, string $errorCode): void
    {
        LocalSpeechToTextLogger::event(
            'api',
            'recording_access_failed',
            'Assigned recording could not be served',
            LOG_WARNING,
            [
                'job_id' => $jobId,
                'worker_uid' => $workerUid,
                'error_code' => $errorCode,
            ]
        );
    }
}
