<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Lib\TranscriptTimeNormalizer;

class TranscriptTechnicalInfoService
{
    public function __construct(private mixed $translation)
    {
    }

    public function technicalInfoCard(string $technicalInfo): array
    {
        $data = json_decode($technicalInfo, true);
        if (!is_array($data)) {
            return [
                'hasRows' => false,
                'groups' => [],
                'rawJson' => '',
            ];
        }
        $data = $this->normalizeTechnicalInfo($data);

        $groups = [
            [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupPipeline'),
                'rows' => array_values(array_filter([
                    $this->technicalRow('module_local_speech_to_text_TechnicalModelResolved', $this->stringMetric($data, 'model.resolved')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalModelRequested', $this->stringMetric($data, 'model.requested')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalWhisperKitModel', $this->stringMetric($data, 'model.whisperkit_model')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalLanguageHint', $this->stringMetric($data, 'model.language_hint')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalLanguageDetected', $this->stringMetric($data, 'language.dominant')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalLanguageVotes', $this->languageVotesMetric($data)),
                    $this->technicalRow('module_local_speech_to_text_TechnicalStereoMode', $this->stringMetric($data, 'pipeline.stereo_mode')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSplitChannels', $this->boolMetric($data, 'pipeline.split_channels')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalAudioNormalize', $this->boolMetric($data, 'pipeline.audio_normalize')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalContext', $this->boolMetric($data, 'pipeline.context_enabled')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalVocabularyTerms', $this->numberMetric($data, 'pipeline.vocabulary_terms')),
                ])),
            ],
            [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupVad'),
                'rows' => array_values(array_filter([
                    $this->technicalRow('module_local_speech_to_text_TechnicalVadEnabled', $this->boolMetric($data, 'vad.enabled')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalInputDuration', $this->secondsMetric($data, 'input.duration_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalInputChannels', $this->numberMetric($data, 'input.channels')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalVadSegments', $this->numberMetric($data, 'vad.detected_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSpeechSeconds', $this->secondsMetric($data, 'vad.speech_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalTimelineSpeechSeconds', $this->secondsMetric($data, 'vad.timeline_speech_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSpeechRatio', $this->percentMetric($data, 'vad.speech_ratio')),
                ])),
            ],
            [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupPerformance'),
                'rows' => array_values(array_filter([
                    $this->technicalRow('module_local_speech_to_text_TechnicalSubmittedSegments', $this->numberMetric($data, 'transcription.submitted_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalRecognizedSegments', $this->numberMetric($data, 'transcription.recognized_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalAttemptedSegments', $this->numberMetric($data, 'transcription.attempted_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalEmptySegments', $this->numberMetric($data, 'transcription.empty_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalFallbackUsed', $this->boolMetric($data, 'transcription.fallback_used')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalFallbackSegments', $this->numberMetric($data, 'transcription.fallback_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalWarnings', $this->numberMetric($data, 'transcription.warnings')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSttCommands', $this->numberMetric($data, 'transcription.stt_commands')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalElapsed', $this->secondsMetric($data, 'timing.elapsed_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSttCommandTime', $this->secondsMetric($data, 'timing.stt_command_seconds')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalRealTimeFactor', $this->ratioMetric($data, 'timing.real_time_factor')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalStartedAt', $this->timestampMetric($data, 'timing.started_at')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalRejectedSegments', $this->numberMetric($data, 'quality.rejected_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalSkippedShortSegments', $this->numberMetric($data, 'quality.skipped_short_segments')),
                    $this->technicalRow('module_local_speech_to_text_TechnicalQualityReasons', $this->qualityReasonsMetric($data)),
                ])),
            ],
        ];

        $channelRows = $this->technicalChannelRows($data);
        if ($channelRows !== []) {
            $groups[] = [
                'title' => $this->translation->_('module_local_speech_to_text_TechnicalGroupChannels'),
                'rows' => $channelRows,
            ];
        }

        $groups = array_values(array_filter(
            $groups,
            static fn (array $group): bool => ($group['rows'] ?? []) !== []
        ));

        return [
            'hasRows' => $groups !== [],
            'groups' => $groups,
            'rawJson' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
        ];
    }

    private function normalizeTechnicalInfo(array $data): array
    {
        if ($this->metricValue($data, 'input.duration_seconds') === null) {
            $durationMs = $this->metricValue($data, 'input.duration_ms') ?? $this->metricValue($data, 'audio.duration_ms');
            $this->setMetricValue(
                $data,
                'input.duration_seconds',
                $durationMs !== null
                    ? TranscriptTimeNormalizer::secondsMetricFromMilliseconds($durationMs)
                    : $this->metricValue($data, 'audio.duration_seconds')
            );
        }
        if ($this->metricValue($data, 'input.channels') === null) {
            $this->setMetricValue($data, 'input.channels', $this->metricValue($data, 'audio.channels'));
        }
        if ($this->metricValue($data, 'pipeline.stereo_mode') === null) {
            $this->setMetricValue($data, 'pipeline.stereo_mode', $this->metricValue($data, 'audio.stereo_mode'));
        }
        if ($this->metricValue($data, 'pipeline.split_channels') === null) {
            $this->setMetricValue($data, 'pipeline.split_channels', $this->metricValue($data, 'audio.split_channels'));
        }
        if ($this->metricValue($data, 'pipeline.audio_normalize') === null) {
            $this->setMetricValue($data, 'pipeline.audio_normalize', $this->metricValue($data, 'audio.normalization'));
        }
        if ($this->metricValue($data, 'vad.detected_segments') === null) {
            $this->setMetricValue($data, 'vad.detected_segments', $this->metricValue($data, 'vad.segments'));
        }
        if (is_array($data['warnings'] ?? null)) {
            $this->setMetricValue($data, 'transcription.warnings', count($data['warnings']));
        }

        $rawDuration = $this->metricValue($data, 'input.duration_seconds');
        $shortRecordingThreshold = $this->metricValue($data, 'short_recording_threshold_seconds');
        if ($shortRecordingThreshold === null && $this->metricValue($data, 'short_recording_threshold_ms') !== null) {
            $shortRecordingThreshold = TranscriptTimeNormalizer::secondsMetricFromMilliseconds(
                $this->metricValue($data, 'short_recording_threshold_ms')
            );
        }
        $duration = $this->metricValue($data, 'short_recording') === true && is_numeric($shortRecordingThreshold)
            ? $this->normalizeDecimalWithExpected($rawDuration, (float)$shortRecordingThreshold)
            : $this->normalizeLargeDecimal($rawDuration);
        $this->setMetricValue($data, 'input.duration_seconds', $duration);
        $channelsCount = max(1, (int)($this->metricValue($data, 'input.channels') ?: 1));
        if ($this->metricValue($data, 'vad.speech_ms') !== null) {
            $this->setMetricValue(
                $data,
                'vad.speech_seconds',
                TranscriptTimeNormalizer::secondsMetricFromMilliseconds($this->metricValue($data, 'vad.speech_ms'))
            );
        }
        $rawSpeechRatio = $this->normalizeRatio($this->metricValue($data, 'vad.speech_ratio'));
        $speechSeconds = $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'vad.speech_seconds'),
            is_numeric($duration) && is_numeric($rawSpeechRatio) ? (float)$duration * $channelsCount * (float)$rawSpeechRatio : null
        );
        $this->setMetricValue($data, 'vad.speech_seconds', $speechSeconds);
        $speechRatio = is_numeric($duration) && (float)$duration > 0 && is_numeric($speechSeconds)
            ? (float)$speechSeconds / ((float)$duration * $channelsCount)
            : $this->normalizeDisplayRatio($rawSpeechRatio);
        if ($this->metricValue($data, 'vad.transcription_ms') !== null) {
            $this->setMetricValue(
                $data,
                'vad.transcription_seconds',
                TranscriptTimeNormalizer::secondsMetricFromMilliseconds($this->metricValue($data, 'vad.transcription_ms'))
            );
        }
        $this->setMetricValue($data, 'vad.transcription_seconds', $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'vad.transcription_seconds'),
            $speechSeconds
        ));
        $this->setMetricValue($data, 'vad.speech_ratio', $speechRatio);
        $rawTimelineRatio = $this->normalizeRatio($this->metricValue($data, 'vad.timeline_speech_ratio'));
        $timelineSpeechSeconds = $this->metricValue($data, 'vad.timeline_speech_seconds');
        if ($timelineSpeechSeconds === null) {
            $timelineSpeechMs = $this->metricValue($data, 'vad.timeline_speech_ms');
            if ($timelineSpeechMs !== null) {
                $timelineSpeechSeconds = TranscriptTimeNormalizer::secondsMetricFromMilliseconds($timelineSpeechMs);
            }
        }
        if ($timelineSpeechSeconds === null) {
            $timelineSpeechSeconds = $this->timelineSpeechSecondsFromTechnicalInfo($data);
        }
        if ($timelineSpeechSeconds !== null) {
            $timelineSpeechSeconds = $this->normalizeDecimalWithExpected(
                $timelineSpeechSeconds,
                is_numeric($duration) && is_numeric($rawTimelineRatio) ? (float)$duration * (float)$rawTimelineRatio : null
            );
            $this->setMetricValue($data, 'vad.timeline_speech_seconds', $timelineSpeechSeconds);
        }
        $timelineRatio = is_numeric($duration) && (float)$duration > 0 && is_numeric($timelineSpeechSeconds)
            ? (float)$timelineSpeechSeconds / (float)$duration
            : $this->normalizeDisplayRatio($rawTimelineRatio);
        if ($timelineRatio !== null) {
            $this->setMetricValue($data, 'vad.timeline_speech_ratio', $timelineRatio);
        }
        if ($this->metricValue($data, 'timing.elapsed_ms') !== null) {
            $this->setMetricValue(
                $data,
                'timing.elapsed_seconds',
                TranscriptTimeNormalizer::secondsMetricFromMilliseconds($this->metricValue($data, 'timing.elapsed_ms'))
            );
        }
        $rawRealTimeFactor = $this->normalizeRatio(
            $this->metricValue($data, 'timing.real_time_factor'),
            true
        );
        $elapsedExpected = is_numeric($duration) && is_numeric($rawRealTimeFactor)
            ? (float)$duration * (float)$rawRealTimeFactor
            : null;
        $elapsedSeconds = $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'timing.elapsed_seconds'),
            $elapsedExpected
        );
        $this->setMetricValue($data, 'timing.elapsed_seconds', $elapsedSeconds);
        $realTimeFactor = is_numeric($duration) && (float)$duration > 0 && is_numeric($elapsedSeconds)
            ? (float)$elapsedSeconds / (float)$duration
            : $this->normalizeDisplayRatio($rawRealTimeFactor, true);
        $this->setMetricValue($data, 'timing.real_time_factor', $realTimeFactor);
        if ($this->metricValue($data, 'timing.stt_command_ms') !== null) {
            $this->setMetricValue(
                $data,
                'timing.stt_command_seconds',
                TranscriptTimeNormalizer::secondsMetricFromMilliseconds($this->metricValue($data, 'timing.stt_command_ms'))
            );
        }
        $this->setMetricValue($data, 'timing.stt_command_seconds', $this->normalizeDecimalWithExpected(
            $this->metricValue($data, 'timing.stt_command_seconds'),
            $elapsedSeconds
        ));

        $votes = $this->metricValue($data, 'language.votes_seconds');
        if (is_array($votes)) {
            foreach ($votes as $language => $seconds) {
                $votes[$language] = $this->normalizeLargeDecimal($seconds, $duration);
            }
            $this->setMetricValue($data, 'language.votes_seconds', $votes);
        }

        $channels = $this->metricValue($data, 'vad.channels');
        if (is_array($channels)) {
            foreach ($channels as $channel => $stats) {
                if (!is_array($stats)) {
                    continue;
                }
                $rawChannelSpeechRatio = $this->normalizeRatio($stats['speech_ratio'] ?? null);
                if (isset($stats['duration_ms'])) {
                    $stats['duration_seconds'] = TranscriptTimeNormalizer::secondsMetricFromMilliseconds($stats['duration_ms']);
                }
                if (isset($stats['speech_ms'])) {
                    $stats['speech_seconds'] = TranscriptTimeNormalizer::secondsMetricFromMilliseconds($stats['speech_ms']);
                }
                if (isset($stats['transcription_ms'])) {
                    $stats['transcription_seconds'] = TranscriptTimeNormalizer::secondsMetricFromMilliseconds($stats['transcription_ms']);
                }
                $stats['duration_seconds'] = $this->normalizeDecimalWithExpected($stats['duration_seconds'] ?? null, $duration);
                $stats['speech_seconds'] = $this->normalizeDecimalWithExpected(
                    $stats['speech_seconds'] ?? null,
                    is_numeric($stats['duration_seconds']) && is_numeric($rawChannelSpeechRatio)
                        ? (float)$stats['duration_seconds'] * (float)$rawChannelSpeechRatio
                        : null
                );
                $stats['speech_ratio'] = is_numeric($stats['duration_seconds'] ?? null)
                    && (float)$stats['duration_seconds'] > 0
                    && is_numeric($stats['speech_seconds'] ?? null)
                        ? (float)$stats['speech_seconds'] / (float)$stats['duration_seconds']
                        : $this->normalizeDisplayRatio($rawChannelSpeechRatio);
                $stats['transcription_seconds'] = $this->normalizeDecimalWithExpected(
                    $stats['transcription_seconds'] ?? null,
                    $stats['speech_seconds'] ?? null
                );
                $channels[$channel] = $stats;
            }
            $this->setMetricValue($data, 'vad.channels', $channels);
        }

        return $data;
    }

    private function timelineSpeechSecondsFromTechnicalInfo(array $data): ?float
    {
        $channels = $this->metricValue($data, 'vad.channels');
        if (!is_array($channels)) {
            return null;
        }

        $windows = [];
        foreach ($channels as $stats) {
            if (!is_array($stats) || !is_array($stats['speech_windows'] ?? null)) {
                continue;
            }

            foreach ($stats['speech_windows'] as $window) {
                if (!is_array($window) || count($window) < 2) {
                    continue;
                }

                $start = $window[0] ?? null;
                $end = $window[1] ?? null;
                if (!is_numeric($start) || !is_numeric($end) || (float)$end <= (float)$start) {
                    continue;
                }

                $windows[] = [(float)$start, (float)$end];
            }
        }

        if ($windows === []) {
            return null;
        }

        usort($windows, static fn (array $left, array $right): int => $left[0] <=> $right[0] ?: $left[1] <=> $right[1]);
        [$currentStart, $currentEnd] = $windows[0];
        $total = 0.0;
        foreach (array_slice($windows, 1) as [$start, $end]) {
            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);
                continue;
            }

            $total += $currentEnd - $currentStart;
            $currentStart = $start;
            $currentEnd = $end;
        }

        return round($total + $currentEnd - $currentStart, 3);
    }

    private function technicalRow(string $labelKey, string $value): ?array
    {
        if (trim($value) === '') {
            return null;
        }

        return [
            'label' => $this->translation->_($labelKey),
            'value' => $value,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function technicalChannelRows(array $data): array
    {
        $channels = $this->metricValue($data, 'vad.channels');
        if (!is_array($channels)) {
            return [];
        }

        $rows = [];
        foreach ($channels as $channel => $stats) {
            if (!is_array($stats)) {
                continue;
            }

            $parts = array_values(array_filter([
                $this->numberValue($stats['detected_segments'] ?? null) !== '' ? sprintf(
                    '%s: %s',
                    $this->translation->_('module_local_speech_to_text_TechnicalVadSegmentsShort'),
                    $this->numberValue($stats['detected_segments'] ?? null)
                ) : '',
                $this->secondsValue($stats['speech_seconds'] ?? null) !== '' ? sprintf(
                    '%s: %s',
                    $this->translation->_('module_local_speech_to_text_TechnicalSpeechShort'),
                    $this->secondsValue($stats['speech_seconds'] ?? null)
                ) : '',
                $this->percentValue($stats['speech_ratio'] ?? null) !== '' ? sprintf(
                    '%s: %s',
                    $this->translation->_('module_local_speech_to_text_TechnicalSpeechRatioShort'),
                    $this->percentValue($stats['speech_ratio'] ?? null)
                ) : '',
            ]));

            if ($parts === []) {
                continue;
            }

            $rows[] = [
                'label' => (string)$channel,
                'value' => implode(' / ', $parts),
            ];
        }

        return $rows;
    }

    private function stringMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function numberMetric(array $data, string $path): string
    {
        return $this->numberValue($this->metricValue($data, $path));
    }

    private function secondsMetric(array $data, string $path): string
    {
        return $this->secondsValue($this->metricValue($data, $path));
    }

    private function percentMetric(array $data, string $path): string
    {
        return $this->percentValue($this->metricValue($data, $path));
    }

    private function languageVotesMetric(array $data): string
    {
        $votes = $this->metricValue($data, 'language.votes_seconds');
        if (!is_array($votes) || $votes === []) {
            return '';
        }

        arsort($votes, SORT_NUMERIC);
        $parts = [];
        foreach ($votes as $language => $seconds) {
            if (!is_numeric($seconds)) {
                continue;
            }
            $parts[] = sprintf('%s: %s', (string)$language, $this->secondsValue($seconds));
        }

        return implode(' / ', $parts);
    }

    private function qualityReasonsMetric(array $data): string
    {
        $reasons = $this->metricValue($data, 'quality.reasons');
        if (!is_array($reasons) || $reasons === []) {
            return '';
        }

        arsort($reasons, SORT_NUMERIC);
        $parts = [];
        foreach ($reasons as $reason => $count) {
            if (!is_numeric($count)) {
                continue;
            }
            $parts[] = sprintf('%s: %s', (string)$reason, $this->numberValue($count));
        }

        return implode(' / ', $parts);
    }

    private function ratioMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);
        if (!is_numeric($value)) {
            return '';
        }

        return sprintf('%.3fx', (float)$value);
    }

    private function boolMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);
        if (!is_bool($value)) {
            return '';
        }

        return $value
            ? $this->translation->_('module_local_speech_to_text_Yes')
            : $this->translation->_('module_local_speech_to_text_No');
    }

    private function timestampMetric(array $data, string $path): string
    {
        $value = $this->metricValue($data, $path);
        if (!is_numeric($value) || (int)$value <= 0) {
            return '';
        }

        return date('Y-m-d H:i:s', (int)$value);
    }

    private function numberValue(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $number = (float)$value;
        if (floor($number) === $number) {
            return (string)(int)$number;
        }

        return rtrim(rtrim(sprintf('%.3f', $number), '0'), '.');
    }

    private function secondsValue(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        return sprintf('%s %s', $this->numberValue($value), $this->translation->_('module_local_speech_to_text_SecondsShort'));
    }

    private function percentValue(mixed $value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        return sprintf('%.1f%%', (float)$value * 100);
    }

    private function normalizeLargeDecimal(mixed $value, mixed $maxReference = null): mixed
    {
        return TranscriptTimeNormalizer::normalizeLargeDecimal($value, $maxReference);
    }

    private function normalizeDecimalWithExpected(mixed $value, mixed $expected): mixed
    {
        return TranscriptTimeNormalizer::normalizeDecimalWithExpected($value, $expected);
    }

    private function normalizeRatio(mixed $value, bool $allowAboveOne = false): mixed
    {
        return TranscriptTimeNormalizer::normalizeRatio($value, $allowAboveOne);
    }

    private function normalizeDisplayRatio(mixed $value, bool $allowAboveOne = false): mixed
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $ratio = (float)$value;
        if ($ratio > 10) {
            $ratio /= 1000;
        }
        if (!$allowAboveOne && $ratio > 1) {
            $ratio = min(1.0, $ratio);
        }

        return $ratio;
    }

    private function metricValue(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    private function setMetricValue(array &$data, string $path, mixed $newValue): void
    {
        $parts = explode('.', $path);
        $value = &$data;
        $lastIndex = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if (!is_array($value)) {
                return;
            }
            if (!array_key_exists($part, $value)) {
                $value[$part] = $index === $lastIndex ? null : [];
            }
            $value = &$value[$part];
        }

        $value = $newValue;
    }

}