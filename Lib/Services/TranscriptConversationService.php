<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Lib\TranscriptTimeNormalizer;

class TranscriptConversationService
{
    private const float TRANSCRIPT_TURN_MAX_GAP_SECONDS = 2.0;
    private const float TRANSCRIPT_TURN_MAX_DURATION_SECONDS = 45.0;
    private const int TRANSCRIPT_TURN_MAX_TEXT_CHARS = 700;
    private const float TRANSCRIPT_SILENCE_MIN_SECONDS = 10.0;
    private const float TRANSCRIPT_OVERLAP_MIN_SECONDS = 1.0;

    public function __construct(private mixed $translation)
    {
    }

    public function conversationFromTranscript(string $transcript): array
    {
        $segments = json_decode($transcript, true);
        if (!is_array($segments)) {
            return $this->emptyConversation();
        }

        $speakers = [
            'left' => $this->speakerColumn('left', $this->translation->_('module_local_speech_to_text_Speaker1')),
            'right' => $this->speakerColumn('right', $this->translation->_('module_local_speech_to_text_Speaker2')),
        ];

        $utterances = [];

        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $speakerKey = $this->speakerKey($segment);
            if (!isset($speakers[$speakerKey])) {
                $speakers[$speakerKey] = $this->speakerColumn($speakerKey, $this->speakerLabel($speakerKey, count($speakers) + 1));
            }

            [$start, $end] = TranscriptTimeNormalizer::segmentRangeSeconds($segment);
            $orderTime = $start > 0 ? $start : $end;
            $utterance = [
                'text' => $text,
                'speakerKey' => $speakerKey,
                'speakerLabel' => $speakers[$speakerKey]['label'],
                'start' => $start,
                'end' => $end,
                'orderTime' => $orderTime,
            ];
            $utterances[] = $utterance;
        }

        usort(
            $utterances,
            static fn (array $left, array $right): int => [$left['orderTime'], $left['start'], $left['end']]
                <=> [$right['orderTime'], $right['start'], $right['end']]
        );

        $timeline = $this->coalescedConversationTurns($utterances);
        $durationSeconds = 0.0;
        foreach ($timeline as $turn) {
            $durationSeconds = max($durationSeconds, (float)$turn['end']);
        }

        $coveredUntil = 0.0;
        foreach ($timeline as $index => &$turn) {
            $start = (float)$turn['start'];
            $end = (float)$turn['end'];
            $gap = max(0.0, $start - $coveredUntil);
            $turn['endLabel'] = $this->timeLabel((float)$turn['orderTime']);
            $turn['startLabel'] = $this->timeLabel($start);
            $turn['rangeLabel'] = $this->rangeLabel($start, $end);
            $turn['durationLabel'] = $this->timeLabel(max(0.0, $end - $start));
            $turn['gapBeforeLabel'] = $gap > self::TRANSCRIPT_SILENCE_MIN_SECONDS
                ? $this->timeLabel($gap)
                : '';
            $turn['overlap'] = $this->turnOverlaps($turn, $timeline, $index);
            $turn['startPercent'] = $this->timelinePercent($start, $durationSeconds);
            $turn['widthPercent'] = $this->timelinePercent(max(0.0, $end - $start), $durationSeconds);
            $coveredUntil = max($coveredUntil, $end);
            $speakers[$turn['speakerKey']]['segments'][] = $turn;
        }
        unset($turn);

        foreach ($speakers as &$speaker) {
            usort(
                $speaker['segments'],
                static fn (array $left, array $right): int => [$left['orderTime'], $left['start']]
                    <=> [$right['orderTime'], $right['start']]
            );
        }
        unset($speaker);

        usort(
            $timeline,
            static fn (array $left, array $right): int => [$left['orderTime'], $left['start'], $left['end']]
                <=> [$right['orderTime'], $right['start'], $right['end']]
        );

        return [
            'speakers' => array_values($speakers),
            'timeline' => $timeline,
            'hasSegments' => count($timeline) > 0,
            'turnCount' => count($timeline),
            'durationSeconds' => $durationSeconds,
            'durationLabel' => $this->timeLabel($durationSeconds),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $utterances
     * @return array<int, array<string, mixed>>
     */
    private function coalescedConversationTurns(array $utterances): array
    {
        $turns = [];
        $current = null;

        foreach ($utterances as $utterance) {
            if ($current === null || !$this->canAppendConversationTurn($current, $utterance)) {
                if ($current !== null) {
                    $turns[] = $current;
                }
                $current = $utterance;
                continue;
            }

            $current = $this->appendConversationTurn($current, $utterance);
        }

        if ($current !== null) {
            $turns[] = $current;
        }

        return $turns;
    }

    /**
     * @param array<string, mixed> $turn
     * @param array<string, mixed> $utterance
     */
    private function canAppendConversationTurn(array $turn, array $utterance): bool
    {
        if ((string)($turn['speakerKey'] ?? '') !== (string)($utterance['speakerKey'] ?? '')) {
            return false;
        }

        $turnStart = (float)($turn['start'] ?? 0.0);
        $turnEnd = (float)($turn['end'] ?? $turnStart);
        $utteranceStart = (float)($utterance['start'] ?? 0.0);
        $utteranceEnd = (float)($utterance['end'] ?? $utteranceStart);
        if ($utteranceStart - $turnEnd > self::TRANSCRIPT_TURN_MAX_GAP_SECONDS) {
            return false;
        }
        if ($utteranceEnd - $turnStart > self::TRANSCRIPT_TURN_MAX_DURATION_SECONDS) {
            return false;
        }

        $text = $this->appendTranscriptText((string)($turn['text'] ?? ''), (string)($utterance['text'] ?? ''));
        return $this->textLength($text) <= self::TRANSCRIPT_TURN_MAX_TEXT_CHARS;
    }

    /**
     * @param array<string, mixed> $turn
     * @param array<string, mixed> $utterance
     * @return array<string, mixed>
     */
    private function appendConversationTurn(array $turn, array $utterance): array
    {
        $turn['text'] = $this->appendTranscriptText((string)$turn['text'], (string)$utterance['text']);
        $turn['start'] = min((float)$turn['start'], (float)$utterance['start']);
        $turn['end'] = max((float)$turn['end'], (float)$utterance['end']);
        $turn['orderTime'] = (float)$turn['start'] > 0 ? (float)$turn['start'] : (float)$turn['end'];

        return $turn;
    }

    private function appendTranscriptText(string $left, string $right): string
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
     * @return array<string, mixed>
     */
    private function emptyConversation(): array
    {
        return [
            'speakers' => [
                $this->speakerColumn('left', $this->translation->_('module_local_speech_to_text_Speaker1')),
                $this->speakerColumn('right', $this->translation->_('module_local_speech_to_text_Speaker2')),
            ],
            'timeline' => [],
            'hasSegments' => false,
            'turnCount' => 0,
            'durationSeconds' => 0.0,
            'durationLabel' => '0:00',
        ];
    }

    /**
     * @param array<string, mixed> $turn
     * @param array<int, array<string, mixed>> $timeline
     */
    private function turnOverlaps(array $turn, array $timeline, int $turnIndex): bool
    {
        $start = (float)$turn['start'];
        $end = (float)$turn['end'];
        foreach ($timeline as $index => $other) {
            if ($index === $turnIndex || $other['speakerKey'] === $turn['speakerKey']) {
                continue;
            }
            $overlap = min($end, (float)$other['end']) - max($start, (float)$other['start']);
            if ($overlap > self::TRANSCRIPT_OVERLAP_MIN_SECONDS) {
                return true;
            }
        }

        return false;
    }

    private function timelinePercent(float $seconds, float $durationSeconds): float
    {
        if ($durationSeconds <= 0) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, ($seconds / $durationSeconds) * 100)), 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function speakerColumn(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'segments' => [],
        ];
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function speakerKey(array $segment): string
    {
        $speaker = strtolower(trim((string)($segment['speaker'] ?? '')));
        $channel = strtolower(trim((string)($segment['channel'] ?? '')));
        $key = $speaker !== '' ? $speaker : $channel;

        if (in_array($key, ['speaker 1', 'speaker_1', 'speaker1', 'left'], true)) {
            return 'left';
        }
        if (in_array($key, ['speaker 2', 'speaker_2', 'speaker2', 'right'], true)) {
            return 'right';
        }

        return $key === '' ? 'left' : (string)preg_replace('/[^a-z0-9_-]+/', '-', $key);
    }

    private function speakerLabel(string $speakerKey, int $number): string
    {
        if ($speakerKey === 'left') {
            return $this->translation->_('module_local_speech_to_text_Speaker1');
        }
        if ($speakerKey === 'right') {
            return $this->translation->_('module_local_speech_to_text_Speaker2');
        }

        return sprintf('%s %d', $this->translation->_('module_local_speech_to_text_Speaker'), $number);
    }

    private function floatValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        return 0.0;
    }

    private function normalizedSecondsValue(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, (float)$value) : 0.0;
    }

    private function timeLabel(float $seconds): string
    {
        $totalSeconds = (int)floor(max(0.0, $seconds));
        $minutes = intdiv($totalSeconds, 60);
        $rest = $totalSeconds % 60;

        return sprintf('%d:%02d', $minutes, $rest);
    }

    private function rangeLabel(float $start, float $end): string
    {
        if ($end <= $start) {
            return $this->timeLabel($start);
        }

        return sprintf('%s - %s', $this->timeLabel($start), $this->timeLabel($end));
    }

    /**
     * @return array<string, mixed>
     */
}
