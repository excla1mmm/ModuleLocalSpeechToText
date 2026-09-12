<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleBatchMigrationRunner;

require_once __DIR__ . '/../Lib/Services/ModuleBatchMigrationRunner.php';

function expectBatchedMigration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$runner = new ModuleBatchMigrationRunner();

$remainingEvents = 1001;
$eventCalls = 0;
$eventTotal = $runner->run(static function () use (&$remainingEvents, &$eventCalls): int {
    $eventCalls++;
    $processed = min(1000, $remainingEvents);
    $remainingEvents -= $processed;

    return $processed;
});
expectBatchedMigration($eventTotal === 1001, 'event backfill must process more than one 1,000-row batch');
expectBatchedMigration($eventCalls === 3, 'event backfill must confirm exhaustion with a final empty batch');

$remainingParts = 10001;
$partCalls = 0;
$partTotal = $runner->run(static function () use (&$remainingParts, &$partCalls): int {
    $partCalls++;
    $processed = min(10000, $remainingParts);
    $remainingParts -= $processed;

    return $processed;
});
expectBatchedMigration($partTotal === 10001, 'call transcript migration must process beyond 10,000 rows');
expectBatchedMigration($partCalls === 3, 'call transcript migration must confirm exhaustion');

$setup = (string)file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
$callMigration = (string)file_get_contents(__DIR__ . '/../Lib/Services/CallTranscriptMigration.php');
$revisionService = (string)file_get_contents(__DIR__ . '/../Lib/Services/CallTranscriptRevisionService.php');
$eventModel = (string)file_get_contents(__DIR__ . '/../Models/STTTranscriptEvent.php');
$searchIndex = (string)file_get_contents(__DIR__ . '/../Lib/Services/TranscriptSearchIndexService.php');
expectBatchedMigration(
    str_contains($setup, "new ModuleSchemaMigration(\n                8,")
        && str_contains($setup, "new ModuleSchemaMigration(\n                9,")
        && substr_count($setup, 'ModuleBatchMigrationRunner') >= 3,
    'corrective migration versions 8 and 9 must exhaust both legacy backfills'
);
expectBatchedMigration(
    preg_match('/backfillCompletedEvents\(\s*1000,\s*preserveCursorVisibility:\s*true\s*\)/s', $setup) === 1
        && str_contains($eventModel, 'MAX(CAST(created_at AS INTEGER))')
        && str_contains($eventModel, 'max(time(), $cursorFrontier)')
        && str_contains($eventModel, 'cursorSafeCreatedAt'),
    'corrective event backfill must append at the existing cursor frontier'
);
expectBatchedMigration(
    str_contains($callMigration, 'LEFT JOIN m_STTCallTranscriptPart')
        && str_contains($callMigration, 'p.id IS NULL')
        && str_contains($callMigration, "trim((string)\$job->call_id) === ''")
        && str_contains($callMigration, "\$job->call_id = (string)(\$row['call_id'] ?? '')")
        && str_contains($callMigration, 'Call transcript migration could not materialize result'),
    'call transcript batches must advance past already migrated results'
);
expectBatchedMigration(
    str_contains($setup, '$migration->publishAllReady()')
        && str_contains($callMigration, 'publishAllReady')
        && str_contains($revisionService, '?int $publishedAt = null')
        && str_contains($revisionService, 'MAX(CAST(created_at AS INTEGER))')
        && str_contains($revisionService, '$event->created_at = (string)$publishedAt'),
    'call transcript migration must exhaust eligible publications with a cursor-safe timestamp'
);
expectBatchedMigration(
    str_contains($setup, "new ModuleSchemaMigration(\n                16,")
        && str_contains($setup, 'applyTranscriptSearchMigration')
        && str_contains($setup, 'prepareTranscriptSearchIndex')
        && str_contains($setup, 'backfillBatch($connection'),
    'transcript search schema must use a new migration and resumable bounded backfill'
);
expectBatchedMigration(
    str_contains($searchIndex, 'm_STTTranscriptSearchState')
        && str_contains($searchIndex, 'last_result_id')
        && str_contains($searchIndex, 'completed_at'),
    'transcript search backfill must persist resumable progress and readiness'
);

echo "Batched migration completion behavior OK\n";
