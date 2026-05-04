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

        try {
            $worker = STTWorker::registerWorker(
                $workerUid,
                self::stringField($data, 'name', $workerUid),
                self::stringField($data, 'model', 'unknown'),
                self::clientIp($data)
            );

            return self::success([
                'worker_id' => (int)$worker->id,
                'poll_interval' => (int)STTSetting::getValue(STTSetting::POLL_INTERVAL_SEC, '5'),
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

        try {
            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }

            $worker->touchOnline(self::clientIp($data));
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

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
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

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }

            $worker->touchOnline(self::clientIp($data));
            $ownershipError = self::validateAssignedJob($job, $worker);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            STTResult::saveForJob(
                $job,
                self::encodeTranscript($data['transcript']),
                self::nullableStringField($data, 'language'),
                isset($data['duration']) ? (float)$data['duration'] : null,
                self::nullableStringField($data, 'model_used')
            );
            $job->markDone();

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

        try {
            $job = STTJob::findFirstById($jobId);
            if ($job === null) {
                return self::error('job_not_found', "Job #$jobId not found", 404);
            }

            $worker = self::findWorker($workerUid);
            if ($worker === null) {
                return self::error('worker_not_registered', 'Worker is not registered', 404);
            }

            $worker->touchOnline(self::clientIp($data));
            $ownershipError = self::validateAssignedJob($job, $worker);
            if ($ownershipError !== null) {
                return $ownershipError;
            }

            $job->markFailed(self::stringField($data, 'error_msg', 'Worker failed to process audio'));

            return self::success([
                'status' => (string)$job->status,
                'attempts' => (int)$job->attempts,
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
            'language_hint' => STTSetting::getValue(STTSetting::DEFAULT_LANGUAGE, 'ru'),
            'model_id' => STTSetting::selectedModelId(),
            'model_repo' => STTSetting::selectedModelRepo(),
        ];
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

    private static function detectContentType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::AUDIO_CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }

    private static function clientIp(array $data): string
    {
        return self::stringField($data, '_remote_addr');
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
