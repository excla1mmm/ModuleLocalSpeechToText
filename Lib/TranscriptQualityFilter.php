<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib;

final class TranscriptQualityFilter
{
    private const float MAX_DENSE_CHARS_PER_SECOND = 320.0;
    private const int DENSE_TRANSCRIPT_MINIMUM_CHARACTERS = 240;
    private const float NEAR_ZERO_DURATION_THRESHOLD = 0.15;
    private const int NEAR_ZERO_DURATION_MINIMUM_CHARACTERS = 180;
    private const int REPEATED_TOKEN_MINIMUM_COUNT = 8;
    private const float REPEATED_TOKEN_MINIMUM_RATIO = 0.70;
    private const int REPEATED_NGRAM_MINIMUM_COUNT = 8;
    private const float REPEATED_NGRAM_MINIMUM_RATIO = 0.45;
    private const int REPEATED_SUBSTRING_MINIMUM_COUNT = 10;
    private const int REPEATED_CHARACTER_MINIMUM_RUN = 18;
    private const float PUNCTUATION_FLOOD_RATIO = 0.80;
    private const float RUSSIAN_SHORT_WRONG_SCRIPT_MAX_SECONDS = 5.0;
    private const int RUSSIAN_SHORT_WRONG_SCRIPT_MAX_CHARS = 56;
    private const int RUSSIAN_SHORT_WRONG_SCRIPT_MAX_TOKENS = 7;

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return array{segments: array<int, array<string, mixed>>, stats: array<string, mixed>}
     */
    public static function filterSegments(array $segments, ?string $languageHint = null): array
    {
        $filtered = [];
        $languageHint = self::normalizeLanguageHint($languageHint);
        $stats = [
            'raw_segments' => count($segments),
            'submitted_segments' => 0,
            'rejected_segments' => 0,
            'trimmed_segments' => 0,
            'reasons' => [],
        ];

        foreach ($segments as $segment) {
            $text = trim((string)($segment['text'] ?? ''));
            $duration = self::segmentDuration($segment);
            $decision = self::cleanedTranscriptText($text, $duration, $languageHint);

            if ($decision['text'] === null || trim($decision['text']) === '') {
                $stats['rejected_segments']++;
                if ($decision['reason'] !== null) {
                    $stats['reasons'][] = $decision['reason'];
                }
                continue;
            }

            if ($decision['reason'] !== null) {
                $stats['trimmed_segments']++;
                $stats['reasons'][] = $decision['reason'];
            }

            $segment['text'] = $decision['text'];
            $filtered[] = $segment;
        }

        $stats['submitted_segments'] = count($filtered);
        $stats['reasons'] = array_values(array_unique($stats['reasons']));

        return [
            'segments' => $filtered,
            'stats' => $stats,
        ];
    }

    /**
     * @param array<string, mixed> $segment
     */
    private static function segmentDuration(array $segment): float
    {
        if (!is_numeric($segment['start_ms'] ?? null) || !is_numeric($segment['end_ms'] ?? null)) {
            return 0.0;
        }

        return max(0.0, ((float)$segment['end_ms'] - (float)$segment['start_ms']) / 1000.0);
    }

    /**
     * @return array{text: ?string, reason: ?string}
     */
    private static function cleanedTranscriptText(string $text, float $duration, string $languageHint): array
    {
        $candidate = trim($text);
        if ($candidate === '') {
            return ['text' => null, 'reason' => 'empty_text'];
        }
        if (str_contains($candidate, "\u{FFFD}")) {
            return ['text' => null, 'reason' => 'replacement_character'];
        }

        $trimReason = null;
        $trim = self::repeatedSuffixTrim($candidate);
        if ($trim !== null) {
            if (!self::isMeaningfulTranscriptPrefix($trim['text'])) {
                return [
                    'text' => null,
                    'reason' => str_replace('trimmed_', '', $trim['reason']),
                ];
            }
            $candidate = $trim['text'];
            $trimReason = $trim['reason'];
        }
        $trim = self::repeatedPrefixTrim($candidate);
        if ($trim !== null) {
            if (!self::isMeaningfulTranscriptRemainder($trim['text'])) {
                return [
                    'text' => null,
                    'reason' => str_replace('trimmed_', '', $trim['reason']),
                ];
            }
            $candidate = $trim['text'];
            $trimReason = $trim['reason'];
        }

        $reason = self::transcriptRejectionReason($candidate, $duration, $languageHint);
        if ($reason !== null) {
            return ['text' => null, 'reason' => $reason];
        }

        return ['text' => $candidate, 'reason' => $trimReason];
    }

    private static function transcriptRejectionReason(string $text, float $duration, string $languageHint): ?string
    {
        if (self::hasDenseTranscript($text, $duration)) {
            return 'text_density';
        }
        if (self::hasHallucinationMarker($text)) {
            return 'hallucination_marker';
        }
        if (self::hasPunctuationFlood($text)) {
            return 'punctuation_flood';
        }
        if (self::hasSingleLetterTranscript($text)) {
            return 'single_letter';
        }
        if (self::hasRepeatedCharacterRun($text)) {
            return 'repeated_character';
        }
        if (self::hasRepeatedSubstringRun($text)) {
            return 'repeated_substring';
        }
        if (self::hasWrongScriptForRussian($text, $duration, $languageHint)) {
            return 'wrong_script';
        }
        if (self::hasRepeatedTokenFlood($text)) {
            return 'repeated_token';
        }

        $tokens = array_column(self::transcriptTokens($text), 'value');
        if (self::hasRepeatedNgram($tokens, 2) || self::hasRepeatedNgram($tokens, 3)) {
            return 'repeated_ngram';
        }

        return null;
    }

    private static function normalizeLanguageHint(?string $languageHint): string
    {
        $languageHint = strtolower(trim((string)$languageHint));
        $isRussian = in_array($languageHint, ['ru', 'rus', 'russian'], true)
            || str_starts_with($languageHint, 'ru-')
            || str_starts_with($languageHint, 'ru_');

        return $isRussian ? 'ru' : '';
    }

    /**
     * @return ?array{text: string, reason: string}
     */
    private static function repeatedSuffixTrim(string $text): ?array
    {
        $tokens = self::transcriptTokens($text);
        if (count($tokens) < self::REPEATED_TOKEN_MINIMUM_COUNT) {
            return null;
        }

        $trim = self::repeatedSuffixTrimFor($text, $tokens, 1, 6, 'repeated_token');
        if ($trim !== null) {
            return $trim;
        }

        $maxPhraseSize = min(4, intdiv(count($tokens), 4));
        for ($phraseSize = 2; $phraseSize <= $maxPhraseSize; $phraseSize++) {
            $trim = self::repeatedSuffixTrimFor($text, $tokens, $phraseSize, 4, 'repeated_phrase');
            if ($trim !== null) {
                return $trim;
            }
        }

        return null;
    }

    /**
     * @return ?array{text: string, reason: string}
     */
    private static function repeatedPrefixTrim(string $text): ?array
    {
        $tokens = self::transcriptTokens($text);
        if (count($tokens) < self::REPEATED_TOKEN_MINIMUM_COUNT) {
            return null;
        }

        $trim = self::repeatedPrefixTrimFor($text, $tokens, 1, 6, 'leading_repeated_token');
        if ($trim !== null) {
            return $trim;
        }

        $maxPhraseSize = min(4, intdiv(count($tokens), 4));
        for ($phraseSize = 2; $phraseSize <= $maxPhraseSize; $phraseSize++) {
            $trim = self::repeatedPrefixTrimFor($text, $tokens, $phraseSize, 4, 'leading_repeated_phrase');
            if ($trim !== null) {
                return $trim;
            }
        }

        return null;
    }

    /**
     * @param array<int, array{value: string, offset: int, length: int}> $tokens
     * @return ?array{text: string, reason: string}
     */
    private static function repeatedPrefixTrimFor(
        string $text,
        array $tokens,
        int $phraseSize,
        int $minimumCount,
        string $reason
    ): ?array {
        $tokenCount = count($tokens);
        if ($tokenCount < $phraseSize * $minimumCount) {
            return null;
        }

        $phrase = array_column(array_slice($tokens, 0, $phraseSize), 'value');
        if ($phraseSize === 1 && mb_strlen($phrase[0], 'UTF-8') < 2) {
            return null;
        }
        if ($phraseSize > 1 && count(array_unique($phrase)) === 1) {
            return null;
        }

        $count = 1;
        $cursor = $phraseSize;
        while ($cursor + $phraseSize <= $tokenCount) {
            $candidate = array_column(array_slice($tokens, $cursor, $phraseSize), 'value');
            if ($candidate !== $phrase) {
                break;
            }

            $count++;
            $cursor += $phraseSize;
        }

        if ($count < $minimumCount) {
            return null;
        }

        $runEnd = $count * $phraseSize;
        $repeatedRatio = $runEnd / max(1, $tokenCount);
        if ($runEnd < self::REPEATED_TOKEN_MINIMUM_COUNT && $repeatedRatio < 0.50) {
            return null;
        }

        if ($runEnd >= $tokenCount) {
            return [
                'text' => '',
                'reason' => 'trimmed_' . $reason,
            ];
        }

        $suffixStart = $tokens[$runEnd]['offset'];
        return [
            'text' => self::trimTranscriptBoundary(substr($text, $suffixStart)),
            'reason' => 'trimmed_' . $reason,
        ];
    }

    /**
     * @param array<int, array{value: string, offset: int, length: int}> $tokens
     * @return ?array{text: string, reason: string}
     */
    private static function repeatedSuffixTrimFor(
        string $text,
        array $tokens,
        int $phraseSize,
        int $minimumCount,
        string $reason
    ): ?array {
        $tokenCount = count($tokens);
        if ($tokenCount < $phraseSize * $minimumCount) {
            return null;
        }

        $phraseStart = $tokenCount - $phraseSize;
        $phrase = array_column(array_slice($tokens, $phraseStart, $phraseSize), 'value');
        if ($phraseSize === 1 && mb_strlen($phrase[0], 'UTF-8') < 2) {
            return null;
        }
        if ($phraseSize > 1 && count(array_unique($phrase)) === 1) {
            return null;
        }

        $count = 1;
        $cursor = $phraseStart - $phraseSize;
        while ($cursor >= 0) {
            $candidate = array_column(array_slice($tokens, $cursor, $phraseSize), 'value');
            if ($candidate !== $phrase) {
                break;
            }

            $count++;
            if ($cursor < $phraseSize) {
                break;
            }
            $cursor -= $phraseSize;
        }

        if ($count < $minimumCount) {
            return null;
        }

        $runStart = $tokenCount - $count * $phraseSize;
        $repeatedTokenCount = $tokenCount - $runStart;
        $repeatedRatio = $repeatedTokenCount / max(1, $tokenCount);
        if ($repeatedTokenCount < self::REPEATED_TOKEN_MINIMUM_COUNT && $repeatedRatio < 0.50) {
            return null;
        }

        $prefixEnd = $tokens[$runStart]['offset'];
        return [
            'text' => self::trimTranscriptBoundary(substr($text, 0, $prefixEnd)),
            'reason' => 'trimmed_' . $reason,
        ];
    }

    private static function isMeaningfulTranscriptPrefix(string $text): bool
    {
        return mb_strlen($text, 'UTF-8') >= 12 && count(self::transcriptTokens($text)) >= 3;
    }

    private static function isMeaningfulTranscriptRemainder(string $text): bool
    {
        return mb_strlen($text, 'UTF-8') >= 10 && count(self::transcriptTokens($text)) >= 2;
    }

    private static function trimTranscriptBoundary(string $text): string
    {
        return preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $text) ?? trim($text);
    }

    private static function hasDenseTranscript(string $text, float $duration): bool
    {
        $characterCount = mb_strlen($text, 'UTF-8');
        if ($duration <= self::NEAR_ZERO_DURATION_THRESHOLD) {
            return $characterCount >= self::NEAR_ZERO_DURATION_MINIMUM_CHARACTERS;
        }
        if ($characterCount < self::DENSE_TRANSCRIPT_MINIMUM_CHARACTERS) {
            return false;
        }

        return $characterCount / max(0.01, $duration) > self::MAX_DENSE_CHARS_PER_SECOND;
    }

    private static function hasHallucinationMarker(string $text): bool
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        foreach (['субтитры', 'subtitles', 'текст фильма', 'продолжение следует'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private static function hasPunctuationFlood(string $text): bool
    {
        $compact = preg_replace('/\s+/u', '', $text) ?? '';
        if (mb_strlen($compact, 'UTF-8') < 20) {
            return false;
        }

        preg_match_all('/[^\p{L}\p{N}]/u', $compact, $matches);
        return count($matches[0]) / max(1, mb_strlen($compact, 'UTF-8')) >= self::PUNCTUATION_FLOOD_RATIO;
    }

    private static function hasWrongScriptForRussian(string $text, float $duration, string $languageHint): bool
    {
        if ($languageHint !== 'ru') {
            return false;
        }

        $profile = self::scriptProfile($text);
        $foreignLetters = $profile['latin'] + $profile['other'];
        if ($profile['letters'] === 0 || $foreignLetters === 0) {
            return false;
        }

        if (self::isAllowedRussianTechnicalLatin($text, $profile['other'] > 0)) {
            return false;
        }

        $tokens = self::transcriptTokens($text);
        $tokenCount = count($tokens);
        $shortSegment = ($duration > 0.0 && $duration <= self::RUSSIAN_SHORT_WRONG_SCRIPT_MAX_SECONDS)
            || (
                mb_strlen($text, 'UTF-8') <= self::RUSSIAN_SHORT_WRONG_SCRIPT_MAX_CHARS
                && $tokenCount <= self::RUSSIAN_SHORT_WRONG_SCRIPT_MAX_TOKENS
            );

        if ($profile['cyrillic'] === 0) {
            return $shortSegment || $profile['other'] > 0 || $tokenCount <= self::RUSSIAN_SHORT_WRONG_SCRIPT_MAX_TOKENS;
        }

        $cyrillicRatio = $profile['cyrillic'] / max(1, $profile['letters']);
        if ($profile['other'] > 0 && $shortSegment) {
            return true;
        }

        return $shortSegment && $cyrillicRatio < 0.70;
    }

    /**
     * @return array{letters: int, cyrillic: int, latin: int, other: int}
     */
    private static function scriptProfile(string $text): array
    {
        preg_match_all('/\p{L}/u', $text, $letterMatches);
        preg_match_all('/\p{Cyrillic}/u', $text, $cyrillicMatches);
        preg_match_all('/\p{Latin}/u', $text, $latinMatches);
        $letters = count($letterMatches[0] ?? []);
        $cyrillic = count($cyrillicMatches[0] ?? []);
        $latin = count($latinMatches[0] ?? []);

        return [
            'letters' => $letters,
            'cyrillic' => $cyrillic,
            'latin' => $latin,
            'other' => max(0, $letters - $cyrillic - $latin),
        ];
    }

    private static function isAllowedRussianTechnicalLatin(string $text, bool $hasOtherScriptLetters): bool
    {
        if ($hasOtherScriptLetters) {
            return false;
        }

        $tokens = self::latinTokens($text);
        if ($tokens === []) {
            return false;
        }

        $hasCyrillic = preg_match('/\p{Cyrillic}/u', $text) === 1;
        foreach ($tokens as $token) {
            if (self::isAllowedRussianTechnicalLatinToken($token, $hasCyrillic)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private static function latinTokens(string $text): array
    {
        preg_match_all('/[A-Za-z0-9][A-Za-z0-9_.+-]*/u', $text, $matches);

        return array_values(array_filter($matches[0] ?? [], static fn (string $token): bool => preg_match('/[A-Za-z]/', $token) === 1));
    }

    private static function isAllowedRussianTechnicalLatinToken(string $token, bool $hasCyrillicContext): bool
    {
        $normalized = strtolower(trim($token, " \t\n\r\0\x0B.,:;!?()[]{}\"'«»"));
        if ($normalized === '') {
            return false;
        }

        $compact = str_replace(['_', '+'], '-', $normalized);
        $known = [
            '1c', 'anydesk', 'anytask', 'api', 'bitrix', 'bitrix24', 'bittrex', 'bitrex',
            'bx', 'crm', 'dns', 'docs', 'e-mail', 'email', 'erp', 'fip-bx', 'freepbx',
            'imessenger', 'ip', 'it', 'live', 'marketplace', 'meco', 'mecopbx', 'mico',
            'micopbx', 'mikopbx', 'mp3', 'pbx', 'pdf', 'rdp', 'rootoken', 'rudesktop',
            'rutoken', 'sip', 'sla', 'spot', 'telegram', 'vpn', 'whatsapp', 'xml',
        ];
        if (in_array($compact, $known, true)) {
            return true;
        }

        if (str_contains($normalized, '.') && preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:[a-z]+[0-9]+|[0-9]+[a-z]+)[a-z0-9-]*$/', $normalized) === 1) {
            return true;
        }

        if (!$hasCyrillicContext) {
            return false;
        }

        if (preg_match('/^[A-Z0-9-]{2,8}$/', $token) === 1) {
            return true;
        }

        return preg_match('/^[A-Z][A-Za-z0-9]{2,23}$/', $token) === 1
            && preg_match('/[a-z]/', $token) === 1;
    }

    private static function hasSingleLetterTranscript(string $text): bool
    {
        $tokens = self::transcriptTokens($text);
        return count($tokens) === 1 && mb_strlen($tokens[0]['value'], 'UTF-8') === 1;
    }

    private static function hasRepeatedCharacterRun(string $text): bool
    {
        $characters = self::compactCharacters($text);
        if (count($characters) < self::REPEATED_CHARACTER_MINIMUM_RUN) {
            return false;
        }

        $previous = null;
        $runLength = 0;
        foreach ($characters as $character) {
            if ($character === $previous) {
                $runLength++;
            } else {
                $previous = $character;
                $runLength = 1;
            }
            if ($runLength >= self::REPEATED_CHARACTER_MINIMUM_RUN) {
                return true;
            }
        }

        return false;
    }

    private static function hasRepeatedSubstringRun(string $text): bool
    {
        $characters = self::compactCharacters($text);
        if (count($characters) < self::REPEATED_SUBSTRING_MINIMUM_COUNT * 2) {
            return false;
        }

        $length = count($characters);
        for ($size = 2; $size <= 6; $size++) {
            $index = 0;
            while ($index + $size * self::REPEATED_SUBSTRING_MINIMUM_COUNT <= $length) {
                $pattern = array_slice($characters, $index, $size);
                if (count(array_unique($pattern)) <= 1) {
                    $index++;
                    continue;
                }

                $count = 1;
                $cursor = $index + $size;
                while ($cursor + $size <= $length && array_slice($characters, $cursor, $size) === $pattern) {
                    $count++;
                    $cursor += $size;
                }

                $coveredRatio = ($count * $size) / max(1, $length);
                if ($count >= self::REPEATED_SUBSTRING_MINIMUM_COUNT && $coveredRatio >= 0.60) {
                    return true;
                }

                $index += max(1, $count * $size);
            }
        }

        return false;
    }

    private static function hasRepeatedTokenFlood(string $text): bool
    {
        $tokens = array_column(self::transcriptTokens($text), 'value');
        if (count($tokens) < 4) {
            return false;
        }

        $counts = array_count_values($tokens);
        $maximum = max($counts);
        if ($maximum >= 4 && mb_strlen((string)array_search($maximum, $counts, true), 'UTF-8') >= 4) {
            return $maximum / count($tokens) >= 0.80;
        }
        if (count($tokens) < self::REPEATED_TOKEN_MINIMUM_COUNT) {
            return false;
        }

        return $maximum >= self::REPEATED_TOKEN_MINIMUM_COUNT
            && $maximum / count($tokens) >= self::REPEATED_TOKEN_MINIMUM_RATIO;
    }

    /**
     * @param array<int, string> $tokens
     */
    private static function hasRepeatedNgram(array $tokens, int $size): bool
    {
        if (count($tokens) < $size * self::REPEATED_NGRAM_MINIMUM_COUNT) {
            return false;
        }

        $grams = [];
        $total = 0;
        for ($index = 0; $index <= count($tokens) - $size; $index++) {
            $gram = implode("\u{1F}", array_slice($tokens, $index, $size));
            $grams[$gram] = ($grams[$gram] ?? 0) + 1;
            $total++;
        }

        return max($grams) >= self::REPEATED_NGRAM_MINIMUM_COUNT
            && max($grams) / max(1, $total) >= self::REPEATED_NGRAM_MINIMUM_RATIO;
    }

    /**
     * @return array<int, array{value: string, offset: int, length: int}>
     */
    private static function transcriptTokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE);

        $tokens = [];
        foreach ($matches[0] as [$value, $offset]) {
            $tokens[] = [
                'value' => mb_strtolower($value, 'UTF-8'),
                'offset' => (int)$offset,
                'length' => strlen($value),
            ];
        }

        return $tokens;
    }

    /**
     * @return array<int, string>
     */
    private static function compactCharacters(string $text): array
    {
        $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text, 'UTF-8')) ?? '';
        return preg_split('//u', $compact, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
