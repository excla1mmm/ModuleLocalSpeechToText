<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\Users;
use MikoPBX\Core\System\Directories;
use Modules\ModuleLocalSpeechToText\App\Providers\MenuProvider;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTTranscriptEvent;

class CallRecordingService
{
    public function __construct(
        private mixed $translation,
        private mixed $url,
        private mixed $request,
        private mixed $response
    ) {
    }

    public function recordingCard(STTResult $result, ?STTJob $job, ?CallDetailRecords $cdr = null): array
    {
        $cdr = $cdr instanceof CallDetailRecords ? $cdr : ($job instanceof STTJob ? $this->cdrRecordForJob($job) : null);
        $playbackUrl = '';
        $downloadUrl = '';
        $safeFilePath = $this->recordingPathForPlayback($job, $cdr);
        if ($safeFilePath !== '') {
            $recordingUrl = MenuProvider::RECORDING_PATH . '?id=' . (int)$result->id;
            $playbackUrl = $this->url->get($recordingUrl);
            $downloadUrl = $this->url->get($recordingUrl . '&download=1');
        }
        $callTimestamp = $this->callTimestamp($result, $job, $cdr);
        $duration = $this->normalizedSecondsValue($result->duration ?? null);
        if ($duration <= 0 && $cdr instanceof CallDetailRecords) {
            $duration = (float)$cdr->duration;
        }
        $sourceNumber = $cdr instanceof CallDetailRecords ? $this->numberWithEmployeeName((string)$cdr->src_num) : '';
        $destinationNumber = $cdr instanceof CallDetailRecords ? $this->numberWithEmployeeName((string)$cdr->dst_num) : '';

        $rows = [
            [
                'label' => $this->translation->_('module_local_speech_to_text_CallDate'),
                'value' => $callTimestamp > 0 ? date('Y-m-d H:i:s', $callTimestamp) : '',
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_Duration'),
                'value' => $duration > 0 ? $this->durationLabel($duration) : '',
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_SourceNumber'),
                'value' => $sourceNumber,
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_DestinationNumber'),
                'value' => $destinationNumber,
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_TranscriptCreatedAt'),
                'value' => (int)$result->created_at > 0 ? date('Y-m-d H:i:s', (int)$result->created_at) : '',
            ],
            [
                'label' => $this->translation->_('module_local_speech_to_text_Model'),
                'value' => (string)$result->model_used,
            ],
        ];

        return [
            'hasPlayback' => $playbackUrl !== '',
            'playbackUrl' => $playbackUrl,
            'downloadUrl' => $downloadUrl,
            'durationSeconds' => $duration > 0 ? (string)$duration : '',
            'durationLabel' => $duration > 0 ? $this->durationLabel($duration) : '',
            'rows' => array_values(array_filter(
                $rows,
                static fn (array $row): bool => trim((string)$row['value']) !== ''
            )),
        ];
    }

    public function sendRecordingError(int $statusCode, string $message): void
    {
        $reason = match ($statusCode) {
            403 => 'Forbidden',
            404 => 'Not Found',
            default => 'Error',
        };

        $this->response->setStatusCode($statusCode, $reason);
        $this->response->setContentType('text/plain', 'UTF-8');
        $this->response->setContent($message);
    }

    public function streamRecordingFile(string $filePath, bool $download): void
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize <= 0) {
            $this->sendRecordingError(404, 'Recording not found');
            return;
        }

        $fileHandle = fopen($filePath, 'rb');
        if ($fileHandle === false) {
            $this->sendRecordingError(403, 'Recording not readable');
            return;
        }

        $start = 0;
        $end = $fileSize - 1;
        $statusCode = 200;
        $rangeHeader = (string)$this->request->getHeader('Range');
        if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches) === 1) {
            $rangeStart = $matches[1];
            $rangeEnd = $matches[2];

            if ($rangeStart === '' && $rangeEnd === '') {
                fclose($fileHandle);
                $this->sendInvalidRecordingRange($fileSize);
                return;
            }

            if ($rangeStart === '') {
                $suffixLength = (int)$rangeEnd;
                if ($suffixLength <= 0) {
                    fclose($fileHandle);
                    $this->sendInvalidRecordingRange($fileSize);
                    return;
                }
                $start = max(0, $fileSize - $suffixLength);
            } else {
                $start = (int)$rangeStart;
                $end = $rangeEnd === '' ? $end : (int)$rangeEnd;
            }

            if ($start > $end || $start >= $fileSize) {
                fclose($fileHandle);
                $this->sendInvalidRecordingRange($fileSize);
                return;
            }

            $end = min($end, $fileSize - 1);
            $statusCode = 206;
        }

        $length = $end - $start + 1;
        $fileName = basename($filePath);
        $safeFileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'recording';
        $disposition = $download ? 'attachment' : 'inline';

        $this->response->setStatusCode($statusCode, $statusCode === 206 ? 'Partial Content' : 'OK');
        $this->response->setHeader('Content-Type', $this->recordingMimeType($filePath));
        $this->response->setHeader('Accept-Ranges', 'bytes');
        $this->response->setHeader('Content-Length', (string)$length);
        $this->response->setHeader('Content-Disposition', $disposition . '; filename="' . $safeFileName . '"');
        if ($statusCode === 206) {
            $this->response->setHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $fileSize));
        }
        $this->response->sendHeaders();

        fseek($fileHandle, $start);
        $remainingBytes = $length;
        while ($remainingBytes > 0 && !feof($fileHandle)) {
            $chunk = fread($fileHandle, min(8192, $remainingBytes));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remainingBytes -= strlen($chunk);
            flush();
            if (connection_aborted()) {
                break;
            }
        }

        fclose($fileHandle);
    }

    private function sendInvalidRecordingRange(int $fileSize): void
    {
        $this->response->setStatusCode(416, 'Range Not Satisfiable');
        $this->response->setHeader('Content-Range', 'bytes */' . $fileSize);
        $this->response->setContentType('text/plain', 'UTF-8');
        $this->response->setContent('Invalid range');
    }

    private function recordingMimeType(string $filePath): string
    {
        return match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };
    }

    private function numberWithEmployeeName(string $number): string
    {
        $number = trim($number);
        if ($number === '') {
            return '';
        }

        $extension = $this->employeeExtensionByNumber($number);
        if (!$extension instanceof Extensions) {
            return $number;
        }

        $employeeName = '';
        $user = $extension->Users;
        if ($user instanceof Users) {
            $employeeName = trim((string)$user->username);
        }
        if ($employeeName === '') {
            $employeeName = trim((string)$extension->callerid);
        }

        return $employeeName === '' ? $number : sprintf('%s (%s)', $number, $employeeName);
    }

    private function employeeExtensionByNumber(string $number): ?Extensions
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }

        $extension = Extensions::findFirst([
            'conditions' => 'number = :number: AND userid IS NOT NULL AND userid > 0',
            'bind' => [
                'number' => $number,
            ],
            'order' => 'is_general_user_number DESC, id ASC',
        ]);

        return $extension instanceof Extensions ? $extension : null;
    }

    public function cdrRecordForJob(STTJob $job): ?CallDetailRecords
    {
        $callId = trim((string)$job->call_id);
        $jobFilePath = $this->safeMonitorRecordingPath((string)$job->file_path);
        $preferredRecord = null;

        if ($callId !== '') {
            $records = CallDetailRecords::find([
                'conditions' => '(linkedid = :call_id: OR UNIQUEID = :call_id:) AND recordingfile <> :empty:',
                'bind' => [
                    'call_id' => $callId,
                    'empty' => '',
                ],
                'order' => 'start ASC, id ASC',
            ]);

            foreach ($records as $record) {
                if (!$record instanceof CallDetailRecords) {
                    continue;
                }
                if (!$preferredRecord instanceof CallDetailRecords) {
                    $preferredRecord = $record;
                }

                $recordingPath = $this->cdrRecordingPath((string)$record->recordingfile);
                if ($jobFilePath !== '' && $recordingPath === $jobFilePath) {
                    return $record;
                }
            }
        }

        if ($preferredRecord instanceof CallDetailRecords) {
            return $preferredRecord;
        }

        return $this->cdrRecordByRecordingPath($jobFilePath);
    }

    public function cdrRecordForResult(STTResult $result): ?CallDetailRecords
    {
        $event = STTTranscriptEvent::findFirst([
            'conditions' => 'result_id = :result_id: AND event_type = :event_type:',
            'bind' => [
                'result_id' => (int)$result->id,
                'event_type' => STTTranscriptEvent::TYPE_TRANSCRIPT_COMPLETED,
            ],
            'order' => 'created_at DESC, id DESC',
        ]);
        $callId = $event instanceof STTTranscriptEvent ? trim((string)$event->call_id) : '';

        return $this->cdrRecordForCallId($callId);
    }

    public function cdrRecordById(int $cdrId): ?CallDetailRecords
    {
        if ($cdrId <= 0) {
            return null;
        }

        $record = CallDetailRecords::findFirst([
            'conditions' => 'id = :id:',
            'bind' => ['id' => $cdrId],
        ]);

        return $record instanceof CallDetailRecords ? $record : null;
    }

    public function cdrRecordForCallId(string $callId): ?CallDetailRecords
    {
        $callId = trim($callId);
        if ($callId === '') {
            return null;
        }

        $record = CallDetailRecords::findFirst([
            'conditions' => '(linkedid = :call_id: OR UNIQUEID = :call_id:) AND recordingfile <> :empty:',
            'bind' => [
                'call_id' => $callId,
                'empty' => '',
            ],
            'order' => 'start ASC, id ASC',
        ]);

        return $record instanceof CallDetailRecords ? $record : null;
    }

    private function cdrRecordByRecordingPath(string $jobFilePath): ?CallDetailRecords
    {
        if ($jobFilePath === '') {
            return null;
        }

        $records = CallDetailRecords::find([
            'conditions' => 'recordingfile <> :empty:',
            'bind' => [
                'empty' => '',
            ],
            'order' => 'start DESC, id DESC',
            'limit' => 500,
        ]);

        foreach ($records as $record) {
            if (!$record instanceof CallDetailRecords) {
                continue;
            }

            if ($this->cdrRecordingPath((string)$record->recordingfile) === $jobFilePath) {
                return $record;
            }
        }

        return null;
    }

    private function cdrRecordingPath(string $recordingFile): string
    {
        return $this->safeMonitorRecordingPath($recordingFile);
    }

    public function recordingPathForPlayback(?STTJob $job, ?CallDetailRecords $cdr): string
    {
        $prettyPath = $this->prettyRecordingPathForPlayback($job, $cdr);
        if ($prettyPath !== '') {
            return $prettyPath;
        }

        if ($job instanceof STTJob) {
            $jobPath = $this->safeMonitorRecordingPath((string)$job->file_path);
            if ($jobPath !== '') {
                return $jobPath;
            }
        }

        if ($cdr instanceof CallDetailRecords) {
            return $this->cdrRecordingPath((string)$cdr->recordingfile);
        }

        return '';
    }

    private function prettyRecordingPathForPlayback(?STTJob $job, ?CallDetailRecords $cdr): string
    {
        if (!$job instanceof STTJob) {
            return '';
        }

        $rawPath = $this->safeMonitorRecordingPath((string)$job->file_path);
        if ($rawPath === '') {
            return '';
        }

        $prettyBase = $this->prettyMonitorDir();
        if ($prettyBase === '') {
            return '';
        }

        $callId = preg_replace('/^mikopbx-/', '', trim((string)$job->call_id)) ?: '';
        $matches = [];
        foreach ($this->prettyMonitorSearchDirs($prettyBase, $job, $cdr, $rawPath) as $dir) {
            foreach ((glob($dir . '/*') ?: []) as $candidate) {
                if (!is_file($candidate) || realpath($candidate) !== $rawPath) {
                    continue;
                }

                $matches[] = $candidate;
            }
        }

        if ($matches === []) {
            return '';
        }

        usort(
            $matches,
            static fn (string $left, string $right): int => [
                $callId !== '' && str_contains(basename($left), $callId) ? 0 : 1,
                basename($left),
            ] <=> [
                $callId !== '' && str_contains(basename($right), $callId) ? 0 : 1,
                basename($right),
            ]
        );

        foreach ($matches as $match) {
            $safePath = $this->safePrettyRecordingPath($match, $rawPath);
            if ($safePath !== '') {
                return $safePath;
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function monitorRecordingCandidates(string $filePath): array
    {
        $filePath = trim($filePath);
        if ($filePath === '' || $filePath === '-') {
            return [];
        }

        if (str_starts_with($filePath, '/')) {
            return [$filePath];
        }

        $monitorDir = Directories::getDir(Directories::AST_MONITOR_DIR);

        return [
            $monitorDir . '/' . ltrim($filePath, '/'),
            $filePath,
        ];
    }

    private function safeMonitorRecordingPath(string $filePath): string
    {
        $allowedBase = realpath(Directories::getDir(Directories::AST_MONITOR_DIR));
        if ($allowedBase === false) {
            return '';
        }

        $allowedPrefix = rtrim($allowedBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($this->monitorRecordingCandidates($filePath) as $candidate) {
            $resolvedPath = realpath($candidate);
            if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
                continue;
            }

            if ($resolvedPath === $allowedBase || strncmp($resolvedPath, $allowedPrefix, strlen($allowedPrefix)) === 0) {
                return $resolvedPath;
            }
        }

        return '';
    }

    private function prettyMonitorDir(): string
    {
        $monitorDir = rtrim(Directories::getDir(Directories::AST_MONITOR_DIR), DIRECTORY_SEPARATOR);
        if ($monitorDir === '') {
            return '';
        }

        return dirname($monitorDir) . DIRECTORY_SEPARATOR . 'pretty-monitor';
    }

    /**
     * @return array<int, string>
     */
    private function prettyMonitorSearchDirs(string $prettyBase, STTJob $job, ?CallDetailRecords $cdr, string $rawPath): array
    {
        $dates = [];
        foreach ([
            $cdr instanceof CallDetailRecords ? strtotime((string)$cdr->start) : false,
            $cdr instanceof CallDetailRecords ? strtotime((string)$cdr->endtime) : false,
            (int)$job->cdr_endtime,
            self::fileModificationTime($rawPath),
        ] as $timestamp) {
            if (is_int($timestamp) && $timestamp > 0) {
                $dates[date('Y/m/d', $timestamp)] = true;
            }
        }

        $dirs = [];
        foreach (array_keys($dates) as $datePath) {
            $dir = $prettyBase . DIRECTORY_SEPARATOR . $datePath;
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
        }

        return array_values(array_unique($dirs));
    }

    private function safePrettyRecordingPath(string $filePath, string $expectedRawPath): string
    {
        $prettyBase = realpath($this->prettyMonitorDir());
        $prettyDir = realpath(dirname($filePath));
        $resolvedPath = realpath($filePath);
        if ($prettyBase === false || $prettyDir === false || $resolvedPath === false) {
            return '';
        }
        if ($resolvedPath !== $expectedRawPath || !is_file($filePath) || !is_readable($filePath)) {
            return '';
        }

        $prettyPrefix = rtrim($prettyBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($prettyDir !== $prettyBase && strncmp($prettyDir, $prettyPrefix, strlen($prettyPrefix)) !== 0) {
            return '';
        }

        return $filePath;
    }

    private function durationLabel(float $seconds): string
    {
        $totalSeconds = (int)round(max(0.0, $seconds));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $rest = $totalSeconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }

    public function callTimestamp(STTResult $result, ?STTJob $job, ?CallDetailRecords $cdr = null): int
    {
        // Priority matches TranscriptIntegrationService::callTimestamp() so the REST API
        // and this module's own web UI never disagree on the call date for the same call:
        // live CDR.start -> cdr_start_ms snapshot (captured at job creation, survives CDR
        // retention) -> a fresh CDR lookup -> CDR.endtime -> recording filename -> job/result
        // creation time as the last resort.
        if ($cdr instanceof CallDetailRecords) {
            $cdrTimestamp = strtotime((string)$cdr->start);
            if ($cdrTimestamp !== false && $cdrTimestamp > 0) {
                return $cdrTimestamp;
            }
        }

        if ($job instanceof STTJob) {
            if ((int)$job->cdr_start_ms > 0) {
                return intdiv((int)$job->cdr_start_ms, 1000);
            }

            $cdrTimestamp = $this->cdrCallStartTimestamp((string)$job->call_id);
            if ($cdrTimestamp > 0) {
                return $cdrTimestamp;
            }

            if ((int)$job->cdr_endtime > 0) {
                return (int)$job->cdr_endtime;
            }

            $recordingTimestamp = $this->recordingTimestamp((string)$job->file_path);
            if ($recordingTimestamp > 0) {
                return $recordingTimestamp;
            }

            $jobTimestamp = (int)$job->created_at;
            if ($jobTimestamp > 0) {
                return $jobTimestamp;
            }
        }

        return (int)$result->created_at;
    }

    public function cdrCallStartTimestamp(string $callId): int
    {
        $callId = trim($callId);
        if ($callId === '') {
            return 0;
        }

        $record = CallDetailRecords::findFirst([
            'conditions' => '(linkedid = :call_id: OR UNIQUEID = :call_id:) AND start <> :empty:',
            'bind' => [
                'call_id' => $callId,
                'empty' => '',
            ],
            'order' => 'start ASC, id ASC',
        ]);
        if (!$record instanceof CallDetailRecords) {
            return 0;
        }

        $timestamp = strtotime((string)$record->start);

        return $timestamp === false ? 0 : $timestamp;
    }

    public function recordingTimestamp(string $filePath): int
    {
        $filePath = trim($filePath);
        if ($filePath === '') {
            return 0;
        }

        $baseName = basename($filePath);
        if (preg_match('/(20\d{2})([01]\d)([0-3]\d)[-_ ]?([0-2]\d)([0-5]\d)([0-5]\d)/', $baseName, $matches) === 1) {
            $timestamp = strtotime(sprintf(
                '%s-%s-%s %s:%s:%s',
                $matches[1],
                $matches[2],
                $matches[3],
                $matches[4],
                $matches[5],
                $matches[6]
            ));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        if (preg_match('~/((?:20)\d{2})/([01]\d)/([0-3]\d)(?:/|$)~', $filePath, $matches) === 1) {
            $timestamp = strtotime(sprintf('%s-%s-%s 00:00:00', $matches[1], $matches[2], $matches[3]));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return self::fileModificationTime($filePath);
    }

    private function normalizedSecondsValue(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, (float)$value) : 0.0;
    }

    private static function fileModificationTime(string $filePath): int
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return 0;
        }

        clearstatcache(true, $filePath);
        $timestamp = filemtime($filePath);

        return is_int($timestamp) ? $timestamp : 0;
    }
}
