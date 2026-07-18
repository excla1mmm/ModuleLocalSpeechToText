<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;
use MikoPBX\Core\System\Directories;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptPart;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Phalcon\Db\Enum;
use RuntimeException;

final class CallTranscriptMigration
{
    /** @return array<string, int> */
    public function run(int $limit = 10000): array
    {
        $connection = (new STTResult())->getReadConnection();
        $rows = $connection->fetchAll(
            'SELECT e.result_id, e.job_id, e.call_id
               FROM m_STTTranscriptEvent e
               JOIN m_STTResult r ON r.id = e.result_id
          LEFT JOIN m_STTCallTranscriptPart p
                 ON p.result_id = e.result_id
                AND p.is_current = 1
              WHERE e.event_type = :event_type
                AND e.call_id IS NOT NULL
                AND TRIM(e.call_id) <> \'\'
                AND p.id IS NULL
              ORDER BY CAST(e.result_id AS INTEGER) ASC
              LIMIT ' . max(1, min(100000, $limit)),
            Enum::FETCH_ASSOC,
            ['event_type' => 'transcript.completed']
        );

        $service = new CallTranscriptRevisionService();
        $changed = 0;
        $unchanged = 0;
        foreach ($rows as $row) {
            $result = STTResult::findFirstById((int)($row['result_id'] ?? 0));
            if (!$result instanceof STTResult) {
                throw new RuntimeException(
                    'Call transcript migration could not load result ' . (int)($row['result_id'] ?? 0)
                );
            }
            $job = STTJob::findFirstById((int)($row['job_id'] ?? 0));
            if (!$job instanceof STTJob) {
                $job = new STTJob();
                $job->id = (int)($row['job_id'] ?? 0);
                $job->call_id = (string)($row['call_id'] ?? '');
                $job->cdr_id = null;
                $job->cdr_start_ms = null;
                $job->cdr_end_ms = null;
                $job->recording_fingerprint = '';
            } else {
                if (trim((string)$job->call_id) === '') {
                    $job->call_id = (string)($row['call_id'] ?? '');
                }
                $this->enrichExactCdrIdentity($job);
            }
            $state = $service->recordCompletedPart($result, $job);
            if ((int)($state['call_transcript_id'] ?? 0) <= 0) {
                throw new RuntimeException(
                    'Call transcript migration could not materialize result ' . (int)$result->id
                );
            }
            if (($state['state'] ?? '') === 'staged') {
                $changed++;
            } else {
                $unchanged++;
            }
        }

        $stability = max(0, (int)STTSetting::getValue(STTSetting::CALL_TRANSCRIPT_STABILITY_SEC, '30'));
        $published = $service->publishReady(
            eligibleAt: time() + $stability + 1,
            limit: 1000,
            publishedAt: time(),
            terminalOnly: true
        );

        return [
            'source_results' => count($rows),
            'changed' => $changed,
            'unchanged' => $unchanged,
            'published' => (int)$published['published'],
            'current_calls' => (int)STTCallTranscript::count(),
            'legacy_unresolved' => (int)STTCallTranscriptPart::count([
                'conditions' => 'is_current = 1 AND resolution_status = :status:',
                'bind' => ['status' => 'legacy_unresolved'],
            ]),
        ];
    }

    /** @return array{published: int, batches: int} */
    public function publishAllReady(int $limit = 1000): array
    {
        $service = new CallTranscriptRevisionService();
        $stability = max(0, (int)STTSetting::getValue(STTSetting::CALL_TRANSCRIPT_STABILITY_SEC, '30'));
        $eligibleAt = time() + $stability + 1;
        $publishedAt = time();
        $total = 0;
        $batches = 0;

        do {
            $summary = $service->publishReady(
                eligibleAt: $eligibleAt,
                limit: $limit,
                publishedAt: $publishedAt,
                terminalOnly: true
            );
            if ((int)$summary['scanned'] !== (int)$summary['published']) {
                throw new RuntimeException('Call transcript migration publication batch made no progress');
            }
            $published = (int)$summary['published'];
            $total += $published;
            $batches++;
        } while ($published > 0);

        return ['published' => $total, 'batches' => $batches];
    }

    private function enrichExactCdrIdentity(STTJob $job): bool
    {
        if ((int)$job->cdr_id > 0 && (int)$job->cdr_start_ms > 0) {
            return true;
        }
        $jobPath = realpath((string)$job->file_path);
        $callId = trim((string)$job->call_id);
        if ($jobPath === false || !is_file($jobPath) || $callId === '') {
            return false;
        }

        $matches = [];
        foreach (CallDetailRecords::find([
            'conditions' => '(linkedid = :call_id: OR UNIQUEID = :call_id:) AND recordingfile <> :empty:',
            'bind' => ['call_id' => $callId, 'empty' => ''],
            'order' => 'start ASC, id ASC',
        ]) as $record) {
            if (!$record instanceof CallDetailRecords) {
                continue;
            }
            $recordingFile = trim((string)$record->recordingfile);
            $candidates = [$recordingFile];
            if ($recordingFile !== '' && !str_starts_with($recordingFile, DIRECTORY_SEPARATOR)) {
                $candidates[] = rtrim(Directories::getDir(Directories::AST_MONITOR_DIR), DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . ltrim($recordingFile, DIRECTORY_SEPARATOR);
            }
            foreach ($candidates as $candidate) {
                $resolved = realpath($candidate);
                if ($resolved !== false && hash_equals($jobPath, $resolved)) {
                    $matches[(int)$record->id] = $record;
                    break;
                }
            }
        }
        if (count($matches) !== 1) {
            return false;
        }

        /** @var CallDetailRecords $record */
        $record = array_values($matches)[0];
        $startMs = $this->dateTimeMilliseconds((string)$record->start);
        $endMs = $this->dateTimeMilliseconds((string)$record->endtime);
        if ($startMs === null) {
            return false;
        }
        $size = (int)(filesize($jobPath) ?: 0);
        $modifiedAt = (int)(filemtime($jobPath) ?: 0);
        $job->cdr_id = (int)$record->id;
        $job->cdr_uniqueid = (string)$record->UNIQUEID;
        $job->cdr_start_ms = (string)$startMs;
        $job->cdr_end_ms = $endMs === null ? null : (string)$endMs;
        $job->recording_fingerprint = hash('sha256', implode('|', [
            (int)$record->id,
            (string)$record->UNIQUEID,
            $startMs,
            $endMs ?? 0,
            $size,
            $modifiedAt,
        ]));

        return $job->save();
    }

    private function dateTimeMilliseconds(string $value): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', trim($value))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($value));

        return $date instanceof \DateTimeImmutable
            ? ((int)$date->format('U') * 1000) + (int)substr($date->format('u'), 0, 3)
            : null;
    }
}
