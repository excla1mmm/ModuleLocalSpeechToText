<?php

declare(strict_types=1);

/**
 * Static contract test for item 4: saving settings must not reset the
 * realtime CDR scan cursor (there is no reason to with the id-based cursor
 * from item 2 - see bin/WorkerLocalSpeechToTextJobs.php), and must reset the
 * backfill cursor together with its completion flags whenever
 * cdr_history_days changes, so the backfill actually re-scans the new
 * window instead of staying permanently marked done.
 */

function expectSettingsSaveCursor(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$controller = (string)file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');
$saveStart = strpos($controller, 'protected function saveSettingsFromPost(): void');
$saveEnd = strpos($controller, 'protected function countJobsByStatus', $saveStart ?: 0);
expectSettingsSaveCursor($saveStart !== false && $saveEnd !== false, 'Cannot isolate settings save method');
$save = substr($controller, $saveStart, $saveEnd - $saveStart);

$historyChangeGuardPosition = strpos($save, '$previousHistoryDays !== $nextHistoryDays');
expectSettingsSaveCursor($historyChangeGuardPosition !== false, 'Cannot find the history-days-changed guard');
$historyChangeBlockEnd = strpos($save, "\n        }", $historyChangeGuardPosition);
expectSettingsSaveCursor($historyChangeBlockEnd !== false, 'Cannot isolate the history-days-changed block');
$historyChangeBlock = substr($save, $historyChangeGuardPosition, $historyChangeBlockEnd - $historyChangeGuardPosition);

// Only inspect actual $settingsToPersist[...] = ... assignments, not prose
// (this file's own explanatory comments necessarily name the constants).
preg_match_all('/\$settingsToPersist\[STTSetting::([A-Z_]+)\]/', $historyChangeBlock, $assignedKeys);
$assignedKeys = $assignedKeys[1];

expectSettingsSaveCursor(
    !in_array('CDR_SCAN_CURSOR_ENDTIME', $assignedKeys, true)
        && !in_array('CDR_SCAN_CURSOR_ID', $assignedKeys, true),
    'Changing cdr_history_days must not touch the realtime (id-based) scan cursor - '
        . 'doing so drops the realtime backlog between the last scanned row and now'
);
expectSettingsSaveCursor(
    in_array('CDR_BACKFILL_CURSOR_ENDTIME', $assignedKeys, true)
        && in_array('CDR_BACKFILL_CURSOR_ID', $assignedKeys, true),
    'Changing cdr_history_days must reset the backfill cursor so it re-scans from the new window'
);
expectSettingsSaveCursor(
    in_array('CDR_BACKFILL_DONE', $assignedKeys, true)
        && in_array('CDR_BACKFILL_DONE_HISTORY_AT', $assignedKeys, true),
    'Changing cdr_history_days must reset CDR_BACKFILL_DONE / CDR_BACKFILL_DONE_HISTORY_AT, '
        . 'otherwise a completed backfill never re-runs for the new window (e.g. widening to unlimited)'
);

echo "Settings save cursor/backfill-reset contract OK\n";
