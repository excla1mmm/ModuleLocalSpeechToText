<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/CallTranscriptTurnProjector.php';

/**
 * Storage format of call transcript revision payloads
 * (m_STTCallTranscriptRevision.payload, m_STTCallTranscript.pending_payload).
 *
 * The assembler output carries presentation data that every reader rebuilds
 * from the raw segments anyway (turns, turn_count, text) plus per-segment
 * fields no reader consumes (normalized_text, recording_fingerprint,
 * resolution_status, source_index — the last three are recoverable from the
 * part manifest / segment_id). encode() drops them and deflates the JSON;
 * decode() accepts both this compact form and legacy plain JSON and restores
 * the presentation fields, so readers see the same shape as before.
 *
 * content_hash / parts_hash are computed by the assembler from semantic
 * fields only and are never derived from the stored payload, so re-encoding
 * a stored payload never changes revision identity.
 */
final class CallTranscriptPayloadCodec
{
    public const string COMPRESSED_PREFIX = 'z1:';

    private const array DERIVED_SEGMENT_FIELDS = [
        'normalized_text',
        'recording_fingerprint',
        'resolution_status',
        'source_index',
    ];
    private const array DERIVED_TRANSCRIPT_FIELDS = ['turns', 'turn_count', 'text'];
    private const int COMPRESSION_LEVEL = 6;
    private const int MAX_INFLATED_BYTES = 67_108_864;

    public function encode(array $payload): string
    {
        $json = json_encode(
            $this->compact($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '[]';
        if (!function_exists('gzdeflate')) {
            return $json;
        }
        $deflated = gzdeflate($json, self::COMPRESSION_LEVEL);
        if (!is_string($deflated)) {
            return $json;
        }
        $encoded = self::COMPRESSED_PREFIX . base64_encode($deflated);

        return strlen($encoded) < strlen($json) ? $encoded : $json;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException when the stored JSON is malformed
     * @throws RuntimeException when a compressed payload cannot be inflated
     */
    public function decode(string $stored): array
    {
        $decoded = json_decode($this->json($stored), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Call transcript payload is not a JSON object');
        }

        return $this->rehydrate($decoded);
    }

    /** @return array<string, mixed> */
    public function decodeOrEmpty(string $stored): array
    {
        try {
            return $this->decode($stored);
        } catch (JsonException|RuntimeException) {
            return [];
        }
    }

    public function isCompact(string $stored): bool
    {
        return str_starts_with($stored, self::COMPRESSED_PREFIX);
    }

    /**
     * Re-encodes a stored legacy payload into the compact form.
     * Returns null when the value is already compact, empty or undecodable,
     * so callers can leave such rows untouched (idempotent migration).
     */
    public function recode(string $stored): ?string
    {
        if ($stored === '' || $this->isCompact($stored)) {
            return null;
        }
        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        $encoded = $this->encode($decoded);

        return $encoded === $stored ? null : $encoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function compact(array $payload): array
    {
        if (!is_array($payload['transcript'] ?? null)) {
            return $payload;
        }
        $transcript = $payload['transcript'];
        foreach (self::DERIVED_TRANSCRIPT_FIELDS as $field) {
            unset($transcript[$field]);
        }
        if (is_array($transcript['segments'] ?? null)) {
            $transcript['segments'] = array_map(
                static function (mixed $segment): mixed {
                    if (!is_array($segment)) {
                        return $segment;
                    }
                    foreach (self::DERIVED_SEGMENT_FIELDS as $field) {
                        unset($segment[$field]);
                    }

                    return $segment;
                },
                $transcript['segments']
            );
        }
        $payload['transcript'] = $transcript;

        return $payload;
    }

    private function json(string $stored): string
    {
        if (!$this->isCompact($stored)) {
            return $stored;
        }
        if (!function_exists('gzinflate')) {
            throw new RuntimeException('zlib is required to read compressed call transcript payloads');
        }
        $deflated = base64_decode(substr($stored, strlen(self::COMPRESSED_PREFIX)), true);
        $json = is_string($deflated) ? @gzinflate($deflated, self::MAX_INFLATED_BYTES) : false;
        if (!is_string($json)) {
            throw new RuntimeException('Compressed call transcript payload is corrupted');
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function rehydrate(array $payload): array
    {
        if (!is_array($payload['transcript'] ?? null) || array_key_exists('turns', $payload['transcript'])) {
            return $payload;
        }
        $transcript = (new CallTranscriptTurnProjector())->project($payload['transcript']);
        $transcript['turns'] ??= [];
        $transcript['turn_count'] ??= count($transcript['turns']);
        $transcript['text'] ??= '';
        $payload['transcript'] = $transcript;

        return $payload;
    }
}
