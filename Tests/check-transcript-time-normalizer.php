<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\TranscriptTimeNormalizer;

require_once __DIR__ . '/../Lib/TranscriptTimeNormalizer.php';

function expectSameTranscriptTimeNormalizer(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function expectFloatTranscriptTimeNormalizer(float $expected, mixed $actual, string $message): void
{
    if (!is_float($actual) || abs($expected - $actual) > 0.000001) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function expectInvalidArgumentTranscriptTimeNormalizer(string $expectedMessage, callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), $expectedMessage)) {
            throw new RuntimeException(
                $message . "\nExpected exception containing: {$expectedMessage}\nActual: " . $exception->getMessage()
            );
        }

        return;
    }

    throw new RuntimeException($message . "\nExpected InvalidArgumentException was not thrown");
}

expectSameTranscriptTimeNormalizer(
    null,
    TranscriptTimeNormalizer::durationSecondsFromPayload([]),
    'Missing duration_ms must be accepted as an absent duration'
);
expectSameTranscriptTimeNormalizer(
    null,
    TranscriptTimeNormalizer::durationSecondsFromPayload(['duration_ms' => null]),
    'Null duration_ms must be accepted as an absent duration'
);
expectFloatTranscriptTimeNormalizer(
    1.234,
    TranscriptTimeNormalizer::durationSecondsFromPayload(['duration_ms' => 1234]),
    'Integer duration_ms must be converted to seconds'
);
expectFloatTranscriptTimeNormalizer(
    2.5,
    TranscriptTimeNormalizer::durationSecondsFromPayload(['duration_ms' => '2500']),
    'Numeric string duration_ms must be converted to seconds'
);
expectFloatTranscriptTimeNormalizer(
    3.0,
    TranscriptTimeNormalizer::durationSecondsFromPayload(['duration_ms' => 3000.0]),
    'Integral float duration_ms must be converted to seconds'
);
expectInvalidArgumentTranscriptTimeNormalizer(
    'duration is not supported',
    static fn (): ?float => TranscriptTimeNormalizer::durationSecondsFromPayload(['duration' => 2.5]),
    'Legacy duration seconds field must be rejected'
);
expectInvalidArgumentTranscriptTimeNormalizer(
    'duration_ms must be an integer number of milliseconds',
    static fn (): ?float => TranscriptTimeNormalizer::durationSecondsFromPayload(['duration_ms' => 12.5]),
    'Fractional duration_ms must be rejected'
);
expectInvalidArgumentTranscriptTimeNormalizer(
    'duration_ms must be greater than or equal to 0',
    static fn (): ?float => TranscriptTimeNormalizer::durationSecondsFromPayload(['duration_ms' => -1]),
    'Negative duration_ms must be rejected'
);

$segments = [
    [
        'start_ms' => '1000',
        'end_ms' => 2500.0,
        'text' => 'Hello',
        'speaker' => 'agent',
    ],
    [
        'text' => 'Untimed segment',
    ],
];
expectSameTranscriptTimeNormalizer(
    [
        [
            'start_ms' => 1000,
            'end_ms' => 2500,
            'text' => 'Hello',
            'speaker' => 'agent',
        ],
        [
            'text' => 'Untimed segment',
        ],
    ],
    TranscriptTimeNormalizer::normalizeTranscriptSegments($segments),
    'Transcript segment timestamps must be normalized while preserving payload fields'
);

expectSameTranscriptTimeNormalizer(
    [
        ['text' => 'early', 'start_ms' => 100, 'end_ms' => 200],
        ['text' => 'same-first', 'start_ms' => 300, 'end_ms' => 400],
        ['text' => 'same-second', 'start_ms' => 300, 'end_ms' => 400],
        ['text' => 'late', 'start_ms' => 500, 'end_ms' => 600],
        ['text' => 'untimed-first'],
        ['text' => 'untimed-second'],
    ],
    TranscriptTimeNormalizer::normalizeTranscriptSegments([
        ['text' => 'late', 'start_ms' => '500', 'end_ms' => 600.0],
        ['text' => 'untimed-first'],
        ['text' => 'same-first', 'start_ms' => 300, 'end_ms' => 400],
        ['text' => 'early', 'start_ms' => 100, 'end_ms' => 200],
        ['text' => 'same-second', 'start_ms' => 300, 'end_ms' => 400],
        ['text' => 'untimed-second'],
    ]),
    'Timed transcript segments must have stable canonical timestamp order and untimed segments must remain stable after them'
);

expectInvalidArgumentTranscriptTimeNormalizer(
    'transcript[0] must be an object',
    static fn (): array => TranscriptTimeNormalizer::normalizeTranscriptSegments(['not an object']),
    'Non-object transcript segments must be rejected'
);
expectInvalidArgumentTranscriptTimeNormalizer(
    'must contain both start_ms and end_ms',
    static fn (): array => TranscriptTimeNormalizer::normalizeTranscriptSegments([['start_ms' => 1000, 'text' => 'Hello']]),
    'Segments with only one timestamp boundary must be rejected'
);
expectInvalidArgumentTranscriptTimeNormalizer(
    'start/end are not supported',
    static fn (): array => TranscriptTimeNormalizer::normalizeTranscriptSegments([['start' => 1.0, 'end' => 2.0, 'text' => 'Hello']]),
    'Legacy start/end seconds fields must be rejected'
);
expectInvalidArgumentTranscriptTimeNormalizer(
    'end_ms must be greater than or equal to start_ms',
    static fn (): array => TranscriptTimeNormalizer::normalizeTranscriptSegments([['start_ms' => 3000, 'end_ms' => 2000]]),
    'Segments with reversed timestamp boundaries must be rejected'
);

expectSameTranscriptTimeNormalizer(
    [1.2, 3.4],
    TranscriptTimeNormalizer::segmentRangeSeconds(['start_ms' => 1200, 'end_ms' => 3400]),
    'Segment ranges must expose seconds for transcript exports'
);
expectSameTranscriptTimeNormalizer(
    [0.0, 0.0],
    TranscriptTimeNormalizer::segmentRangeSeconds(['start_ms' => 'bad', 'end_ms' => 3400]),
    'Invalid stored segment ranges must fall back to zero seconds for read-side exports'
);

expectFloatTranscriptTimeNormalizer(
    0.9,
    TranscriptTimeNormalizer::secondsMetricFromMilliseconds('900'),
    'Technical metrics in milliseconds must be converted to seconds'
);
expectSameTranscriptTimeNormalizer(
    null,
    TranscriptTimeNormalizer::secondsMetricFromMilliseconds(''),
    'Blank technical millisecond metrics must stay empty'
);
expectSameTranscriptTimeNormalizer(
    null,
    TranscriptTimeNormalizer::secondsMetricFromMilliseconds('bad'),
    'Malformed technical millisecond metrics must stay empty'
);
expectSameTranscriptTimeNormalizer(
    1.235,
    TranscriptTimeNormalizer::roundSeconds(1.23456),
    'Display seconds must be rounded to milliseconds'
);
expectSameTranscriptTimeNormalizer(
    0.0,
    TranscriptTimeNormalizer::roundSeconds(-0.1),
    'Display seconds must never go below zero'
);

echo "Transcript time normalizer contract OK\n";
