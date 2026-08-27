<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;

class QueueViewService
{
    public function __construct(private mixed $translation, private CallRecordingService $recordings)
    {
    }

    public function queueSummaryRows(
        int $pendingCount,
        int $processingCount,
        int $doneTodayCount,
        int $errorCount,
        array $delayedRecordings,
        array $skippedRecordings
    ): array {
        return [
            [
                'icon' => 'clock outline',
                'label' => $this->translation->_('module_local_speech_to_text_Pending'),
                'labelClass' => 'blue',
                'count' => $pendingCount,
                'lastEventLabel' => $this->formatTimestamp($this->latestJobTimestamp(STTJob::STATUS_PENDING, 'updated_at')),
            ],
            [
                'icon' => 'spinner',
                'label' => $this->translation->_('module_local_speech_to_text_Processing'),
                'labelClass' => 'orange',
                'count' => $processingCount,
                'lastEventLabel' => $this->formatTimestamp($this->latestJobTimestamp(STTJob::STATUS_PROCESSING, 'updated_at')),
            ],
            [
                'icon' => 'check circle outline',
                'label' => $this->translation->_('module_local_speech_to_text_DoneToday'),
                'labelClass' => 'green',
                'count' => $doneTodayCount,
                'lastEventLabel' => $this->formatTimestamp(
                    $this->latestJobTimestamp(STTJob::STATUS_DONE, 'finished_at', strtotime('today'))
                ),
            ],
            [
                'icon' => 'exclamation triangle',
                'label' => $this->translation->_('module_local_speech_to_text_Errors'),
                'labelClass' => 'red',
                'count' => $errorCount,
                'lastEventLabel' => $this->formatTimestamp($this->latestJobTimestamp(STTJob::STATUS_ERROR, 'finished_at')),
            ],
            [
                'icon' => 'hourglass half',
                'label' => $this->translation->_('module_local_speech_to_text_DelayedRecordings'),
                'labelClass' => 'yellow',
                'count' => count($delayedRecordings),
                'lastEventLabel' => $this->formatTimestamp($this->latestStoredRecordTimestamp($delayedRecordings, 'last_seen')),
            ],
            [
                'icon' => 'ban',
                'label' => $this->translation->_('module_local_speech_to_text_SkippedRecordings'),
                'labelClass' => 'grey',
                'count' => count($skippedRecordings),
                'lastEventLabel' => $this->formatTimestamp($this->latestStoredRecordTimestamp($skippedRecordings, 'created_at')),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<int, array<string, mixed>> $skippedRecordings
     * @return array<int, array<string, mixed>>
     */
    public function queueFileRows(array $delayedRecordings, array $skippedRecordings): array
    {
        $rows = [];
        $jobs = STTJob::find([
            'conditions' => 'status = :pending: OR status = :processing: OR status = :error:',
            'bind' => [
                'pending' => STTJob::STATUS_PENDING,
                'processing' => STTJob::STATUS_PROCESSING,
                'error' => STTJob::STATUS_ERROR,
            ],
            'order' => 'id ASC',
            'limit' => 100,
        ]);

        foreach ($jobs as $job) {
            if (!$job instanceof STTJob) {
                continue;
            }

            $status = $this->queueJobStatusPresentation((string)$job->status);
            $filePath = (string)$job->file_path;
            $worker = $job->STTWorker;
            $updatedTimestamp = $this->queueJobUpdatedTimestamp($job);
            $recordingTimestamp = $this->queueRecordingTimestamp(
                (string)$job->call_id,
                $filePath,
                (int)$job->created_at
            );

            $rows[] = [
                'sourceRank' => 10,
                'sourceId' => (int)$job->id,
                'recordingDateLabel' => $this->queueDateLabel($recordingTimestamp),
                'recordingTimeLabel' => $this->queueTimeLabel($recordingTimestamp),
                'icon' => $status['icon'],
                'labelClass' => $status['labelClass'],
                'statusLabel' => $status['label'],
                'statusDetailLabel' => trim((string)$job->error_msg),
                'sourceLabel' => $this->translation->_('module_local_speech_to_text_Job') . ' #' . (int)$job->id,
                'callId' => (string)$job->call_id,
                'workerLabel' => $worker instanceof STTWorker && trim((string)$worker->name) !== ''
                    ? (string)$worker->name
                    : $this->translation->_('module_local_speech_to_text_WorkerUnassigned'),
                'attemptsLabel' => (string)(int)$job->attempts,
                'createdAtLabel' => $this->formatTimestamp((int)$job->created_at),
                'updatedAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedTimestamp' => $updatedTimestamp,
            ];
        }

        foreach ($delayedRecordings as $recordId => $record) {
            $recordingFile = (string)($record['recordingfile'] ?? '');
            $updatedTimestamp = (int)($record['last_seen'] ?? $record['first_seen'] ?? 0);
            $recordingFallbackTimestamp = (int)($record['cdr_endtime'] ?? 0);
            if ($recordingFallbackTimestamp <= 0) {
                $recordingFallbackTimestamp = (int)($record['first_seen'] ?? 0);
            }
            $recordingTimestamp = $this->queueRecordingTimestamp(
                (string)($record['call_id'] ?? ''),
                $recordingFile,
                $recordingFallbackTimestamp
            );
            $rows[] = [
                'sourceRank' => 20,
                'sourceId' => (int)$recordId,
                'recordingDateLabel' => $this->queueDateLabel($recordingTimestamp),
                'recordingTimeLabel' => $this->queueTimeLabel($recordingTimestamp),
                'icon' => 'hourglass half',
                'labelClass' => 'yellow',
                'statusLabel' => $this->translation->_('module_local_speech_to_text_DelayedRecordings'),
                'statusDetailLabel' => '',
                'sourceLabel' => $this->translation->_('module_local_speech_to_text_CdrRecord') . ' #' . (int)$recordId,
                'callId' => (string)($record['call_id'] ?? ''),
                'workerLabel' => '',
                'attemptsLabel' => '',
                'createdAtLabel' => $this->formatTimestamp((int)($record['first_seen'] ?? 0)),
                'updatedAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedTimestamp' => $updatedTimestamp,
            ];
        }

        foreach ($skippedRecordings as $recordId => $record) {
            $recordingPath = (string)($record['recording_path'] ?? '');
            $recordingFile = $recordingPath !== '' ? $recordingPath : (string)($record['recordingfile'] ?? '');
            $updatedTimestamp = (int)($record['created_at'] ?? 0);
            $recordingFallbackTimestamp = (int)($record['cdr_endtime'] ?? 0);
            if ($recordingFallbackTimestamp <= 0) {
                $recordingFallbackTimestamp = $updatedTimestamp;
            }
            $recordingTimestamp = $this->queueRecordingTimestamp(
                (string)($record['call_id'] ?? ''),
                $recordingFile,
                $recordingFallbackTimestamp
            );
            $rows[] = [
                'sourceRank' => 30,
                'sourceId' => (int)$recordId,
                'recordingDateLabel' => $this->queueDateLabel($recordingTimestamp),
                'recordingTimeLabel' => $this->queueTimeLabel($recordingTimestamp),
                'icon' => 'ban',
                'labelClass' => 'grey',
                'statusLabel' => $this->translation->_('module_local_speech_to_text_SkippedRecordings'),
                'statusDetailLabel' => $this->queueSkippedReasonLabel((string)($record['reason'] ?? '')),
                'sourceLabel' => $this->translation->_('module_local_speech_to_text_CdrRecord') . ' #' . (int)$recordId,
                'callId' => (string)($record['call_id'] ?? ''),
                'workerLabel' => '',
                'attemptsLabel' => '',
                'createdAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedAtLabel' => $this->formatTimestamp($updatedTimestamp),
                'updatedTimestamp' => $updatedTimestamp,
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $sourceRank = ((int)$left['sourceRank']) <=> ((int)$right['sourceRank']);
                if ($sourceRank !== 0) {
                    return $sourceRank;
                }

                return ((int)$left['sourceId']) <=> ((int)$right['sourceId']);
            }
        );

        return $rows;
    }

    private function latestJobTimestamp(string $status, string $column, int $since = 0): int
    {
        $conditions = "status = :status: AND {$column} IS NOT NULL AND {$column} <> :empty:";
        $bind = [
            'status' => $status,
            'empty' => '',
        ];
        if ($since > 0) {
            $conditions .= " AND {$column} >= :since:";
            $bind['since'] = (string)$since;
        }

        $job = STTJob::findFirst([
            'conditions' => $conditions,
            'bind' => $bind,
            'order' => "{$column} DESC, id DESC",
        ]);
        if (!$job instanceof STTJob) {
            return 0;
        }

        return (int)$job->{$column};
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function latestStoredRecordTimestamp(array $records, string $field): int
    {
        $latest = 0;
        foreach ($records as $record) {
            $latest = max($latest, (int)($record[$field] ?? 0));
        }

        return $latest;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function storedRecords(string $settingKey): array
    {
        $records = json_decode(STTSetting::getValue($settingKey, '[]'), true);
        if (!is_array($records)) {
            return [];
        }

        $normalized = [];
        foreach ($records as $recordId => $record) {
            $recordId = (int)$recordId;
            if ($recordId <= 0 || !is_array($record)) {
                continue;
            }

            $normalized[$recordId] = $record;
        }

        return $normalized;
    }

    /**
     * @return array{icon: string, labelClass: string, label: string}
     */
    private function queueJobStatusPresentation(string $status): array
    {
        return match ($status) {
            STTJob::STATUS_PROCESSING => [
                'icon' => 'spinner',
                'labelClass' => 'orange',
                'label' => $this->translation->_('module_local_speech_to_text_Processing'),
            ],
            STTJob::STATUS_ERROR => [
                'icon' => 'exclamation triangle',
                'labelClass' => 'red',
                'label' => $this->translation->_('module_local_speech_to_text_Errors'),
            ],
            default => [
                'icon' => 'clock outline',
                'labelClass' => 'blue',
                'label' => $this->translation->_('module_local_speech_to_text_Pending'),
            ],
        };
    }

    private function queueJobUpdatedTimestamp(STTJob $job): int
    {
        if ((int)$job->updated_at > 0) {
            return (int)$job->updated_at;
        }
        if ((string)$job->status === STTJob::STATUS_PROCESSING) {
            return (int)$job->started_at;
        }
        if ((string)$job->status === STTJob::STATUS_ERROR) {
            return (int)$job->finished_at;
        }

        return (int)$job->created_at;
    }

    private function queueSkippedReasonLabel(string $reason): string
    {
        return match ($reason) {
            'file_too_large' => $this->translation->_('module_local_speech_to_text_QueueSkipReasonFileTooLarge'),
            'duration_too_long' => $this->translation->_('module_local_speech_to_text_QueueSkipReasonDurationTooLong'),
            'duration_probe_failed' => $this->translation->_('module_local_speech_to_text_QueueSkipReasonDurationProbeFailed'),
            'recording_missing_timeout' => $this->translation->_('module_local_speech_to_text_QueueSkipReasonMissingTimeout'),
            'outside_history_window' => $this->translation->_('module_local_speech_to_text_QueueSkipReasonOutsideHistory'),
            default => $reason,
        };
    }

    private function queueRecordingTimestamp(string $callId, string $filePath, int $fallbackTimestamp): int
    {
        $cdrTimestamp = $this->recordings->cdrCallStartTimestamp($callId);
        if ($cdrTimestamp > 0) {
            return $cdrTimestamp;
        }

        $recordingTimestamp = $this->recordings->recordingTimestamp($filePath);
        if ($recordingTimestamp > 0) {
            return $recordingTimestamp;
        }

        return max(0, $fallbackTimestamp);
    }

    private function queueDateLabel(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d', $timestamp) : '';
    }

    private function queueTimeLabel(int $timestamp): string
    {
        return $timestamp > 0 ? date('H:i:s', $timestamp) : '';
    }

    private function formatTimestamp(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    /**
     * @param array{dateFrom: string, dateTo: string, start: int, end: int, isActive: bool}|null $dateFilter
     * @return array<int, array<string, mixed>>
     */
}
