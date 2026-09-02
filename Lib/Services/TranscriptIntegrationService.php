<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Directories;
use Modules\ModuleLocalSpeechToText\Lib\TranscriptTimeNormalizer;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTTranscriptEvent;
use RuntimeException;

class TranscriptIntegrationService
{
    private const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 200;
    private const int TURN_MAX_GAP_MS = 2000;
    private const int TURN_MAX_DURATION_MS = 45000;
    private const int TURN_MAX_TEXT_CHARS = 700;

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listCompletedTranscripts(array $filters): array
    {
        $limit = $this->limit($filters['limit'] ?? null);
        $offset = max(0, (int)($filters['offset'] ?? 0));
        [$dateFrom, $dateTo] = $this->dateRange($filters);
        $callTimestampSql = 'COALESCE(NULLIF(CAST(j.cdr_endtime AS INTEGER), 0), CAST(r.created_at AS INTEGER))';

        $where = [];
        $bind = [];
        if ($dateFrom !== null) {
            $where[] = "{$callTimestampSql} >= {$dateFrom}";
        }
        if ($dateTo !== null) {
            $where[] = "{$callTimestampSql} <= {$dateTo}";
        }

        $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);
        $connection = (new STTResult())->getReadConnection();
        $countRows = $connection->fetchAll(
            "SELECT COUNT(r.id) AS total
               FROM m_STTResult r
          LEFT JOIN m_STTJob j ON j.id = r.job_id
              WHERE {$whereSql}",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            $bind
        );
        $total = (int)($countRows[0]['total'] ?? 0);

        $rows = $connection->fetchAll(
            "SELECT r.id AS result_id
                  FROM m_STTResult r
             LEFT JOIN m_STTJob j ON j.id = r.job_id
                 WHERE {$whereSql}
              ORDER BY {$callTimestampSql} DESC, r.id DESC
                 LIMIT {$limit} OFFSET {$offset}",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            $bind
        );

        $items = [];
        foreach ($rows as $row) {
            $result = STTResult::findFirstById((int)($row['result_id'] ?? 0));
            if ($result instanceof STTResult) {
                $items[] = $this->summary($result);
            }
        }

        return [
            'items' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => $total,
                'has_more' => $offset + count($items) < $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transcriptDetail(int $resultId): array
    {
        $result = STTResult::findFirstById($resultId);
        if (!$result instanceof STTResult) {
            throw new RuntimeException('Transcript result not found');
        }

        $summary = $this->summary($result);
        $segments = $this->segments($result);
        $turns = $this->turns($result, $segments);

        $transcript = (new TranscriptPublicProjection())->project([
            'segments' => $segments,
            'segment_count' => count($segments),
            'turns' => $turns,
            'turn_count' => count($turns),
            'text' => $this->plainText($turns),
        ]);

        return $summary + [
            'technical_info' => $this->technicalInfo($result),
            'transcript' => $transcript,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalInfo(STTResult $result): array
    {
        $decoded = json_decode((string)$result->technical_info, true);
        if (!is_array($decoded)) {
            return [];
        }
        if ($this->isListPayload($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function completedTranscriptEvents(array $filters): array
    {
        $limit = $this->limit($filters['limit'] ?? null);
        [$cursorCreatedAt, $cursorEventId] = $this->decodeCursor((string)($filters['cursor'] ?? ''));

        $connection = (new STTTranscriptEvent())->getReadConnection();
        $rows = $connection->fetchAll(
            "SELECT e.id AS event_id,
                    e.event_type AS event_type,
                    e.result_id AS result_id,
                    e.job_id AS job_id,
                    e.call_id AS call_id,
                    e.update_type AS update_type,
                    e.created_at AS created_at
               FROM m_STTTranscriptEvent e
              WHERE (
                    CAST(e.created_at AS INTEGER) > :cursor_created_at
                 OR (
                        CAST(e.created_at AS INTEGER) = :cursor_created_at
                    AND CAST(e.id AS INTEGER) > :cursor_event_id
                 )
              )
           ORDER BY CAST(e.created_at AS INTEGER) ASC, CAST(e.id AS INTEGER) ASC
              LIMIT {$limit}",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [
                'cursor_created_at' => $cursorCreatedAt,
                'cursor_event_id' => $cursorEventId,
            ]
        );

        $events = [];
        $nextCursor = $this->encodeCursor($cursorCreatedAt, $cursorEventId);
        foreach ($rows as $row) {
            $createdAt = (int)($row['created_at'] ?? 0);
            $eventId = (int)($row['event_id'] ?? 0);
            $resultId = (int)($row['result_id'] ?? 0);
            $events[] = [
                'cursor' => $this->encodeCursor($createdAt, $eventId),
                'event_type' => (string)($row['event_type'] ?? STTTranscriptEvent::TYPE_TRANSCRIPT_COMPLETED),
                'result_id' => $resultId,
                'job_id' => isset($row['job_id']) ? (int)$row['job_id'] : null,
                'call_id' => (string)($row['call_id'] ?? ''),
                'created_at' => $createdAt,
                'update_type' => (string)($row['update_type'] ?? STTTranscriptEvent::UPDATE_CREATED),
            ];
            $nextCursor = $this->encodeCursor($createdAt, $eventId);
        }

        return [
            'events' => $events,
            'next_cursor' => $nextCursor,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(STTResult $result): array
    {
        $job = $this->job($result);
        $event = $this->event($result);
        $callId = $this->callId($job, $event);
        $cdr = $job instanceof STTJob ? $this->cdrRecordForJob($job) : $this->cdrRecordForCallId($callId);
        $callTimestamp = $this->callTimestamp($result, $job, $cdr);
        $recording = $this->recordingInfo($job, $cdr);

        return [
            'result_id' => (int)$result->id,
            'job_id' => $this->jobId($job, $event),
            'call_id' => $callId,
            'cdr_id' => $cdr instanceof CallDetailRecords ? (int)$cdr->id : null,
            'cdr_endtime' => $this->cdrEndtime($job, $cdr),
            'cdr_start_ms' => $job instanceof STTJob && (int)$job->cdr_start_ms > 0 ? (int)$job->cdr_start_ms : null,
            'call_timestamp' => $callTimestamp,
            'finished_at' => $job instanceof STTJob ? (int)$job->finished_at : null,
            'result_created_at' => (int)$result->created_at,
            'duration' => $result->duration === null ? null : (float)$result->duration,
            'language' => (string)$result->language,
            'model_used' => (string)$result->model_used,
            'recording' => $recording,
            'call' => $this->callMetadata($cdr),
            'pbx_timezone' => PbxSettings::getValueByKey(PbxSettings::PBX_TIMEZONE),
        ];
    }

    private function job(STTResult $result): ?STTJob
    {
        $job = $result->STTJob;

        return $job instanceof STTJob ? $job : null;
    }

    /**
     * @param array<mixed> $value
     */
    private function isListPayload(array $value): bool
    {
        return $value !== [] && array_keys($value) === range(0, count($value) - 1);
    }

    private function jobId(?STTJob $job, ?STTTranscriptEvent $event): ?int
    {
        if ($job instanceof STTJob) {
            return (int)$job->id;
        }
        if ($event instanceof STTTranscriptEvent && (int)($event->job_id ?? 0) > 0) {
            return (int)$event->job_id;
        }

        return null;
    }

    private function event(STTResult $result): ?STTTranscriptEvent
    {
        $rows = (new STTTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT id, event_type, event_key, result_id, job_id, call_id, update_type, payload, created_at
               FROM m_STTTranscriptEvent
              WHERE CAST(result_id AS INTEGER) = :result_id
                AND event_type = :event_type
              ORDER BY CAST(created_at AS INTEGER) DESC, CAST(id AS INTEGER) DESC
              LIMIT 1',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [
                'result_id' => (int)$result->id,
                'event_type' => STTTranscriptEvent::TYPE_TRANSCRIPT_COMPLETED,
            ]
        );
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $event = new STTTranscriptEvent();
        $event->id = (int)($row['id'] ?? 0);
        $event->event_type = (string)($row['event_type'] ?? STTTranscriptEvent::TYPE_TRANSCRIPT_COMPLETED);
        $event->event_key = (string)($row['event_key'] ?? '');
        $event->result_id = (int)($row['result_id'] ?? 0);
        $event->job_id = isset($row['job_id']) ? (int)$row['job_id'] : null;
        $event->call_id = (string)($row['call_id'] ?? '');
        $event->update_type = (string)($row['update_type'] ?? STTTranscriptEvent::UPDATE_CREATED);
        $event->payload = (string)($row['payload'] ?? '');
        $event->created_at = (string)($row['created_at'] ?? '0');

        return $event;
    }

    private function callId(?STTJob $job, ?STTTranscriptEvent $event): string
    {
        if ($job instanceof STTJob && trim((string)$job->call_id) !== '') {
            return trim((string)$job->call_id);
        }
        if ($event instanceof STTTranscriptEvent && trim((string)$event->call_id) !== '') {
            return trim((string)$event->call_id);
        }

        return '';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function segments(STTResult $result): array
    {
        $decoded = json_decode((string)$result->transcript, true);
        if (!is_array($decoded)) {
            return [];
        }

        $segments = [];
        foreach ($decoded as $index => $segment) {
            if (!is_array($segment)) {
                continue;
            }

            [$start, $end] = TranscriptTimeNormalizer::segmentRangeSeconds($segment);
            $segments[] = [
                'segment_id' => sprintf('stt:%d:%d', (int)$result->id, $index),
                'index' => $index,
                'start_ms' => isset($segment['start_ms']) ? (int)$segment['start_ms'] : null,
                'end_ms' => isset($segment['end_ms']) ? (int)$segment['end_ms'] : null,
                'start' => $start,
                'end' => $end,
                'text' => (string)($segment['text'] ?? ''),
                'channel' => isset($segment['channel']) ? (string)$segment['channel'] : null,
                'speaker' => isset($segment['speaker']) ? (string)$segment['speaker'] : null,
            ];
        }

        return $segments;
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array<int, array<string, mixed>>
     */
    private function turns(STTResult $result, array $segments): array
    {
        usort(
            $segments,
            static fn (array $left, array $right): int => [
                self::segmentStartMs($left) ?? 0,
                self::segmentEndMs($left) ?? 0,
                (int)($left['index'] ?? 0),
            ] <=> [
                self::segmentStartMs($right) ?? 0,
                self::segmentEndMs($right) ?? 0,
                (int)($right['index'] ?? 0),
            ]
        );

        $turns = [];
        $current = null;
        foreach ($segments as $segment) {
            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            if ($current === null || !$this->canAppendToTurn($current, $segment)) {
                if ($current !== null) {
                    $turns[] = $current;
                }
                $current = $this->newTurn((int)$result->id, count($turns), $segment, $text);
                continue;
            }

            $current = $this->appendToTurn($current, $segment, $text);
        }

        if ($current !== null) {
            $turns[] = $current;
        }

        return $turns;
    }

    /**
     * @param array<string, mixed> $turn
     * @param array<string, mixed> $segment
     */
    private function canAppendToTurn(array $turn, array $segment): bool
    {
        if ((string)($turn['speaker_key'] ?? '') !== self::speakerKey($segment)) {
            return false;
        }

        $segmentStart = self::segmentStartMs($segment);
        $segmentEnd = self::segmentEndMs($segment);
        $turnStart = is_int($turn['start_ms'] ?? null) ? $turn['start_ms'] : null;
        $turnEnd = is_int($turn['end_ms'] ?? null) ? $turn['end_ms'] : null;
        if ($segmentStart !== null && $turnEnd !== null && $segmentStart - $turnEnd > self::TURN_MAX_GAP_MS) {
            return false;
        }
        if ($segmentEnd !== null && $turnStart !== null && $segmentEnd - $turnStart > self::TURN_MAX_DURATION_MS) {
            return false;
        }

        $candidateText = $this->appendText((string)($turn['text'] ?? ''), trim((string)($segment['text'] ?? '')));
        return $this->textLength($candidateText) <= self::TURN_MAX_TEXT_CHARS;
    }

    /**
     * @param array<string, mixed> $segment
     * @return array<string, mixed>
     */
    private function newTurn(int $resultId, int $turnIndex, array $segment, string $text): array
    {
        $startMs = self::segmentStartMs($segment);
        $endMs = self::segmentEndMs($segment);

        return [
            'turn_id' => sprintf('stt:%d:turn:%d', $resultId, $turnIndex),
            'index' => $turnIndex,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'start' => $startMs === null ? 0.0 : $startMs / 1000.0,
            'end' => $endMs === null ? 0.0 : $endMs / 1000.0,
            'text' => $text,
            'channel' => isset($segment['channel']) ? (string)$segment['channel'] : null,
            'speaker' => isset($segment['speaker']) ? (string)$segment['speaker'] : null,
            'speaker_key' => self::speakerKey($segment),
            'source_segment_ids' => [(string)($segment['segment_id'] ?? '')],
            'source_indexes' => [(int)($segment['index'] ?? 0)],
        ];
    }

    /**
     * @param array<string, mixed> $turn
     * @param array<string, mixed> $segment
     * @return array<string, mixed>
     */
    private function appendToTurn(array $turn, array $segment, string $text): array
    {
        $segmentStart = self::segmentStartMs($segment);
        $segmentEnd = self::segmentEndMs($segment);
        if ($segmentStart !== null) {
            $turn['start_ms'] = is_int($turn['start_ms'] ?? null)
                ? min($turn['start_ms'], $segmentStart)
                : $segmentStart;
            $turn['start'] = $turn['start_ms'] / 1000.0;
        }
        if ($segmentEnd !== null) {
            $turn['end_ms'] = is_int($turn['end_ms'] ?? null)
                ? max($turn['end_ms'], $segmentEnd)
                : $segmentEnd;
            $turn['end'] = $turn['end_ms'] / 1000.0;
        }

        $turn['text'] = $this->appendText((string)$turn['text'], $text);
        $turn['source_segment_ids'][] = (string)($segment['segment_id'] ?? '');
        $turn['source_indexes'][] = (int)($segment['index'] ?? 0);

        return $turn;
    }

    private function appendText(string $left, string $right): string
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }

        return preg_match('/[\s([{]$/u', $left) === 1 ? $left . $right : $left . ' ' . $right;
    }

    private function textLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * @param array<int, array<string, mixed>> $turns
     */
    private function plainText(array $turns): string
    {
        $lines = [];
        foreach ($turns as $turn) {
            $text = trim((string)($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $speaker = trim((string)($turn['speaker'] ?? $turn['channel'] ?? ''));
            $lines[] = $speaker === '' ? $text : sprintf('%s: %s', $speaker, $text);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $segment
     */
    private static function speakerKey(array $segment): string
    {
        $speaker = self::lowercase(trim((string)($segment['speaker'] ?? '')));
        $channel = strtolower(trim((string)($segment['channel'] ?? '')));
        if ($speaker !== '') {
            return 'speaker:' . $speaker;
        }
        if ($channel !== '') {
            return 'channel:' . $channel;
        }

        return 'default';
    }

    private static function lowercase(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * @param array<string, mixed> $segment
     */
    private static function segmentStartMs(array $segment): ?int
    {
        if (isset($segment['start_ms']) && is_numeric($segment['start_ms'])) {
            return (int)$segment['start_ms'];
        }
        if (isset($segment['start']) && is_numeric($segment['start'])) {
            return (int)round((float)$segment['start'] * 1000);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $segment
     */
    private static function segmentEndMs(array $segment): ?int
    {
        if (isset($segment['end_ms']) && is_numeric($segment['end_ms'])) {
            return (int)$segment['end_ms'];
        }
        if (isset($segment['end']) && is_numeric($segment['end'])) {
            return (int)round((float)$segment['end'] * 1000);
        }

        return null;
    }

    private function cdrRecordForJob(STTJob $job): ?CallDetailRecords
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

                if ($jobFilePath !== '' && $this->cdrRecordingPath((string)$record->recordingfile) === $jobFilePath) {
                    return $record;
                }
            }
        }

        return $preferredRecord instanceof CallDetailRecords
            ? $preferredRecord
            : $this->cdrRecordByRecordingPath($jobFilePath);
    }

    private function cdrRecordForCallId(string $callId): ?CallDetailRecords
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
            if ($record instanceof CallDetailRecords && $this->cdrRecordingPath((string)$record->recordingfile) === $jobFilePath) {
                return $record;
            }
        }

        return null;
    }

    private function cdrRecordingPath(string $recordingFile): string
    {
        return $this->safeMonitorRecordingPath($recordingFile);
    }

    /**
     * @return array<string, mixed>
     */
    private function recordingInfo(?STTJob $job, ?CallDetailRecords $cdr): array
    {
        $safePath = $this->recordingPathForPlayback($job, $cdr);
        $recordingName = $safePath === ''
            ? ($job instanceof STTJob ? basename((string)$job->file_path) : '')
            : basename($safePath);
        if ($recordingName === '' && $cdr instanceof CallDetailRecords) {
            $recordingName = basename((string)$cdr->recordingfile);
        }

        return [
            'available' => $safePath !== '',
            'name' => $recordingName,
        ];
    }

    private function recordingPathForPlayback(?STTJob $job, ?CallDetailRecords $cdr): string
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
            $this->fileModificationTime($rawPath),
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

    private function fileModificationTime(string $filePath): int
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return 0;
        }

        clearstatcache(true, $filePath);
        $timestamp = filemtime($filePath);

        return is_int($timestamp) ? $timestamp : 0;
    }

    private function callTimestamp(STTResult $result, ?STTJob $job, ?CallDetailRecords $cdr): int
    {
        if ($cdr instanceof CallDetailRecords) {
            $timestamp = strtotime((string)$cdr->start);
            if ($timestamp !== false && $timestamp > 0) {
                return $timestamp;
            }
        }

        // cdr_start_ms is captured from the live CDR at job-creation time, so it survives
        // CDR retention purges that happen before this backlog item is processed.
        if ($job instanceof STTJob && (int)$job->cdr_start_ms > 0) {
            return intdiv((int)$job->cdr_start_ms, 1000);
        }

        if ($cdr instanceof CallDetailRecords) {
            $timestamp = strtotime((string)$cdr->endtime);
            if ($timestamp !== false && $timestamp > 0) {
                return $timestamp;
            }
        }

        if ($job instanceof STTJob && (int)$job->cdr_endtime > 0) {
            return (int)$job->cdr_endtime;
        }

        return (int)$result->created_at;
    }

    private function cdrEndtime(?STTJob $job, ?CallDetailRecords $cdr): ?int
    {
        if ($job instanceof STTJob && (int)$job->cdr_endtime > 0) {
            return (int)$job->cdr_endtime;
        }
        if ($cdr instanceof CallDetailRecords) {
            $timestamp = strtotime((string)$cdr->endtime);

            return $timestamp === false || $timestamp <= 0 ? null : $timestamp;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function callMetadata(?CallDetailRecords $cdr): array
    {
        if (!$cdr instanceof CallDetailRecords) {
            return [
                'start' => '',
                'endtime' => '',
                'src_num' => '',
                'dst_num' => '',
                'did' => '',
                'disposition' => '',
            ];
        }

        return [
            'start' => (string)$cdr->start,
            'endtime' => (string)$cdr->endtime,
            'src_num' => (string)$cdr->src_num,
            'dst_num' => (string)$cdr->dst_num,
            'did' => (string)$cdr->did,
            'disposition' => (string)$cdr->disposition,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: int|null, 1: int|null}
     */
    private function dateRange(array $filters): array
    {
        $from = $this->dateStart((string)($filters['date_from'] ?? ''));
        $to = $this->dateEnd((string)($filters['date_to'] ?? ''));

        return [$from, $to];
    }

    private function dateStart(string $date): ?int
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $timestamp = strtotime($date . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? ' 00:00:00' : ''));

        return $timestamp === false ? null : $timestamp;
    }

    private function dateEnd(string $date): ?int
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }

        $timestamp = strtotime($date . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? ' 23:59:59' : ''));

        return $timestamp === false ? null : $timestamp;
    }

    private function limit(mixed $value): int
    {
        $limit = (int)$value;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function decodeCursor(string $cursor): array
    {
        if (!preg_match('/^(\d+):(\d+)$/', trim($cursor), $matches)) {
            return [0, 0];
        }

        return [(int)$matches[1], (int)$matches[2]];
    }

    private function encodeCursor(int $createdAt, int $resultId): string
    {
        return max(0, $createdAt) . ':' . max(0, $resultId);
    }
}
