<?php

declare(strict_types=1);

function expectTimelineProjection(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Call transcript timeline projection failed: {$message}\n");
        exit(1);
    }
}

function expectTimelineSame(mixed $expected, mixed $actual, string $message): void
{
    expectTimelineProjection(
        $expected === $actual,
        "{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

$serviceFile = __DIR__ . '/../Lib/Services/CallTranscriptTimelineProjector.php';
expectTimelineProjection(is_file($serviceFile), 'timeline projector service is missing');
require_once $serviceFile;

$projector = new \Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptTimelineProjector();

$segment = static fn (int $resultId, int $index, int $startMs, int $endMs, string $text): array => [
    'segment_id' => sprintf('call:fixed:r%d:s%d', $resultId, $index),
    'result_id' => $resultId,
    'source_index' => $index,
    'part_order' => $resultId === 10 ? 0 : 1,
    'start_ms' => $startMs,
    'end_ms' => $endMs,
    'text' => $text,
    'channel' => 'left',
    'speaker' => '',
    'source_label' => 'part:' . $resultId,
];

$twoPartManifest = [
    ['result_id' => 10, 'job_id' => 16, 'cdr_id' => 501, 'cdr_start_ms' => 1_000_000, 'cdr_end_ms' => 1_040_000],
    ['result_id' => 11, 'job_id' => 15, 'cdr_id' => 502, 'cdr_start_ms' => 1_030_000, 'cdr_end_ms' => 1_070_000],
];

$projected = $projector->project([
    'segments' => [
        $segment(10, 0, 0, 5_000, 'Клиентская реплика'),
        $segment(11, 0, 0, 5_000, 'Реплика второй части'),
    ],
    'turns' => [],
], $twoPartManifest);

expectTimelineSame(0, $projected['segments'][0]['start_ms'], 'first part keeps call-relative start');
expectTimelineSame(5_000, $projected['segments'][0]['end_ms'], 'first part keeps call-relative end');
expectTimelineSame(0, $projected['segments'][0]['recording_start_ms'], 'first part must publish recording-local start');
expectTimelineSame(5_000, $projected['segments'][0]['recording_end_ms'], 'first part must publish recording-local end');
expectTimelineSame(10, $projected['segments'][0]['result_id'], 'segment must keep its part result id');
expectTimelineSame(30_000, $projected['segments'][1]['start_ms'], 'second part start must shift by cdr_start difference');
expectTimelineSame(35_000, $projected['segments'][1]['end_ms'], 'second part end must shift by cdr_start difference');
expectTimelineSame(0, $projected['segments'][1]['recording_start_ms'], 'second part must keep its recording-local start');
expectTimelineSame(11, $projected['segments'][1]['result_id'], 'second segment result id mismatch');
expectTimelineSame(true, $projected['timeline_reliable'], 'resolved two-part timeline must be reliable');

$projectedTurns = $projector->project([
    'segments' => [],
    'turns' => [
        [
            'turn_id' => 'turn-a', 'index' => 0, 'start_ms' => 0, 'end_ms' => 5_000,
            'text' => 'Первая часть', 'channel' => 'left', 'source_result_ids' => [10],
        ],
        [
            'turn_id' => 'turn-b', 'index' => 1, 'start_ms' => 0, 'end_ms' => 5_000,
            'text' => 'Вторая часть', 'channel' => 'left', 'source_result_ids' => [11],
        ],
    ],
], $twoPartManifest);

expectTimelineSame(0, $projectedTurns['turns'][0]['start_ms'], 'single-source turn keeps call-relative start');
expectTimelineSame(0, $projectedTurns['turns'][0]['recording_start_ms'], 'single-source turn must publish recording-local start');
expectTimelineSame(30_000, $projectedTurns['turns'][1]['start_ms'], 'turn of second part must use call-relative start');
expectTimelineSame(0, $projectedTurns['turns'][1]['recording_start_ms'], 'turn of second part must keep recording-local start');
expectTimelineSame([11], $projectedTurns['turns'][1]['source_result_ids'], 'turn must keep source result ids');
expectTimelineSame(true, $projectedTurns['timeline_reliable'], 'resolved turns must keep the timeline reliable');

$ambiguous = $projector->project([
    'segments' => [],
    'turns' => [
        [
            'turn_id' => 'turn-mixed', 'index' => 0, 'start_ms' => 1_000, 'end_ms' => 2_000,
            'text' => 'Смешанный turn', 'channel' => 'left', 'source_result_ids' => [10, 11],
        ],
    ],
], $twoPartManifest);

expectTimelineSame(1_000, $ambiguous['turns'][0]['start_ms'], 'ambiguous turn must stay on the local timeline');
expectTimelineSame(1_000, $ambiguous['turns'][0]['recording_start_ms'], 'ambiguous turn must still publish local codes');
expectTimelineSame(false, $ambiguous['timeline_reliable'], 'ambiguous turn must mark the timeline unreliable');

$absolute = $projector->project([
    'segments' => [
        [
            'segment_id' => 'seg-abs', 'result_id' => 10, 'index' => 0,
            'start_ms' => 2_000, 'end_ms' => 4_000,
            'absolute_start_ms' => 1_002_000, 'absolute_end_ms' => 1_004_000,
            'text' => 'Абсолют', 'channel' => 'left',
        ],
    ],
    'turns' => [],
], [['result_id' => 10, 'cdr_id' => 501, 'cdr_start_ms' => 1_000_000]]);

expectTimelineSame(2_000, $absolute['segments'][0]['start_ms'], 'absolute times must project onto the call timeline');
expectTimelineSame(2_000, $absolute['segments'][0]['recording_start_ms'], 'absolute projection must keep recording-local codes');
expectTimelineSame(true, $absolute['timeline_reliable'], 'absolute projection must stay reliable');

$unresolvedCases = [
    'empty manifest' => [[], [ $segment(10, 0, 3_000, 4_000, 'Без манифеста') ]],
    'unknown result id' => [[['result_id' => 10, 'cdr_id' => 501, 'cdr_start_ms' => 1_000_000]], [ $segment(99, 0, 3_000, 4_000, 'Неизвестная часть') ]],
    'part without cdr start' => [[['result_id' => 10, 'cdr_id' => 501, 'cdr_start_ms' => null]], [ $segment(10, 0, 3_000, 4_000, 'Старая часть') ]],
];
foreach ($unresolvedCases as $case => [$caseManifest, $caseSegments]) {
    $unresolved = $projector->project(['segments' => $caseSegments, 'turns' => []], $caseManifest);
    expectTimelineSame(3_000, $unresolved['segments'][0]['start_ms'], "{$case}: local start must be preserved");
    expectTimelineSame(3_000, $unresolved['segments'][0]['recording_start_ms'], "{$case}: local codes must still be published");
    expectTimelineSame(false, $unresolved['timeline_reliable'], "{$case}: timeline must be marked unreliable");
}

$partial = $projector->project([
    'segments' => [
        $segment(10, 0, 1_000, 2_000, 'Разрешённая часть'),
        $segment(99, 0, 3_000, 4_000, 'Неразрешённая часть'),
    ],
    'turns' => [],
], $twoPartManifest);
expectTimelineSame(1_000, $partial['segments'][0]['start_ms'], 'resolved segment must still use the call timeline');
expectTimelineSame(3_000, $partial['segments'][1]['start_ms'], 'unresolved segment must keep local time');
expectTimelineSame(false, $partial['timeline_reliable'], 'mixed resolution must mark the timeline unreliable');

$empty = $projector->project(['segments' => [], 'turns' => []], $twoPartManifest);
expectTimelineSame(true, $empty['timeline_reliable'], 'empty transcript with resolved manifest must stay reliable');

echo "Call transcript timeline projector OK\n";
