<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTSummary;
use PDO;

/**
 * The "Summarization" field of the transcript card. It is shown only while
 * summarization is turned on.
 */
final class SummaryCardService
{
    public const string STATE_DONE = 'done';
    // Waiting for a worker or being summarized right now.
    public const string STATE_PENDING = 'pending';
    public const string STATE_ERROR = 'error';
    // Too little speech to summarize.
    public const string STATE_SKIPPED = 'skipped';
    // Transcribed before summarization was turned on.
    public const string STATE_UNAVAILABLE = 'unavailable';

    public static function enabled(): bool
    {
        return STTSetting::summarizationEnabled();
    }

    /**
     * @param array<int, STTResult> $results
     * @return array<int, array{state: string, topic: string, summary: string}> keyed by result id
     */
    public function forResults(array $results): array
    {
        $summaries = STTSummary::forResultIds(array_map(static fn (STTResult $result): int => (int)$result->id, $results));
        $enabledAt = STTSetting::summarizationEnabledAt();
        $cards = [];
        foreach ($results as $result) {
            $summary = $summaries[(int)$result->id] ?? null;
            $cards[(int)$result->id] = $summary instanceof STTSummary
                ? self::card((string)$summary->status, (string)$summary->topic, (string)$summary->summary)
                : self::card(
                    (int)$result->created_at >= $enabledAt ? self::STATE_PENDING : self::STATE_UNAVAILABLE,
                    '',
                    ''
                );
        }

        return $cards;
    }

    /**
     * Summaries of every transcribed leg of a call, in the order the full card
     * lists its stages.
     *
     * @return array<int, array{state: string, topic: string, summary: string}>
     */
    public function forCall(string $callId): array
    {
        if (trim($callId) === '') {
            return [];
        }
        $rows = (new STTResult())->getReadConnection()->fetchAll(
            'SELECT r.id FROM m_STTResult r JOIN m_STTJob j ON j.id = r.job_id
              WHERE j.call_id = :call_id AND j.status = :done ORDER BY j.cdr_start_ms ASC, j.id ASC',
            PDO::FETCH_ASSOC,
            ['call_id' => $callId, 'done' => STTJob::STATUS_DONE]
        );
        $results = [];
        foreach ($rows as $row) {
            $result = STTResult::findFirstById((int)$row['id']);
            if ($result instanceof STTResult) {
                $results[] = $result;
            }
        }

        return array_values($this->forResults($results));
    }

    /** @return array{state: string, topic: string, summary: string} */
    private static function card(string $status, string $topic, string $summary): array
    {
        $state = match ($status) {
            STTSummary::STATUS_DONE => self::STATE_DONE,
            STTSummary::STATUS_ERROR => self::STATE_ERROR,
            STTSummary::STATUS_SKIPPED => self::STATE_SKIPPED,
            STTSummary::STATUS_PENDING, STTSummary::STATUS_PROCESSING => self::STATE_PENDING,
            default => $status,
        };

        return ['state' => $state, 'topic' => $topic, 'summary' => $summary];
    }
}
