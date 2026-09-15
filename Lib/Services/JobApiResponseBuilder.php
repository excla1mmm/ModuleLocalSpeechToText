<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\PBXCoreREST\Lib\PBXApiResult;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;

/**
 * Builds the PBXApiResult responses LocalSpeechToTextMain's worker-facing
 * endpoints return: plain success/error envelopes, the recording
 * file-passthrough response, and the shared "did the job's ownership change
 * while we were handling this request" conflict response.
 */
final class JobApiResponseBuilder
{
    private const array AUDIO_CONTENT_TYPES = [
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'webm' => 'audio/webm',
        'wav' => 'audio/wav',
        'mp3' => 'audio/mpeg',
    ];

    public static function success(array $data = [], int $httpCode = 200): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = true;
        $result->data = $data;
        $result->httpCode = $httpCode;

        return $result;
    }

    public static function error(string $code, string $message, int $httpCode): PBXApiResult
    {
        $result = new PBXApiResult();
        $result->success = false;
        $result->data = ['error' => $code];
        $result->messages['error'][] = $message;
        $result->httpCode = $httpCode;

        return $result;
    }

    public static function fileResponse(string $filePath): PBXApiResult
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

    /**
     * Re-checks job ownership to explain why a mutation that required
     * ownership (markDoneForWorker/markFailedForWorker/
     * refreshProcessingLeaseForWorker/releaseForWorker/
     * markTerminalErrorForWorker) failed its compare-and-swap: the job
     * either no longer exists, or WorkerAuthorizationValidator::
     * validateAssignedJob() can point at the specific ownership rule that
     * now fails (status/worker/lease-token/lease-expiry). If none of those
     * explain it, ownership was technically still valid at read time and
     * the CAS lost a race - reported as a generic conflict.
     */
    public static function assignedJobConflict(int $jobId, STTWorker $worker, string $leaseToken): PBXApiResult
    {
        $job = STTJob::findFirstById($jobId);
        if (!$job instanceof STTJob) {
            return self::error('job_not_found', "Job #$jobId not found", 404);
        }

        return WorkerAuthorizationValidator::validateAssignedJob($job, $worker, $leaseToken)
            ?? self::error('job_update_conflict', 'Job state changed while processing request', 409);
    }

    private static function detectContentType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::AUDIO_CONTENT_TYPES[$extension] ?? 'application/octet-stream';
    }
}
