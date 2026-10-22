<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Phalcon\Db\Enum;

/**
 * The call summary block of the integration API: the summary of every part of
 * a call and one status for the whole call. Read at request time, so a summary
 * that appears later never changes the transcript content_hash or revision.
 */
final class CallSummaryProjection
{
    public const string STATUS_DONE = 'done';
    // Waiting for a worker or being summarized right now.
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ERROR = 'error';
    // Too little speech to summarize.
    public const string STATUS_SKIPPED = 'skipped';
    // Summarization is off, or the part was transcribed before it was turned on.
    public const string STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param array<int, int> $resultIds part results in call order
     * @return array{status: string, topic: ?string, summary: ?string, parts: array<int, array<string, mixed>>}
     */
    public function forResultIds(array $resultIds): array
    {
        $resultIds = array_values(array_unique(array_filter(
            array_map('intval', $resultIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($resultIds === []) {
            return ['status' => self::STATUS_UNAVAILABLE, 'topic' => null, 'summary' => null, 'parts' => []];
        }
        $rows = $this->rows($resultIds);
        [$enabled, $enabledAt] = $this->settings();

        $parts = [];
        foreach ($resultIds as $resultId) {
            $row = $rows[$resultId] ?? null;
            $status = $this->partStatus($row, $enabled, $enabledAt);
            $done = $status === self::STATUS_DONE;
            $parts[] = [
                'result_id' => $resultId,
                'status' => $status,
                'topic' => $done ? (string)($row['topic'] ?? '') : null,
                'summary' => $done ? (string)($row['summary'] ?? '') : null,
                'model' => $done && trim((string)($row['model_used'] ?? '')) !== '' ? (string)$row['model_used'] : null,
                'finished_at' => $done && (int)($row['finished_at'] ?? 0) > 0 ? (int)$row['finished_at'] : null,
            ];
        }

        $done = array_values(array_filter($parts, static fn (array $part): bool => $part['status'] === self::STATUS_DONE));
        $texts = array_values(array_filter(array_map(static fn (array $part): string => trim((string)$part['summary']), $done)));

        return [
            'status' => self::callStatus(array_column($parts, 'status')),
            'topic' => $done === [] ? null : (string)$done[0]['topic'],
            'summary' => $texts === [] ? null : implode("\n\n", $texts),
            'parts' => $parts,
        ];
    }

    /**
     * Pending wins while any part may still get a summary; then a summary that
     * exists; then an error; skipped only when every part was skipped.
     *
     * @param array<int, string> $statuses
     */
    public static function callStatus(array $statuses): string
    {
        foreach ([self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_ERROR] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return $statuses !== [] && array_unique($statuses) === [self::STATUS_SKIPPED]
            ? self::STATUS_SKIPPED
            : self::STATUS_UNAVAILABLE;
    }

    /** @param array<string, mixed>|null $row */
    private function partStatus(?array $row, bool $enabled, int $enabledAt): string
    {
        $status = (string)($row['status'] ?? '');
        if (in_array($status, [self::STATUS_DONE, self::STATUS_ERROR, self::STATUS_SKIPPED], true)) {
            return $status;
        }
        if (!$enabled) {
            // Nothing summarizes while the setting is off.
            return self::STATUS_UNAVAILABLE;
        }
        if ($status === 'pending' || $status === 'processing') {
            return self::STATUS_PENDING;
        }
        // No summary row yet: the queue adds one only for transcripts created
        // since summarization was turned on.
        $createdAt = (int)($row['result_created_at'] ?? 0);

        return $createdAt > 0 && $createdAt >= $enabledAt ? self::STATUS_PENDING : self::STATUS_UNAVAILABLE;
    }

    /**
     * @param array<int, int> $resultIds
     * @return array<int, array<string, mixed>> keyed by result id
     */
    private function rows(array $resultIds): array
    {
        if ($resultIds === []) {
            return [];
        }
        $placeholders = [];
        $bind = [];
        foreach ($resultIds as $index => $resultId) {
            $placeholders[] = ':result_' . $index;
            $bind['result_' . $index] = $resultId;
        }
        $rows = (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT r.id AS result_id, r.created_at AS result_created_at, s.status, s.topic, s.summary,
                    s.model_used, s.finished_at
               FROM m_STTResult r
          LEFT JOIN m_STTSummary s ON s.result_id = r.id
              WHERE r.id IN (' . implode(', ', $placeholders) . ')',
            Enum::FETCH_ASSOC,
            $bind
        );

        $byResult = [];
        foreach ($rows as $row) {
            $resultId = (int)($row['result_id'] ?? 0);
            if (in_array($resultId, $resultIds, true)) {
                $byResult[$resultId] = $row;
            }
        }

        return $byResult;
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
