<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use JsonException;
use InvalidArgumentException;
use MikoPBX\Core\System\Directories;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\AssignedJobConflictException;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\WorkerApiKeyBindingConflictException;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\STTWorkerResourceService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiContract;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerRegistrationTransaction;
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

    private const array AUDIO_CONTENT_TYPES = [
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'webm' => 'audio/webm',
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
    ];

    public static function registerWorker(array $data): PBXApiResult
    {
        $workerUid = self::stringField($data, 'worker_uid');
        if ($workerUid === '') {
            return self::error('validation_error', 'worker_uid is required', 422);
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/', $workerUid) !== 1) {
            return self::error('validation_error', 'worker_uid format is invalid', 422);
        }

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        $registrationBindingError = self::validateWorkerRegistrationBinding($workerUid, $data);
        if ($registrationBindingError !== null) {
            return $registrationBindingError;
        }

        try {
            $apiKeyId = self::requestApiKeyId($data);
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

            return self::success(STTWorkerResourceService::formatWorker($worker));
        } catch (WorkerApiKeyBindingConflictException $exception) {
            $result = self::error($exception->errorCode, $exception->getMessage(), 409);
            $result->data['error_code'] = $exception->errorCode;

            return $result;
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function getNextJob(array $data): PBXApiResult
    {
        $workerUid = self::stringField($data, 'worker_uid');
        if ($workerUid === '') {
            return self::error('validation_error', 'worker_uid is required', 422);
        }

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = self::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $hasSettingsChanged = $worker->hasProcessingSettingsChanged();
            $worker->touchOnline(self::clientIp($data));
            self::syncWorkerModel($worker);
            $job = STTJob::claimNextForWorker($worker);
            if ($job === null) {
                return self::success([
                    'job' => null,
                    'has_settings_changed' => $hasSettingsChanged,
                ]);
            }
            LocalSpeechToTextLogger::event(
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

            return self::success([
                'job' => self::formatJob($job, $workerUid),
                'has_settings_changed' => $hasSettingsChanged,
            ]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function getWorkerProcessingSettings(array $data): PBXApiResult
    {
        $workerUid = self::stringField($data, 'worker_uid');
        if ($workerUid === '') {
            return self::error('validation_error', 'worker_uid is required', 422);
        }

        $apiKeyError = self::requireBearerWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = self::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $worker->touchOnline(self::clientIp($data));
            $payload = (new WorkerProcessingSettingsService())->fetchForWorker($worker);

            return self::success($payload);
        } catch (InvalidArgumentException $exception) {
            return self::error('validation_error', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function downloadJobFile(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return self::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = self::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }
            $worker->touchOnline(self::clientIp($data));

            $ownershipError = self::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!STTSetting::recordingDurationAllowed(self::jobDurationSeconds($job))) {
                $message = 'Recording duration exceeds configured maximum';
                if (!$job->markTerminalErrorForWorker((int)$worker->id, $leaseToken, $message)) {
                    return self::assignedJobConflict($jobId, $worker, $leaseToken);
                }
                self::logRecordingAccessFailure($jobId, $workerUid, 'duration_too_long');

                return self::error('duration_too_long', $message, 413);
            }

            $filePath = self::safeRecordingPath((string)$job->file_path);
            if ($filePath === null || !is_readable($filePath)) {
                if (!$job->markTerminalErrorForWorker(
                    (int)$worker->id,
                    $leaseToken,
                    'Recording file is not available'
                )) {
                    return self::assignedJobConflict($jobId, $worker, $leaseToken);
                }
                self::logRecordingAccessFailure($jobId, $workerUid, 'recording_unavailable');

                return self::error('file_not_found', 'Recording file is not available', 404);
            }
            if (!self::fileWithinSizeLimit($filePath)) {
                if (!$job->markTerminalErrorForWorker(
                    (int)$worker->id,
                    $leaseToken,
                    'Recording file exceeds fixed maximum size'
                )) {
                    return self::assignedJobConflict($jobId, $worker, $leaseToken);
                }
                self::logRecordingAccessFailure($jobId, $workerUid, 'file_too_large');

                return self::error('file_too_large', 'Recording file exceeds fixed maximum size', 413);
            }

            return self::fileResponse($filePath);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function submitResult(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');

        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '' || !isset($data['transcript']) || !is_array($data['transcript'])) {
            return self::error('validation_error', 'job id, worker_uid, lease_token and transcript are required', 422);
        }

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = self::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $worker->touchOnline(self::clientIp($data));
            $ownershipError = self::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            $completionEventId = 0;
            $segmentCount = 0;
            $language = null;
            $model = null;
            $connection = $job->getWriteConnection();
            try {
                (new ModuleTransaction())->run(
                    $connection,
                    static function () use (
                        $data,
                        $job,
                        $worker,
                        $leaseToken,
                        &$completionEventId,
                        &$segmentCount,
                        &$language,
                        &$model
                    ): void {
                        $technicalInfo = is_array($data['technical_info'] ?? null)
                            ? $data['technical_info']
                            : null;
                        $duration = TranscriptTimeNormalizer::durationSecondsFromPayload($data);
                        $transcript = TranscriptTimeNormalizer::normalizeTranscriptSegments($data['transcript']);
                        $language = self::nullableStringField($data, 'language');
                        $filterLanguage = $language
                            ?? self::nullableStringField($data, 'language_hint')
                            ?? self::languageHintFromTechnicalInfo($technicalInfo);
                        $filterResult = TranscriptQualityFilter::filterSegments($transcript, $filterLanguage);
                        $transcript = $filterResult['segments'];
                        $technicalInfo = self::mergeTranscriptQualityInfo($technicalInfo, $filterResult['stats']);
                        $result = STTResult::saveForJob(
                            $job,
                            self::encodeTranscript($transcript),
                            $language,
                            $duration,
                            self::nullableStringField($data, 'model_used'),
                            self::encodeTechnicalInfo($technicalInfo)
                        );
                        $completionEvent = STTTranscriptEvent::ensureCompletedForResult($result, $job);
                        $completionEventId = (int)$completionEvent->id;
                        $segmentCount = count($transcript);
                        $model = self::nullableStringField($data, 'model_used');
                        if (!$job->markDoneForWorker((int)$worker->id, $leaseToken)) {
                            throw new AssignedJobConflictException('Assigned STT job changed during result submission');
                        }
                        (new CallTranscriptRevisionService())->recordCompletedPart($result, $job);
                    }
                );
            } catch (AssignedJobConflictException) {
                return self::assignedJobConflict($jobId, $worker, $leaseToken);
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

            return self::success();
        } catch (InvalidArgumentException | JsonException $exception) {
            return self::error('validation_error', $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function submitError(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return self::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = self::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $worker->touchOnline(self::clientIp($data));
            $ownershipError = self::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!$job->markFailedForWorker(
                (int)$worker->id,
                $leaseToken,
                self::stringField($data, 'error_msg', 'Worker failed to process audio')
            )) {
                return self::assignedJobConflict($jobId, $worker, $leaseToken);
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

            return self::success([
                'status' => (string)$job->status,
                'attempts' => (int)$job->attempts,
            ]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function heartbeatJob(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return self::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = self::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $worker->touchOnline(self::clientIp($data));
            $ownershipError = self::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!$job->refreshProcessingLeaseForWorker((int)$worker->id, $leaseToken)) {
                return self::assignedJobConflict($jobId, $worker, $leaseToken);
            }

            return self::success([
                'status' => (string)$job->status,
                'lease_expires_at' => (int)$job->lease_expires_at,
            ]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function releaseJob(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        $leaseToken = self::stringField($data, 'lease_token');
        if ($jobId <= 0 || $workerUid === '' || $leaseToken === '') {
            return self::error('validation_error', 'Job id, worker_uid and lease_token are required', 422);
        }

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }
            $bindingError = self::validateWorkerApiKeyBinding($worker, $data);
            if ($bindingError !== null) {
                return $bindingError;
            }

            $worker->touchOnline(self::clientIp($data));
            $ownershipError = self::validateAssignedJob($job, $worker, $leaseToken);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!$job->releaseForWorker((int)$worker->id, $leaseToken)) {
                return self::assignedJobConflict($jobId, $worker, $leaseToken);
            }
            LocalSpeechToTextLogger::event(
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

            return self::success([
                'status' => (string)$job->status,
            ]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    private static function formatJob(STTJob $job, string $workerUid): array
    {
        return [
            'id' => (int)$job->id,
            'call_id' => (string)$job->call_id,
            'file_url' => '/job-recordings/'.(int)$job->id,
            'lease_token' => (string)$job->lease_token,
            'lease_expires_at' => (int)$job->lease_expires_at,
            'duration_hint' => self::jobDurationSeconds($job),
            'language_hint' => STTSetting::selectedLanguageHint(),
            'model_id' => STTSetting::selectedModelRuntimeId(),
            'model_repo' => STTSetting::selectedModelRepo(),
            'model_engine' => STTSetting::selectedModelEngine(),
            'model_artifact_type' => STTSetting::selectedModelArtifactType(),
            'whisperkit_model' => STTSetting::selectedModelRuntimeId(),
            'model_display_name' => STTSetting::selectedModelDisplayName(),
            'model_source_url' => STTSetting::selectedModelSourceUrl(),
            'vocabulary_terms' => STTSetting::selectedVocabularyTerms(),
        ];
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

    private static function findWorker(string $workerUid): ?STTWorker
    {
        $worker = STTWorker::findFirst([
            'conditions' => 'worker_uid = :worker_uid:',
            'bind' => [
                'worker_uid' => $workerUid,
            ],
        ]);

        return $worker instanceof STTWorker ? $worker : null;
    }

    private static function validateWorkerApiKey(array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return null;
        }

        $apiKeyId = self::requestApiKeyId($data) ?? 0;
        if ($apiKeyId <= 0) {
            return self::error('worker_api_key_required', 'Worker REST API key is required', 403);
        }

        $workerApiKeyIds = STTSetting::workerApiKeyIds();
        if ($workerApiKeyIds === []) {
            return self::error('worker_api_key_not_configured', 'Worker REST API key is not configured', 403);
        }

        if (!in_array($apiKeyId, $workerApiKeyIds, true)) {
            return self::error('worker_api_key_forbidden', 'This API key is not assigned to Local Speech To Text workers', 403);
        }

        return null;
    }

    private static function requireBearerWorkerApiKey(array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return self::error('worker_api_key_required', 'Worker REST API key is required', 403);
        }

        return self::validateWorkerApiKey($data);
    }

    private static function validateWorkerRegistrationBinding(string $workerUid, array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return null;
        }

        $worker = self::findWorker($workerUid);
        $requestApiKeyId = self::requestApiKeyId($data) ?? 0;
        if (!$worker instanceof STTWorker) {
            return self::validateWorkerApiKeyExclusiveBinding($workerUid, $requestApiKeyId);
        }

        $boundApiKeyId = (int)$worker->api_key_id;
        if ($boundApiKeyId <= 0 || $boundApiKeyId === $requestApiKeyId) {
            return self::validateWorkerApiKeyExclusiveBinding($workerUid, $requestApiKeyId);
        }

        if (!STTSetting::isWorkerApiKeyIdAllowed($boundApiKeyId)) {
            return self::validateWorkerApiKeyExclusiveBinding($workerUid, $requestApiKeyId);
        }

        return self::error('worker_api_key_mismatch', 'Worker is bound to another API key', 403);
    }

    private static function validateWorkerApiKeyExclusiveBinding(string $workerUid, int $apiKeyId): ?PBXApiResult
    {
        if ($apiKeyId <= 0) {
            return null;
        }

        $worker = STTWorker::findFirst([
            'conditions' => 'api_key_id = :api_key_id: AND worker_uid <> :worker_uid:',
            'bind' => [
                'api_key_id' => $apiKeyId,
                'worker_uid' => $workerUid,
            ],
        ]);
        if ($worker instanceof STTWorker) {
            return self::error('worker_api_key_already_bound', 'This API key is already bound to another worker', 409);
        }

        return null;
    }

    private static function validateWorkerApiKeyBinding(STTWorker $worker, array $data): ?PBXApiResult
    {
        if (!self::isBearerAuthenticatedRequest($data)) {
            return null;
        }

        $boundApiKeyId = (int)$worker->api_key_id;
        if ($boundApiKeyId <= 0) {
            return self::error('worker_api_key_not_bound', 'Worker must be registered with the configured API key', 403);
        }

        if ($boundApiKeyId !== self::requestApiKeyId($data)) {
            return self::error('worker_api_key_mismatch', 'Worker is bound to another API key', 403);
        }

        return null;
    }

    private static function validateAssignedJob(STTJob $job, STTWorker $worker, string $leaseToken): ?PBXApiResult
    {
        if ((string)$job->status !== STTJob::STATUS_PROCESSING) {
            return self::error('job_not_processing', 'Job is not assigned for processing', 409);
        }

        if ((int)$job->worker_id !== (int)$worker->id) {
            return self::error('job_assigned_to_another_worker', 'Job is assigned to another worker', 403);
        }

        if ((string)$job->lease_token === '' || !hash_equals((string)$job->lease_token, $leaseToken)) {
            return self::error('job_lease_mismatch', 'Job lease token is invalid or expired', 409);
        }

        if ((int)$job->lease_expires_at > 0 && (int)$job->lease_expires_at < time()) {
            STTJob::requeueStaleProcessing();
            return self::error('job_lease_expired', 'Job lease has expired', 409);
        }

        return null;
    }

    private static function assignedJobConflict(int $jobId, STTWorker $worker, string $leaseToken): PBXApiResult
    {
        $job = STTJob::findFirstById($jobId);
        if (!$job instanceof STTJob) {
            return self::error('job_not_found', "Job #$jobId not found", 404);
        }

        return self::validateAssignedJob($job, $worker, $leaseToken)
            ?? self::error('job_update_conflict', 'Job state changed while processing request', 409);
    }

    private static function safeRecordingPath(string $filePath): ?string
    {
        $allowedBase = realpath(Directories::getDir(Directories::AST_MONITOR_DIR));
        $resolvedPath = realpath($filePath);

        if ($allowedBase === false || $resolvedPath === false) {
            return null;
        }

        $allowedPrefix = rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolvedPath !== $allowedBase && strncmp($resolvedPath, $allowedPrefix, strlen($allowedPrefix)) !== 0) {
            return null;
        }

        return $resolvedPath;
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

    private static function fileResponse(string $filePath): PBXApiResult
    {
        $result = self::success([
            'fpassthru' => [
                'filename' => $filePath,
                'need_delete' => false,
                'content_type' => self::detectContentType($filePath),
                'download_name' => basename($filePath),
                'additional_headers' => [
                    'X-Accel-Buffering' => 'no',
                ],
            ],
        ]);

        return $result;
    }

    private static function success(array $data = [], int $httpCode = 200): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = true;
        $result->data = $data;
        $result->httpCode = $httpCode;

        return $result;
    }

    private static function error(string $code, string $message, int $httpCode): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->data = ['error' => $code];
        $result->messages['error'][] = $message;
        $result->httpCode = $httpCode;

        return $result;
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

    /**
     * @param array<string, mixed>|null $technicalInfo
     */
    private static function languageHintFromTechnicalInfo(?array $technicalInfo): ?string
    {
        if (!is_array($technicalInfo)) {
            return null;
        }

        $model = is_array($technicalInfo['model'] ?? null) ? $technicalInfo['model'] : [];
        foreach ([$model['language_hint'] ?? null, $model['language'] ?? null] as $value) {
            if (is_scalar($value)) {
                $language = trim((string)$value);
                if ($language !== '') {
                    return $language;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @throws JsonException
     */
    private static function encodeTranscript(array $segments): string
    {
        return json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null $technicalInfo
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private static function mergeTranscriptQualityInfo(?array $technicalInfo, array $stats): array
    {
        $technicalInfo ??= [];
        $quality = is_array($technicalInfo['quality'] ?? null) ? $technicalInfo['quality'] : [];
        $existingReasons = is_array($quality['reasons'] ?? null) ? $quality['reasons'] : [];
        $serverReasons = is_array($stats['reasons'] ?? null) ? $stats['reasons'] : [];

        $quality['server_filter'] = true;
        $quality['server_raw_segments'] = (int)($stats['raw_segments'] ?? 0);
        $quality['server_submitted_segments'] = (int)($stats['submitted_segments'] ?? 0);
        $quality['server_rejected_segments'] = (int)($stats['rejected_segments'] ?? 0);
        $quality['server_trimmed_segments'] = (int)($stats['trimmed_segments'] ?? 0);
        $quality['rejected_segments'] = (int)($quality['rejected_segments'] ?? 0) + (int)($stats['rejected_segments'] ?? 0);
        $quality['trimmed_segments'] = (int)($quality['trimmed_segments'] ?? 0) + (int)($stats['trimmed_segments'] ?? 0);
        $quality['reasons'] = array_values(array_unique(array_merge(
            array_map('strval', $existingReasons),
            array_map('strval', $serverReasons)
        )));

        $technicalInfo['quality'] = $quality;
        $technicalInfo['server_quality'] = [
            'transcript_filter' => true,
            'raw_segments' => (int)($stats['raw_segments'] ?? 0),
            'submitted_segments' => (int)($stats['submitted_segments'] ?? 0),
            'rejected_segments' => (int)($stats['rejected_segments'] ?? 0),
            'trimmed_segments' => (int)($stats['trimmed_segments'] ?? 0),
            'reasons' => $serverReasons,
        ];

        return $technicalInfo;
    }

    /**
     * @throws JsonException
     */
    private static function encodeTechnicalInfo(mixed $technicalInfo): ?string
    {
        if (!is_array($technicalInfo) || $technicalInfo === []) {
            return null;
        }

        return json_encode($technicalInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function detectContentType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::AUDIO_CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }

    private static function clientIp(array $data): string
    {
        return self::stringField($data, '_remote_addr');
    }

    private static function isBearerAuthenticatedRequest(array $data): bool
    {
        return self::stringField($data, '_auth_type') === 'bearer_token';
    }

    private static function requestApiKeyId(array $data): ?int
    {
        $apiKeyId = (int)($data['_api_key_id'] ?? 0);

        return $apiKeyId > 0 ? $apiKeyId : null;
    }

    private static function logException(string $context, Throwable $exception): void
    {
        LocalSpeechToTextLogger::event(
            'api',
            'operation_failed',
            'STT API operation failed',
            LOG_ERR,
            [
                'operation' => $context,
                'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
            ]
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
