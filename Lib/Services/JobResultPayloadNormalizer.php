<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;

/**
 * Normalizes a worker-submitted transcription result payload before it is
 * persisted by LocalSpeechToTextMain::submitResult(): merges the server-side
 * quality-filter stats into technical_info, JSON-encodes the
 * transcript/technical_info columns, and derives a language hint from
 * technical_info when the worker did not send one directly.
 */
final class JobResultPayloadNormalizer
{
    /**
     * @param array<int, array<string, mixed>> $segments
     *
     * @throws JsonException
     */
    public static function encodeTranscript(array $segments): string
    {
        return json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null $technicalInfo
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    public static function mergeTranscriptQualityInfo(?array $technicalInfo, array $stats): array
    {
        $technicalInfo ??= [];
        $quality = is_array($technicalInfo['quality'] ?? null) ? $technicalInfo['quality'] : [];
        $existingReasons = is_array($quality['reasons'] ?? null) ? $quality['reasons'] : [];
        $serverReasons = is_array($stats['reasons'] ?? null) ? $stats['reasons'] : [];

        $quality['server_filter'] = true;
        $quality['server_raw_segments'] = (int)($stats['raw_segments'] ?? 0);
        $quality['server_submitted_segments'] = (int)($stats['submitted_segments'] ?? 0);
        $quality['server_rejected_segments'] = (int)($stats['rejected_segments'] ?? 0);
        $quality['server_trimmed_segments'] = (int)($stats['trimmed_segments'] ?? 0);
        $quality['rejected_segments'] = (int)($quality['rejected_segments'] ?? 0) + (int)($stats['rejected_segments'] ?? 0);
        $quality['trimmed_segments'] = (int)($quality['trimmed_segments'] ?? 0) + (int)($stats['trimmed_segments'] ?? 0);
        $quality['reasons'] = array_values(array_unique(array_merge(
            array_map('strval', $existingReasons),
            array_map('strval', $serverReasons)
        )));

        $technicalInfo['quality'] = $quality;
        $technicalInfo['server_quality'] = [
            'transcript_filter' => true,
            'raw_segments' => (int)($stats['raw_segments'] ?? 0),
            'submitted_segments' => (int)($stats['submitted_segments'] ?? 0),
            'rejected_segments' => (int)($stats['rejected_segments'] ?? 0),
            'trimmed_segments' => (int)($stats['trimmed_segments'] ?? 0),
            'reasons' => $serverReasons,
        ];

        return $technicalInfo;
    }

    /**
     * @throws JsonException
     */
    public static function encodeTechnicalInfo(mixed $technicalInfo): ?string
    {
        if (!is_array($technicalInfo) || $technicalInfo === []) {
            return null;
        }

        return json_encode($technicalInfo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null $technicalInfo
     */
    public static function languageHintFromTechnicalInfo(?array $technicalInfo): ?string
    {
        if (!is_array($technicalInfo)) {
            return null;
        }

        $model = is_array($technicalInfo['model'] ?? null) ? $technicalInfo['model'] : [];
        foreach ([$model['language_hint'] ?? null, $model['language'] ?? null] as $value) {
            if (is_scalar($value)) {
                $language = trim((string)$value);
                if ($language !== '') {
                    return $language;
                }
            }
        }

        return null;
    }
}
