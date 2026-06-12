<?php

declare(strict_types=1);

function expectTrueQueueLifecycle(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$job = file_get_contents(__DIR__ . '/../Models/STTJob.php');
$setup = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
$main = file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php');
$worker = file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
$controller = file_get_contents(__DIR__ . '/../App/Controllers/ModuleLocalSpeechToTextController.php');
$queueView = file_get_contents(__DIR__ . '/../Lib/Services/QueueViewService.php');
$view = file_get_contents(__DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt');

expectTrueQueueLifecycle($job !== false, 'Cannot read STTJob.php');
expectTrueQueueLifecycle($setup !== false, 'Cannot read PbxExtensionSetup.php');
expectTrueQueueLifecycle($main !== false, 'Cannot read LocalSpeechToTextMain.php');
expectTrueQueueLifecycle($worker !== false, 'Cannot read WorkerLocalSpeechToTextJobs.php');
expectTrueQueueLifecycle($controller !== false, 'Cannot read ModuleLocalSpeechToTextController.php');
expectTrueQueueLifecycle($queueView !== false, 'Cannot read QueueViewService.php');
expectTrueQueueLifecycle($view !== false, 'Cannot read index.volt');

expectTrueQueueLifecycle(
    str_contains($job, 'public ?string $updated_at')
        && str_contains($setup, 'ensureJobUpdatedAtColumn')
        && str_contains($setup, 'ALTER TABLE m_STTJob ADD COLUMN updated_at'),
    'Queue jobs must persist an updated_at timestamp with an install migration'
);
expectTrueQueueLifecycle(
    str_contains($job, 'markTerminalErrorForWorker')
        && str_contains($main, 'Recording file is not available')
        && str_contains($main, 'Recording file exceeds configured maximum size'),
    'Unavailable or oversized assigned files must become terminal job errors'
);
expectTrueQueueLifecycle(
    str_contains($worker, '$this->retrySkippedRecordings();')
        && str_contains($worker, 'Recovered skipped transcription job')
        && str_contains($worker, 'saveSkippedRecordings'),
    'Skipped recordings must be revisited and removed after recovery'
);
expectTrueQueueLifecycle(
    str_contains($queueView, "latestJobTimestamp(STTJob::STATUS_PENDING, 'updated_at')")
        && str_contains($queueView, "latestJobTimestamp(STTJob::STATUS_PROCESSING, 'updated_at')")
        && str_contains($queueView, 'queueSkippedReasonLabel'),
    'Queue overview must use lifecycle update timestamps and expose skip reasons'
);
expectTrueQueueLifecycle(
    str_contains($queueView, "'statusDetailLabel'")
        && str_contains($view, "row['statusDetailLabel']"),
    'Queue file rows must render status details'
);

echo "Queue lifecycle contract OK\n";
