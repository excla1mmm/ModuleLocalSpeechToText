<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Core\System\Directories;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * Per-CDR-record decision helpers shared by CDR discovery and delayed/skipped
 * recording retry: identity extraction, history-window/eligibility checks,
 * recording path validation, duration probing, priority, and job creation.
 *
 * Extracted verbatim from bin/WorkerLocalSpeechToTextJobs.php (moved, not
 * rewritten) so CdrDiscoveryScanner and RecordingRetryProcessor can share one
 * implementation instead of duplicating it.
 */
final class CdrRecordEvaluator
{
    private const int LONG_CALL_SECONDS = 1800;

    private ?RecordingDurationProbe $recordingDurationProbe = null;

    /**
     * @param array<string, mixed> $record
     */
    public function recordId(array $record): int
    {
        return (int)($record['id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $record
     */
    public function endtime(array $record): string
    {
        return trim((string)($record['endtime'] ?? ''));
    }

    /**
     * @param array<string, mixed> $record
     */
    public function recordEndtimeTimestamp(array $record): ?int
    {
        $endtime = $this->endtime($record);
        if ($endtime === '') {
            return null;
        }

        $timestamp = strtotime($endtime);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function recordWithinHistoryWindow(array $record, string $historyStart): bool
    {
        if ($historyStart === '') {
            return true;
        }

        $endtime = $this->endtime($record);
        if ($endtime === '') {
            return true;
        }

        return $endtime >= $historyStart;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function callId(array $record): string
    {
        $linkedId = trim((string)($record['linkedid'] ?? ''));
        if ($linkedId !== '') {
            return $linkedId;
        }

        return trim((string)($record['UNIQUEID'] ?? ''));
    }

    /**
     * @param array<string, mixed> $record
     * @return array{cdr_id: int|null, cdr_uniqueid: string, cdr_start_ms: int|null, cdr_end_ms: int|null, recording_fingerprint: string}
     */
    public function recordingIdentity(array $record, string $recordingPath): array
    {
        $cdrId = (int)($record['cdr_id'] ?? $record['id'] ?? 0);
        $uniqueId = trim((string)($record['cdr_uniqueid'] ?? $record['UNIQUEID'] ?? ''));
        $startMs = (int)($record['cdr_start_ms'] ?? 0) ?: $this->dateTimeMilliseconds((string)($record['start'] ?? ''));
        $endMs = (int)($record['cdr_end_ms'] ?? 0) ?: $this->dateTimeMilliseconds((string)($record['endtime'] ?? ''));
        $size = is_file($recordingPath) ? (int)filesize($recordingPath) : 0;
        $modifiedAt = is_file($recordingPath) ? (int)filemtime($recordingPath) : 0;
        $fingerprintMaterial = implode('|', [
            (string)$cdrId,
            $uniqueId,
            (string)($startMs ?? 0),
            (string)($endMs ?? 0),
            (string)$size,
            (string)$modifiedAt,
        ]);

        return [
            'cdr_id' => $cdrId > 0 ? $cdrId : null,
            'cdr_uniqueid' => $uniqueId,
            'cdr_start_ms' => $startMs,
            'cdr_end_ms' => $endMs,
            'recording_fingerprint' => hash('sha256', $fingerprintMaterial),
        ];
    }

    public function dateTimeMilliseconds(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        return $date instanceof \DateTimeImmutable
            ? ((int)$date->format('U') * 1000) + (int)substr($date->format('u'), 0, 3)
            : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function priority(array $record): int
    {
        return $this->priorityForDuration($this->recordingDurationSeconds($record));
    }

    public function priorityForDuration(?int $duration): int
    {
        if ($duration !== null && $duration >= self::LONG_CALL_SECONDS) {
            return 8;
        }

        return 5;
    }

    public function recordingPath(string $recordingFile): ?string
    {
        $recordingFile = trim($recordingFile);
        if ($recordingFile === '' || $recordingFile === '-') {
            return null;
        }

        $monitorDir = Directories::getDir(Directories::AST_MONITOR_DIR);
        $candidate = str_starts_with($recordingFile, '/')
            ? $recordingFile
            : $monitorDir . '/' . ltrim($recordingFile, '/');

        $allowedBase = realpath($monitorDir);
        $resolvedPath = realpath($candidate);
        if ($allowedBase === false || $resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            return null;
        }

        $allowedPrefix = rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolvedPath !== $allowedBase && strncmp($resolvedPath, $allowedPrefix, strlen($allowedPrefix)) !== 0) {
            return null;
        }

        return $resolvedPath;
    }

    public function recordingWithinSizeLimit(string $recordingPath): bool
    {
        if (!is_file($recordingPath) || !is_readable($recordingPath)) {
            return false;
        }

        clearstatcache(true, $recordingPath);
        $fileSize = filesize($recordingPath);

        return $fileSize !== false && $fileSize <= STTSetting::maxFileSizeBytes();
    }

    /**
     * @param array<string, mixed> $record
     */
    public function recordingDurationSeconds(array $record): ?int
    {
        return STTSetting::normalizeRecordingDurationSeconds(
            $record['recording_duration_sec'] ?? $record['duration'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array{state: 'allowed'|'too_long'|'probe_failed', duration_sec: int|null, source: string}
     */
    public function recordingDurationDecision(array $record, string $recordingPath): array
    {
        $durationSeconds = $this->recordingDurationSeconds($record);
        $source = trim((string)($record['recording_duration_source'] ?? ''));
        if ($source === 'media_probe') {
            if ($durationSeconds === null) {
                return [
                    'state' => 'probe_failed',
                    'duration_sec' => null,
                    'source' => '',
                ];
            }
            return [
                'state' => STTSetting::recordingDurationAllowed($durationSeconds) ? 'allowed' : 'too_long',
                'duration_sec' => $durationSeconds,
                'source' => $source,
            ];
        }
        if ($durationSeconds !== null && STTSetting::recordingDurationAllowed($durationSeconds)) {
            return [
                'state' => 'allowed',
                'duration_sec' => $durationSeconds,
                'source' => $durationSeconds === null ? '' : 'cdr',
            ];
        }

        $probe = $this->recordingDurationProbe()->probe($recordingPath);
        if ($probe['status'] !== RecordingDurationProbe::STATUS_OK || $probe['duration_sec'] === null) {
            return [
                'state' => 'probe_failed',
                'duration_sec' => $durationSeconds,
                'source' => '',
            ];
        }

        return [
            'state' => STTSetting::recordingDurationAllowed($probe['duration_sec']) ? 'allowed' : 'too_long',
            'duration_sec' => $probe['duration_sec'],
            'source' => 'media_probe',
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array{state: string, duration_sec: int|null, source: string} $duration
     * @return array<string, mixed>
     */
    public function recordWithDurationDecision(array $record, array $duration): array
    {
        $record['recording_duration_sec'] = $duration['duration_sec'];
        $record['recording_duration_source'] = $duration['source'];

        return $record;
    }

    public function recordingDurationProbe(): RecordingDurationProbe
    {
        if (!$this->recordingDurationProbe instanceof RecordingDurationProbe) {
            $this->recordingDurationProbe = new RecordingDurationProbe();
        }

        return $this->recordingDurationProbe;
    }

    public function logJobCreated(STTJob $job, ?int $recordingDurationSeconds, ?string $reason = null): void
    {
        LocalSpeechToTextLogger::event(
            'queue',
            'job_created',
            'Transcription job created',
            LOG_INFO,
            [
                'job_id' => (int)$job->id,
                'recording_duration_ms' => $recordingDurationSeconds !== null
                    ? $recordingDurationSeconds * 1000
                    : null,
                'skip_reason' => $reason,
            ]
        );
    }

    /**
     * @param array<string, mixed> $record
     */
    public function createJob(array $record, string $recordingPath, ?int $recordingDurationSeconds): void
    {
        $job = STTJob::createPending(
            $this->callId($record),
            $recordingPath,
            $this->priorityForDuration($recordingDurationSeconds),
            $this->recordEndtimeTimestamp($record),
            $this->recordingIdentity($record, $recordingPath),
            $recordingDurationSeconds
        );

        $this->logJobCreated($job, $recordingDurationSeconds);
    }
}
