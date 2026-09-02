<?php

declare(strict_types=1);

function expectSimplifiedSTTSurface(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "STT API simplification failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string)file_get_contents($root . '/' . $path);

expectSimplifiedSTTSurface(!is_file($root . '/Lib/RestAPI/TranscriptEvents/Controller.php'), 'legacy transcript events resource remains');

$main = $read('Lib/LocalSpeechToTextMain.php');
expectSimplifiedSTTSurface(
    str_contains($main, "return self::success(['id' => (int)\$worker->id]);"),
    'worker registration must return only its numeric id'
);
expectSimplifiedSTTSurface(!str_contains($main, "'duration_hint' =>"), 'unused duration_hint remains in job lease');
expectSimplifiedSTTSurface(!str_contains($main, "'model_source_url' =>"), 'unused model_source_url remains in job lease');

$jobs = $read('Lib/Services/STTJobManagementService.php');
expectSimplifiedSTTSurface(!str_contains($jobs, "'file_path'=>(string)\$job->file_path"), 'administrative job response exposes local file_path');

$jobsSchema = $read('Lib/RestAPI/Jobs/DataStructure.php');
$leaseSchema = $read('Lib/RestAPI/JobLeases/DataStructure.php');
expectSimplifiedSTTSurface(substr_count($jobsSchema, "'file_path'=>") === 1, 'job response schema still exposes file_path');
expectSimplifiedSTTSurface(!str_contains($leaseSchema, "'model_source_url'=>"), 'job lease schema still exposes model_source_url');

$workersController = $read('Lib/RestAPI/Workers/Controller.php');
expectSimplifiedSTTSurface(
    str_contains($workersController, "schemaClass: RegistrationDataStructure::class"),
    'worker registration still documents the full worker record'
);

$events = $read('Lib/Services/CallTranscriptIntegrationService.php');
$events = strstr($events, 'public function callTranscriptDetail', true) ?: $events;
foreach (["'call_id' =>", "'content_hash' =>", "'parts_hash' =>", "'completeness_status' =>", "'created_at' => \$rowCreatedAt", "'limit' => \$limit"] as $redundantEventField) {
    expectSimplifiedSTTSurface(!str_contains($events, $redundantEventField), "call transcript event duplicate remains: {$redundantEventField}");
}

$callEventsSchema = $read('Lib/RestAPI/CallTranscriptEvents/DataStructure.php');
$callEventsSchema = strstr($callEventsSchema, 'public static function getListItemSchema') ?: $callEventsSchema;
foreach (["'call_id' =>", "'content_hash' =>", "'parts_hash' =>", "'completeness_status' =>", "'created_at' =>", "'limit' =>"] as $redundantEventSchemaField) {
    expectSimplifiedSTTSurface(!str_contains($callEventsSchema, $redundantEventSchemaField), "call transcript event schema duplicate remains: {$redundantEventSchemaField}");
}

$callTranscriptSchema = $read('Lib/RestAPI/CallTranscripts/DataStructure.php');
$callTranscriptSchema = strstr($callTranscriptSchema, 'private static function transcriptSchema') ?: $callTranscriptSchema;
$callTranscriptSchema = strstr($callTranscriptSchema, 'private static function detailExample', true) ?: $callTranscriptSchema;
foreach (["'result_id' =>", "'source_index' =>", "'absolute_start_ms' =>", "'normalized_text' =>", "'source_segment_ids' =>", "'segment_count' =>", "'turn_count' =>"] as $internalTranscriptField) {
    expectSimplifiedSTTSurface(!str_contains($callTranscriptSchema, $internalTranscriptField), "public transcript schema still exposes internal field: {$internalTranscriptField}");
}

require_once $root . '/Lib/Services/TranscriptPublicProjection.php';
$projection = (new \Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptPublicProjection())->project([
    'segments' => [[
        'segment_id' => 'stt:3048:2', 'result_id' => 3048, 'source_index' => 2, 'part_order' => 0,
        'index' => 2, 'start_ms' => 5440, 'end_ms' => 10080,
        'absolute_start_ms' => 1700000005440, 'absolute_end_ms' => 1700000010080,
        'text' => 'Это Людмила.', 'normalized_text' => 'это людмила.', 'source_label' => 'left',
        'recording_fingerprint' => str_repeat('a', 64), 'resolution_status' => 'complete',
        'channel' => 'left', 'speaker' => '',
    ]],
    'turns' => [[
        'turn_id' => 'turn:2', 'index' => 0, 'start_ms' => 5440, 'end_ms' => 10080,
        'absolute_start_ms' => 1700000005440, 'absolute_end_ms' => 1700000010080,
        'text' => 'Это Людмила.', 'channel' => 'left', 'speaker' => 'operator',
        'source_segment_ids' => ['stt:3048:2'], 'source_result_ids' => [3048],
    ]],
    'segment_count' => 1, 'turn_count' => 1, 'text' => '[operator] Это Людмила.',
]);
expectSimplifiedSTTSurface(($projection['segments'][0] ?? null) === [
    'segment_id' => 'stt:3048:2', 'index' => 2, 'start_ms' => 5440, 'end_ms' => 10080,
    'text' => 'Это Людмила.', 'speaker' => 'left',
], 'public segment is not minimal');
expectSimplifiedSTTSurface(($projection['turns'][0] ?? null) === [
    'turn_id' => 'turn:2', 'index' => 0, 'start_ms' => 5440, 'end_ms' => 10080,
    'text' => 'Это Людмила.', 'speaker' => 'operator',
], 'public turn is not minimal');
expectSimplifiedSTTSurface(!isset($projection['segment_count'], $projection['turn_count']), 'derived transcript counts remain public');

echo "STT API surface simplification contract OK\n";
