<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

class TranscriptUserJsonService
{
    public function __construct(private mixed $translation)
    {
    }

    /**
     * @param array<string, mixed> $call
     */
    public function transcriptJson(array $call, string $transcript): string
    {
        $payload = [
            'call' => $this->normalizedCall($call),
            'phrases' => $this->phrases($transcript),
        ];

        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT
                | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '';
    }

    public function transcriptText(string $transcript): string
    {
        $lines = array_map(
            static fn (array $phrase): string => $phrase['speaker'] . ': ' . $phrase['text'],
            $this->phrases($transcript)
        );

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, string|float|null>
     */
    private function normalizedCall(array $call): array
    {
        return [
            'id' => $this->nullableString($call['id'] ?? null),
            'started_at' => $this->nullableString($call['started_at'] ?? null),
            'ended_at' => $this->nullableString($call['ended_at'] ?? null),
            'duration_seconds' => is_numeric($call['duration_seconds'] ?? null)
                ? (float)$call['duration_seconds']
                : null,
            'source_number' => $this->nullableString($call['source_number'] ?? null),
            'destination_number' => $this->nullableString($call['destination_number'] ?? null),
            'language' => $this->nullableString($call['language'] ?? null),
            'model' => $this->nullableString($call['model'] ?? null),
        ];
    }

    /**
     * @return array<int, array{speaker: string, start_ms: int|null, end_ms: int|null, text: string}>
     */
    private function phrases(string $transcript): array
    {
        $segments = json_decode($transcript, true);
        if (!is_array($segments)) {
            return [];
        }

        $phrases = [];
        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $text = trim((string)($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $phrases[] = [
                'speaker' => $this->speakerLabel($segment),
                'start_ms' => $this->milliseconds($segment['start_ms'] ?? null),
                'end_ms' => $this->milliseconds($segment['end_ms'] ?? null),
                'text' => $text,
            ];
        }

        return $phrases;
    }

    /**
     * @param array<string, mixed> $segment
     */
    private function speakerLabel(array $segment): string
    {
        $speaker = $this->nullableString($segment['speaker'] ?? null)
            ?? $this->nullableString($segment['channel'] ?? null);
        $normalized = strtolower((string)$speaker);

        return match ($normalized) {
            'left' => $this->translation->_('module_local_speech_to_text_Speaker1'),
            'right' => $this->translation->_('module_local_speech_to_text_Speaker2'),
            '' => $this->translation->_('module_local_speech_to_text_Speaker'),
            default => (string)$speaker,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private function milliseconds(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }
}
