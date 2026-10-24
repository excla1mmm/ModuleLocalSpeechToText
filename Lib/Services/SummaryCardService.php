<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * The "Summarization" field of the transcript card: one summary of the whole
 * call, every leg in it. It is shown only while summarization is turned on.
 */
final class SummaryCardService
{
    public const string STATE_DONE = CallSummaryProjection::STATUS_DONE;
    // Waiting for a worker or being summarized right now.
    public const string STATE_PENDING = CallSummaryProjection::STATUS_PENDING;
    public const string STATE_ERROR = CallSummaryProjection::STATUS_ERROR;
    // Too little speech to summarize.
    public const string STATE_SKIPPED = CallSummaryProjection::STATUS_SKIPPED;
    // Transcribed before summarization was turned on.
    public const string STATE_UNAVAILABLE = CallSummaryProjection::STATUS_UNAVAILABLE;

    public static function enabled(): bool
    {
        return STTSetting::summarizationEnabled();
    }

    /**
     * The summary card of a call, by the PBX call id the transcripts carry.
     *
     * @return array{state: string, topic: string, summary: string}
     */
    public function forCall(string $callId): array
    {
        $call = trim($callId) === '' ? null : STTCallTranscript::findFirst([
            'conditions' => 'call_id = :call_id:',
            'bind' => ['call_id' => trim($callId)],
        ]);
        if (!$call instanceof STTCallTranscript) {
            return ['state' => self::STATE_UNAVAILABLE, 'topic' => '', 'summary' => ''];
        }
        $summary = (new CallSummaryProjection())->forAggregateId((int)$call->id);

        return [
            'state' => $summary['status'],
            'topic' => (string)$summary['topic'],
            'summary' => (string)$summary['summary'],
        ];
    }
}
