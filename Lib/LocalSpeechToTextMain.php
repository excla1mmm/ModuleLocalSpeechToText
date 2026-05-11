<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

use JsonException;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
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

        $apiKeyError = self::validateWorkerApiKey($data);
        if ($apiKeyError !== null) {
            return $apiKeyError;
        }

        $registrationBindingError = self::validateWorkerRegistrationBinding($workerUid, $data);
        if ($registrationBindingError !== null) {
            return $registrationBindingError;
        }

        try {
            $worker = STTWorker::registerWorker(
                $workerUid,
                self::stringField($data, 'name', $workerUid),
                self::currentWorkerModelName(),
                self::clientIp($data),
                self::requestApiKeyId($data)
            );

            return self::success([
                'worker_id' => (int)$worker->id,
                'poll_interval' => (int)STTSetting::getValue(STTSetting::POLL_INTERVAL_SEC, '30'),
            ]);
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

            $worker->touchOnline(self::clientIp($data));
            self::syncWorkerModel($worker);
            $job = STTJob::claimNextForWorker($worker);
            if ($job === null) {
                return self::success(['job' => null]);
            }

            return self::success(['job' => self::formatJob($job, $workerUid)]);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function downloadJobFile(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        if ($jobId <= 0 || $workerUid === '') {
            return self::error('validation_error', 'Job id and worker_uid are required', 422);
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

            $ownershipError = self::validateAssignedJob($job, $worker);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            $filePath = self::safeRecordingPath((string)$job->file_path);
            if ($filePath === null || !is_readable($filePath)) {
                return self::error('file_not_found', 'Recording file is not available', 404);
            }
            if (!self::fileWithinSizeLimit($filePath)) {
                return self::error('file_too_large', 'Recording file exceeds configured maximum size', 413);
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

        if ($jobId <= 0 || $workerUid === '' || !isset($data['transcript']) || !is_array($data['transcript'])) {
            return self::error('validation_error', 'job id, worker_uid and transcript are required', 422);
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
            $ownershipError = self::validateAssignedJob($job, $worker);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            $connection = $job->getWriteConnection();
            $connection->begin();
            try {
                $technicalInfo = is_array($data['technical_info'] ?? null) ? $data['technical_info'] : null;
                $duration = isset($data['duration']) ? self::normalizeSecondsValue($data['duration'], $technicalInfo) : null;
                $transcript = self::normalizeTranscriptSegments($data['transcript'], $duration);
                STTResult::saveForJob(
                    $job,
                    self::encodeTranscript($transcript),
                    self::nullableStringField($data, 'language'),
                    $duration,
                    self::nullableStringField($data, 'model_used'),
                    self::encodeTechnicalInfo($data['technical_info'] ?? null)
                );
                if (!$job->markDoneForWorker((int)$worker->id)) {
                    $connection->rollback();
                    return self::assignedJobConflict($jobId, $worker);
                }
                $connection->commit();
            } catch (Throwable $exception) {
                $connection->rollback();
                throw $exception;
            }

            return self::success();
        } catch (JsonException) {
            return self::error('validation_error', 'transcript must be valid JSON serializable data', 422);
        } catch (Throwable $exception) {
            self::logException(__METHOD__, $exception);
            return self::error('internal_error', 'Internal server error', 500);
        }
    }

    public static function submitError(array $data): PBXApiResult
    {
        $jobId = (int)($data['id'] ?? 0);
        $workerUid = self::stringField($data, 'worker_uid');
        if ($jobId <= 0 || $workerUid === '') {
            return self::error('validation_error', 'Job id and worker_uid are required', 422);
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
            $ownershipError = self::validateAssignedJob($job, $worker);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!$job->markFailedForWorker(
                (int)$worker->id,
                self::stringField($data, 'error_msg', 'Worker failed to process audio')
            )) {
                return self::assignedJobConflict($jobId, $worker);
            }

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
        if ($jobId <= 0 || $workerUid === '') {
            return self::error('validation_error', 'Job id and worker_uid are required', 422);
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
            $ownershipError = self::validateAssignedJob($job, $worker);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            if (!$job->refreshProcessingLeaseForWorker((int)$worker->id)) {
                return self::assignedJobConflict($jobId, $worker);
            }

            return self::success([
                'status' => (string)$job->status,
                'lease_refreshed_at' => (int)$job->started_at,
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
            'file_url' => self::API_BASE_PATH . '/jobs/' . $job->id . ':download?worker_uid=' . rawurlencode($workerUid),
            'duration_hint' => null,
            'language_hint' => STTSetting::selectedLanguageHint(),
            'model_id' => STTSetting::selectedModelId(),
            'model_repo' => STTSetting::selectedModelRepo(),
            'vocabulary_terms' => STTSetting::selectedVocabularyTerms(),
        ];
    }

    private static function currentWorkerModelName(): string
    {
        return STTSetting::selectedModelId();
    }

    private static function syncWorkerModel(STTWorker $worker): void
    {
        $currentModel = self::currentWorkerModelName();
        if ((string)$worker->model === $currentModel) {
            return;
        }

        $worker->model = $currentModel;
        if (!$worker->save()) {
            throw new RuntimeException(implode(', ', $worker->getMessages()));
        }
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

    private static function validateAssignedJob(STTJob $job, STTWorker $worker): ?PBXApiResult
    {
        if ((string)$job->status !== STTJob::STATUS_PROCESSING) {
            return self::error('job_not_processing', 'Job is not assigned for processing', 409);
        }

        if ((int)$job->worker_id !== (int)$worker->id) {
            return self::error('job_assigned_to_another_worker', 'Job is assigned to another worker', 403);
        }

        return null;
    }

    private static function assignedJobConflict(int $jobId, STTWorker $worker): PBXApiResult
    {
        $job = STTJob::findFirstById($jobId);
        if (!$job instanceof STTJob) {
            return self::error('job_not_found', "Job #$jobId not found", 404);
        }

        return self::validateAssignedJob($job, $worker)
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
        $fileSize = @filesize($filePath);

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
     * @param array<int, array<string, mixed>> $segments
     *
     * @throws JsonException
     */
    private static function encodeTranscript(array $segments): string
    {
        return json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function normalizeSecondsValue(mixed $value, ?array $technicalInfo = null): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float)$value;
        if ($number <= 0) {
            return null;
        }

        $shortRecordingThreshold = self::numericPath($technicalInfo, ['short_recording_threshold_seconds']);
        $isShortRecording = self::boolPath($technicalInfo, ['short_recording']);
        if ($isShortRecording && $shortRecordingThreshold !== null && $number > $shortRecordingThreshold * 100) {
            return $number / 1000;
        }

        if ($number > 10000) {
            return $number / 1000;
        }

        return $number;
    }

    /**
     * @param array<int, string> $path
     */
    private static function numericPath(?array $data, array $path): ?float
    {
        $value = self::valuePath($data, $path);

        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * @param array<int, string> $path
     */
    private static function boolPath(?array $data, array $path): bool
    {
        return self::valuePath($data, $path) === true;
    }

    /**
     * @param array<int, string> $path
     */
    private static function valuePath(?array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeTranscriptSegments(array $segments, ?float $duration): array
    {
        $scale = self::transcriptTimeScale($segments, $duration);
        $normalized = [];

        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            [$start, $end] = self::normalizeTranscriptRange(
                $segment['start'] ?? null,
                $segment['end'] ?? null,
                $scale,
                $duration
            );
            if (is_numeric($segment['start'] ?? null)) {
                $segment['start'] = self::roundSeconds($start);
            }
            if (is_numeric($segment['end'] ?? null)) {
                $segment['end'] = self::roundSeconds($end);
            }

            $normalized[] = $segment;
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $segments
     */
    private static function transcriptTimeScale(array $segments, ?float $duration): float
    {
        $maxTime = 0.0;
        $hasFractionalTime = false;
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }
            foreach (['start', 'end'] as $field) {
                if (is_numeric($segment[$field] ?? null)) {
                    $time = (float)$segment[$field];
                    $maxTime = max($maxTime, abs($time));
                    $hasFractionalTime = $hasFractionalTime || abs($time - round($time)) > 0.000001;
                }
            }
        }

        if ($maxTime <= 0) {
            return 1.0;
        }
        if ($hasFractionalTime || $maxTime <= 10) {
            return 1.0;
        }
        if ($maxTime <= 360000) {
            return 100.0;
        }

        if ($duration !== null && $duration > 0) {
            $bestScale = 1.0;
            $bestScore = PHP_FLOAT_MAX;
            foreach ([1.0, 10.0, 100.0, 1000.0] as $scale) {
                $candidate = $maxTime / $scale;
                $score = abs($candidate - $duration);
                if ($candidate > $duration * 1.2) {
                    $score += $duration;
                }
                if ($candidate < $duration * 0.01) {
                    $score += $duration;
                }
                if ($score < $bestScore) {
                    $bestScale = $scale;
                    $bestScore = $score;
                }
            }

            return $bestScale;
        }

        if ($maxTime > 360000) {
            return 1000.0;
        }
        if ($maxTime > 1000) {
            return 100.0;
        }

        return 1.0;
    }

    private static function normalizeTranscriptTime(
        mixed $value,
        float $scale,
        ?float $duration,
        ?float $minimum = null
    ): float {
        if (!is_numeric($value)) {
            return 0.0;
        }

        $number = (float)$value;
        $base = max(0.0, $number / max(1.0, $scale));
        $durationLimit = $duration !== null && $duration > 0
            ? $duration + max(2.0, $duration * 0.05)
            : null;

        if (($minimum === null || $base + 0.05 >= $minimum) && ($durationLimit === null || $base <= $durationLimit)) {
            return $base;
        }

        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ([1.0, 10.0, 100.0, 1000.0] as $candidateScale) {
            $candidate = max(0.0, $number / $candidateScale);
            if ($minimum !== null && $candidate + 0.05 < $minimum) {
                continue;
            }
            if ($durationLimit !== null && $candidate > $durationLimit) {
                continue;
            }

            $score = abs($candidate - $base);
            if ($minimum !== null) {
                $score += abs($candidate - $minimum) * 0.05;
            }
            if ($duration !== null && $duration > 0) {
                $score += abs($candidate - min($candidate, $duration)) * 0.5;
            }

            if ($score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best ?? $base;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function normalizeTranscriptRange(mixed $startValue, mixed $endValue, float $scale, ?float $duration): array
    {
        $start = self::normalizeTranscriptTime($startValue, $scale, $duration);
        $end = self::normalizeTranscriptTime($endValue, $scale, $duration, $start);
        if (!is_numeric($startValue) || !is_numeric($endValue)) {
            return [$start, $end];
        }

        $segmentDuration = $end - $start;
        if ($segmentDuration >= 0 && $segmentDuration <= self::maxTranscriptSegmentDuration($duration)) {
            return [$start, $end];
        }

        $durationLimit = $duration !== null && $duration > 0
            ? $duration + max(2.0, $duration * 0.05)
            : null;
        $bestStart = $start;
        $bestEnd = $end;
        $bestScore = PHP_FLOAT_MAX;
        $baseStart = max(0.0, (float)$startValue / max(1.0, $scale));
        $baseEnd = max(0.0, (float)$endValue / max(1.0, $scale));

        foreach ([0.01, 0.1, 1.0, 10.0, 100.0, 1000.0] as $startScale) {
            $candidateStart = max(0.0, (float)$startValue / $startScale);
            if ($durationLimit !== null && $candidateStart > $durationLimit) {
                continue;
            }

            foreach ([0.01, 0.1, 1.0, 10.0, 100.0, 1000.0] as $endScale) {
                $candidateEnd = max(0.0, (float)$endValue / $endScale);
                if ($durationLimit !== null && $candidateEnd > $durationLimit) {
                    continue;
                }
                if ($candidateEnd + 0.05 < $candidateStart) {
                    continue;
                }

                $candidateDuration = $candidateEnd - $candidateStart;
                $score = self::scaleDistance($startScale, $scale) + self::scaleDistance($endScale, $scale);
                $score += abs($candidateStart - $baseStart) * 0.01 + abs($candidateEnd - $baseEnd) * 0.01;
                if ($candidateDuration > self::maxTranscriptSegmentDuration($duration)) {
                    $score += ($candidateDuration - self::maxTranscriptSegmentDuration($duration)) * 10.0;
                }
                if ($duration !== null && $duration > 0) {
                    $score += abs($candidateEnd - min($candidateEnd, $duration)) * 0.5;
                }

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestStart = $candidateStart;
                    $bestEnd = $candidateEnd;
                }
            }
        }

        return [$bestStart, $bestEnd];
    }

    private static function maxTranscriptSegmentDuration(?float $duration): float
    {
        if ($duration !== null && $duration > 0) {
            return min(35.0, max(12.0, $duration * 0.25));
        }

        return 35.0;
    }

    private static function scaleDistance(float $candidateScale, float $baseScale): float
    {
        return abs(log($candidateScale / max(1.0, $baseScale), 10));
    }

    private static function roundSeconds(float $seconds): float
    {
        return round(max(0.0, $seconds), 3);
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
        SystemMessages::sysLogMsg(
            __CLASS__,
            sprintf('%s failed: %s', $context, $exception->getMessage()),
            LOG_ERR
        );
    }
}
