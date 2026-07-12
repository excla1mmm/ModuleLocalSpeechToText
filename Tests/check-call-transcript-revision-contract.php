<?php

declare(strict_types=1);

require_once __DIR__ . '/../Lib/Services/CallTranscriptRevisionPolicy.php';

use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionPolicy;

function expectCallRevision(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$policy = new CallTranscriptRevisionPolicy(30);
$empty = [
    'current_revision' => 0,
    'current_content_hash' => '',
    'pending_content_hash' => '',
    'stable_after' => 0,
];
$first = $policy->stage($empty, 'hash-a', 'parts-a', '{"transcript":"a"}', 'complete', 1000);
expectCallRevision($first['state'] === 'staged', 'first aggregate must stage');
expectCallRevision($first['stable_after'] === 1030, 'default stability deadline must be 30 seconds');

$replay = $policy->stage($first['call'], 'hash-a', 'parts-a', '{"transcript":"a"}', 'complete', 1001);
expectCallRevision($replay['state'] === 'unchanged', 'replay must not restage');
expectCallRevision($replay['call']['stable_after'] === 1030, 'replay must not extend stability');
expectCallRevision($policy->publication($replay['call'], 1029, true) === null, 'candidate must not publish early');
expectCallRevision($policy->publication($replay['call'], 1030, false) === null, 'non-terminal jobs must block publication');

$published = $policy->publication($replay['call'], 1030, true);
expectCallRevision(is_array($published), 'stable terminal candidate must publish');
expectCallRevision($published['revision'] === 1, 'first publication must be revision 1');
expectCallRevision($published['event_type'] === 'call-transcript.completed', 'first event must be completed');

$current = $policy->applyPublication($replay['call'], $published, 1030);
$sameContentNewComposition = $policy->stage($current, 'hash-a', 'parts-b', '{"transcript":"a"}', 'complete', 1040);
expectCallRevision($sameContentNewComposition['state'] === 'unchanged', 'same content hash must not create a revision');

$changed = $policy->stage($current, 'hash-b', 'parts-c', '{"transcript":"b"}', 'complete', 1050);
$updated = $policy->publication($changed['call'], 1080, true);
expectCallRevision(is_array($updated) && $updated['revision'] === 2, 'one changed aggregate must create exactly revision 2');
expectCallRevision($updated['event_type'] === 'call-transcript.updated', 'later event must be updated');

$requiredModels = [
    'STTCallTranscript.php',
    'STTCallTranscriptPart.php',
    'STTCallTranscriptRevision.php',
    'STTCallTranscriptEvent.php',
];
foreach ($requiredModels as $model) {
    expectCallRevision(is_file(__DIR__ . '/../Models/' . $model), "missing model {$model}");
}

$setup = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php') ?: '';
$schemaService = file_get_contents(__DIR__ . '/../Lib/Services/CallTranscriptSchemaService.php') ?: '';
foreach (['m_STTCallTranscript', 'm_STTCallTranscriptPart', 'm_STTCallTranscriptRevision', 'm_STTCallTranscriptEvent'] as $table) {
    expectCallRevision(str_contains($schemaService, $table), "schema service must create {$table}");
}
expectCallRevision(str_contains($setup, 'CallTranscriptSchemaService'), 'setup must invoke call transcript schema service');
expectCallRevision(str_contains($setup, "STTSetting::CALL_TRANSCRIPT_STABILITY_SEC, '30'"), 'setup must default stability to 30 seconds');

$job = file_get_contents(__DIR__ . '/../Models/STTJob.php') ?: '';
foreach (['cdr_id', 'cdr_uniqueid', 'cdr_start_ms', 'cdr_end_ms', 'recording_fingerprint'] as $field) {
    expectCallRevision(str_contains($job, '$' . $field), "STT job must persist {$field}");
}

$main = file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php') ?: '';
expectCallRevision(str_contains($main, 'recordCompletedPart'), 'result submission must update call aggregate');

$worker = file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php') ?: '';
expectCallRevision(str_contains($worker, 'publishReady'), 'scanner must publish stable call revisions');

echo "Call transcript revision contract OK\n";
