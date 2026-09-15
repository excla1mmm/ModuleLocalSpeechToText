<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * Throttles and runs the transcript retention purge sweep.
 *
 * Extracted verbatim from bin/WorkerLocalSpeechToTextJobs.php (moved, not
 * rewritten).
 */
final class TranscriptRetentionCoordinator
{
    private const int TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS = 3600;

    public function purgeExpiredTranscripts(): void
    {
        $retentionDays = STTSetting::transcriptRetentionDays();
        if ($retentionDays === 0) {
            return;
        }

        $now = time();
        $lastCleanupAt = max(0, (int)STTSetting::getValue(
            STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT,
            '0'
        ));
        if ($lastCleanupAt > $now - self::TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS) {
            return;
        }

        $summary = (new TranscriptRetentionService())->purgeExpired($retentionDays, $now);
        STTSetting::setValue(
            STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT,
            $summary['has_more'] ? '0' : (string)$now
        );
        if ($summary['calls'] > 0 || $summary['results'] > 0) {
            LocalSpeechToTextLogger::event(
                'transcript',
                'retention_cleanup_completed',
                'Expired transcript data removed',
                LOG_NOTICE,
                [
                    'calls_count' => $summary['calls'],
                    'results_count' => $summary['results'],
                    'has_more' => $summary['has_more'],
                ]
            );
        }
    }
}
