<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Phalcon\Db\Enum;

/**
 * The summary of a call for the integration API and the transcript cards:
 * one summary of the whole published call, every leg in it. Read at request
 * time, so a summary that appears later never changes the transcript
 * content_hash or revision.
 */
final class CallSummaryProjection
{
    public const string STATUS_DONE = 'done';
    // Waiting for a worker or being summarized right now.
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ERROR = 'error';
    // Too little speech to summarize.
    public const string STATUS_SKIPPED = 'skipped';
    // Summarization is off, or the call was transcribed before it was turned on.
    public const string STATUS_UNAVAILABLE = 'unavailable';

    private const array FINAL_STATUSES = [self::STATUS_DONE, self::STATUS_ERROR, self::STATUS_SKIPPED];

    /**
     * @return array{status: string, topic: ?string, summary: ?string, model: ?string, finished_at: ?int}
     */
    public function forAggregateId(int $aggregateId): array
    {
        $none = ['status' => self::STATUS_UNAVAILABLE, 'topic' => null, 'summary' => null, 'model' => null, 'finished_at' => null];
        if ($aggregateId <= 0) {
            return $none;
        }
        $rows = (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT c.created_at AS call_created_at, c.current_content_hash, s.id AS summary_id, s.status,
                    s.content_hash, s.topic, s.summary, s.model_used, s.finished_at
               FROM m_STTCallTranscript c
          LEFT JOIN m_STTSummary s ON s.call_transcript_id = c.id
              WHERE c.id = :id',
            Enum::FETCH_ASSOC,
            ['id' => $aggregateId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return $none;
        }
        [$enabled, $enabledAt] = $this->settings();
        $status = (string)($row['status'] ?? '');
        // A finished summary of earlier content is about to be redone.
        $current = (string)($row['content_hash'] ?? '') === (string)($row['current_content_hash'] ?? '');

        if (in_array($status, self::FINAL_STATUSES, true) && ($current || !$enabled)) {
            $done = $status === self::STATUS_DONE;

            return [
                'status' => $status,
                'topic' => $done ? (string)($row['topic'] ?? '') : null,
                'summary' => $done ? (string)($row['summary'] ?? '') : null,
                'model' => $done && trim((string)($row['model_used'] ?? '')) !== '' ? (string)$row['model_used'] : null,
                'finished_at' => (int)($row['finished_at'] ?? 0) > 0 ? (int)$row['finished_at'] : null,
            ];
        }
        if (!$enabled) {
            // Nothing summarizes while the setting is off.
            return $none;
        }
        if ($status !== '') {
            return ['status' => self::STATUS_PENDING] + $none;
        }
        // No summary row yet: the queue adds one only for calls created since
        // summarization was turned on.
        $createdAt = (int)($row['call_created_at'] ?? 0);

        return ['status' => $createdAt > 0 && $createdAt >= $enabledAt ? self::STATUS_PENDING : self::STATUS_UNAVAILABLE] + $none;
    }

    /** @return array{0: bool, 1: int} */
    private function settings(): array
    {
        $rows = (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT key, value FROM m_STTSetting WHERE key IN (:enabled, :enabled_at)',
            Enum::FETCH_ASSOC,
            ['enabled' => 'summarization_enabled', 'enabled_at' => 'summarization_enabled_at']
        );
        $values = [];
        foreach ($rows as $row) {
            if (isset($row['key'])) {
                $values[(string)$row['key']] = (string)($row['value'] ?? '');
            }
        }

        return [($values['summarization_enabled'] ?? '0') === '1', max(0, (int)($values['summarization_enabled_at'] ?? 0))];
    }
}
