<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Common\Models\CallDetailRecords;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;

/**
 * CDR discovery: the realtime id-watermark scan, the legacy-cursor
 * migration, the one-shot backfill scan, and per-record job creation.
 *
 * Extracted verbatim from bin/WorkerLocalSpeechToTextJobs.php (moved, not
 * rewritten). $needRestart / $iterationBudgetExhausted are supplied as
 * closures by the worker so this class observes the exact same live,
 * per-iteration flags the worker itself checks, without owning them.
 */
final class CdrDiscoveryScanner
{
    // Internal CDR discovery budget: not a user setting. Realtime and backfill share
    // at most this many core CDR rows per pass; pending backfill always gets a slice.
    public const int CDR_DISCOVERY_BATCH_SIZE = 30;
    public const int BACKFILL_RESERVED_BATCH_SIZE = 5;

    public function __construct(
        private readonly CdrRecordEvaluator $evaluator,
        private readonly RecordingRetryCache $retryCache
    ) {
    }

    /**
     * @return array{active: bool, idle: bool}
     */
    public function createJobsFromCompletedCdr(Closure $needRestart, Closure $iterationBudgetExhausted): array
    {
        $delayedRecordings = $this->retryCache->delayedRecordings();
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        $this->initializeRealtimeScanCursor();
        $backfillPending = !$this->backfillCompletedFor($historyStart);
        $realtimeLimit = $backfillPending
            ? self::CDR_DISCOVERY_BATCH_SIZE - self::BACKFILL_RESERVED_BATCH_SIZE
            : self::CDR_DISCOVERY_BATCH_SIZE;
        $realtimeRowCount = $this->createRealtimeJobsFromCompletedCdr(
            $delayedRecordings,
            $historyStart,
            $realtimeLimit,
            $needRestart,
            $iterationBudgetExhausted
        );
        if ($needRestart() || $iterationBudgetExhausted()) {
            $this->retryCache->saveDelayedRecordings($delayedRecordings);
            return ['active' => false, 'idle' => false];
        }

        $remainingBudget = self::CDR_DISCOVERY_BATCH_SIZE - $realtimeRowCount;
        if (!$backfillPending || $remainingBudget <= 0) {
            $this->retryCache->saveDelayedRecordings($delayedRecordings);
            return $realtimeRowCount > 0
                ? ['active' => true, 'idle' => false]
                : ['active' => false, 'idle' => true];
        }

        $backfill = $this->createBackfillJobsFromCompletedCdr(
            $delayedRecordings,
            $historyStart,
            $remainingBudget,
            $needRestart,
            $iterationBudgetExhausted
        );
        $this->retryCache->saveDelayedRecordings($delayedRecordings);

        if ($backfill['interrupted']) {
            return ['active' => false, 'idle' => false];
        }

        return $realtimeRowCount > 0 || $backfill['row_count'] > 0
            ? ['active' => true, 'idle' => false]
            : ['active' => false, 'idle' => true];
    }

    /**
     * The realtime cursor is a plain `id` watermark, not `endtime`. Core
     * assigns `cdr_general.id` as a fresh autoincrement when a linkedid's
     * rows are moved out of the temp table - in completion order, not in
     * endtime order (see CallDetailRecordsTmp::afterSave() /
     * WorkerCdr::processCompletedRows() in Core). For a transferred or
     * multi-leg call, an early leg can be inserted (and get its id) long
     * after a later, short call that already advanced an endtime-based
     * cursor past that leg's endtime - permanently skipping it. `id` has no
     * such ordering inversion: it is assigned once, monotonically, at
     * insertion time, so `id > cursor` never misses a row and never needs
     * revisiting one already seen.
     *
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @return int Number of CDR rows the realtime query returned.
     */
    public function createRealtimeJobsFromCompletedCdr(
        array &$delayedRecordings,
        string $historyStart,
        int $scanLimit,
        Closure $needRestart,
        Closure $iterationBudgetExhausted
    ): int {
        $cursorId = $this->scanCursor();
        $conditions = 'recordingfile <> :empty: AND endtime <> :empty: AND work_completed = :completed:'
            . ' AND id > :cursor_id:';
        $bind = [
            'empty' => '',
            'completed' => '1',
            'cursor_id' => $cursorId,
        ];
        if ($historyStart !== '') {
            $conditions .= ' AND endtime >= :history_start:';
            $bind['history_start'] = $historyStart;
        }

        $records = CallDetailRecords::find([
            'conditions' => $conditions,
            'bind' => $bind,
            'order' => 'id ASC',
            'limit' => $scanLimit,
        ]);

        $rows = $records->toArray();
        $processedRowCount = 0;

        $lastId = $cursorId;

        foreach ($rows as $record) {
            if ($needRestart() || $iterationBudgetExhausted()) {
                $this->saveScanCursor($lastId);
                return $processedRowCount;
            }

            $this->processCompletedRecord($delayedRecordings, $record, $historyStart);
            $processedRowCount++;

            $recordId = $this->evaluator->recordId($record);
            if ($recordId > $lastId) {
                $lastId = $recordId;
            }
        }

        $this->saveScanCursor($lastId);

        return $processedRowCount;
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @return array{row_count: int, interrupted: bool}
     */
    public function createBackfillJobsFromCompletedCdr(
        array &$delayedRecordings,
        string $historyStart,
        int $scanLimit,
        Closure $needRestart,
        Closure $iterationBudgetExhausted
    ): array {
        // Backfill is a one-shot scan inside the current retention window.
        // Once the tail is drained, we keep the flag set and stop hammering the CDR
        // table every discovery pass until the retention window is widened.
        if ($this->backfillCompletedFor($historyStart)) {
            return ['row_count' => 0, 'interrupted' => false];
        }

        [$cursorEndtime, $cursorId] = $this->backfillCursor();
        if ($cursorEndtime === '') {
            $cursorEndtime = date('Y-m-d H:i:s');
            $cursorId = PHP_INT_MAX;
        }

        // The retention window slides forward each day; if the cursor has already
        // slid past it, every subsequent scan is guaranteed to be empty. Mark done.
        if ($historyStart !== '' && $cursorEndtime < $historyStart) {
            $this->markBackfillCompleted($historyStart);
            return ['row_count' => 0, 'interrupted' => false];
        }

        $conditions = 'recordingfile <> :empty: AND endtime <> :empty: AND work_completed = :completed:'
            . ' AND (endtime < :cursor_endtime: OR (endtime = :cursor_endtime: AND id < :cursor_id:))';
        $bind = [
            'empty' => '',
            'completed' => '1',
            'cursor_endtime' => $cursorEndtime,
            'cursor_id' => $cursorId,
        ];
        if ($historyStart !== '') {
            $conditions .= ' AND endtime >= :history_start:';
            $bind['history_start'] = $historyStart;
        }

        $records = CallDetailRecords::find([
            'conditions' => $conditions,
            'bind' => $bind,
            'order' => 'endtime DESC, id DESC',
            'limit' => $scanLimit,
        ]);

        $rows = $records->toArray();
        $rowCount = count($rows);

        $lastEndtime = '';
        $lastId = 0;

        foreach ($rows as $record) {
            if ($needRestart() || $iterationBudgetExhausted()) {
                if ($lastEndtime !== '') {
                    $this->saveBackfillCursor($lastEndtime, $lastId);
                }
                return ['row_count' => 0, 'interrupted' => true];
            }

            $this->processCompletedRecord($delayedRecordings, $record, $historyStart);
            $lastEndtime = $this->evaluator->endtime($record);
            $lastId = $this->evaluator->recordId($record);
        }

        if ($lastEndtime !== '') {
            $this->saveBackfillCursor($lastEndtime, $lastId);
        }

        // A short page is the only reliable signal that the tail has been drained:
        // empty result is the special case where $rowCount === 0 < $scanLimit.
        if ($rowCount < $scanLimit) {
            $this->markBackfillCompleted($historyStart);
        }

        return ['row_count' => $rowCount, 'interrupted' => false];
    }

    public function backfillCompletedFor(string $historyStart): bool
    {
        if (STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE, '0') !== '1') {
            return false;
        }

        // Auto-reset the flag if the operator widens the retention window:
        // either history_start moved earlier than the snapshot we stored
        // when we finished, or the window was widened all the way to
        // "unlimited" (historyStart === '' while we previously finished
        // against a bounded window). saveSettingsFromPost() already resets
        // these settings directly when cdr_history_days changes; this check
        // is a second line of defense so backfill self-heals even if this
        // method is reached with stale flags some other way.
        $doneFor = trim(STTSetting::getValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, ''));
        $historyWindowWidened = $doneFor !== ''
            && ($historyStart === '' || $historyStart < $doneFor);
        if ($historyWindowWidened) {
            STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE, '0');
            STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, '');
            return false;
        }

        return true;
    }

    public function markBackfillCompleted(string $historyStart): void
    {
        STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE_HISTORY_AT, $historyStart);
        STTSetting::setValue(STTSetting::CDR_BACKFILL_DONE, '1');
    }

    /**
     * @param array<int, array<string, mixed>> $delayedRecordings
     * @param array<string, mixed> $record
     */
    public function processCompletedRecord(array &$delayedRecordings, array $record, string $historyStart): void
    {
        if (!$this->evaluator->recordWithinHistoryWindow($record, $historyStart)) {
            $this->retryCache->rememberSkippedRecording($record, 'outside_history_window');
            return;
        }

        $recordingPath = $this->evaluator->recordingPath((string)($record['recordingfile'] ?? ''));
        if ($recordingPath === null) {
            $this->retryCache->rememberDelayedRecording($delayedRecordings, $record);
            return;
        }

        $this->retryCache->forgetDelayedRecording($delayedRecordings, $this->evaluator->recordId($record));
        if (!$this->evaluator->recordingWithinSizeLimit($recordingPath)) {
            $this->retryCache->rememberSkippedRecording($record, 'file_too_large', $recordingPath);
            return;
        }

        $duration = $this->evaluator->recordingDurationDecision($record, $recordingPath);
        if ($duration['state'] !== 'allowed') {
            $record = $this->evaluator->recordWithDurationDecision($record, $duration);
            $this->retryCache->rememberSkippedRecording(
                $record,
                $duration['state'] === 'too_long' ? 'duration_too_long' : 'duration_probe_failed',
                $recordingPath
            );
            return;
        }

        if (STTJob::existsForFilePath($recordingPath)) {
            return;
        }

        $this->evaluator->createJob($record, $recordingPath, $duration['duration_sec']);
    }

    /**
     * Prepares the id-based realtime cursor before every scan.
     * CDR_SCAN_CURSOR_ENDTIME is no longer part of the realtime *query* (see
     * createRealtimeJobsFromCompletedCdr()), but it is still read here, for
     * two different purposes depending on CDR_SCAN_CURSOR_MODE:
     *
     * - Fresh install (marker empty): start the cursor at the current
     *   MAX(id) so realtime picks up only calls completed from now on;
     *   existing history is covered by the separate backfill scan. Mode is
     *   set to 'id' directly - there is no legacy cursor to migrate from.
     * - Legacy install (marker set, mode not yet 'id'): the marker still
     *   holds a real (endtime, id) cursor written by a prior run of the old
     *   endtime-ordered scanner. Reusing CDR_SCAN_CURSOR_ID as-is here would
     *   be wrong: under (endtime, id) ordering the stored id is the id of
     *   the row with the greatest *endtime* processed so far, not the
     *   greatest id, so any still-pending backlog row with a smaller id but
     *   a larger endtime (batch-limited scanning had not reached it yet)
     *   would be permanently skipped by a naive `id > old_id` cursor. See
     *   migrateRealtimeScanCursorToIdMode() for the actual derivation.
     * - Mode already 'id': nothing to do, cursor is used as-is.
     */
    public function initializeRealtimeScanCursor(): void
    {
        $endtimeMarker = trim(STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, ''));
        if ($endtimeMarker === '') {
            $maxId = (int)(CallDetailRecords::maximum(['column' => 'id']) ?: 0);
            $this->saveScanCursor($maxId);
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, date('Y-m-d H:i:s'));
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_MODE, STTSetting::CDR_SCAN_CURSOR_MODE_ID);
            return;
        }

        if (STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_MODE, '') === STTSetting::CDR_SCAN_CURSOR_MODE_ID) {
            return;
        }

        $this->migrateRealtimeScanCursorToIdMode($endtimeMarker);
    }

    /**
     * One-time migration from the legacy (endtime, id) realtime cursor to a
     * plain id watermark. Must find the smallest id among CDR rows the OLD
     * query would still have returned (same base filters, same
     * (endtime > :legacy_endtime: OR (endtime = :legacy_endtime: AND
     * id > :legacy_id:)) backlog condition) and back the new cursor off to
     * just below it, so nothing already pending under the old ordering gets
     * skipped by the new `id > cursor` condition. When no such backlog row
     * exists, the legacy id is already a safe id-cursor value as-is.
     *
     * The cursor is persisted before the mode marker: if the process is
     * interrupted in between, CDR_SCAN_CURSOR_MODE is still not 'id' on the
     * next run, so this method runs again and recomputes the exact same
     * result from the still-untouched legacy endtime/id pair - idempotent,
     * not a partial or duplicated migration.
     */
    public function migrateRealtimeScanCursorToIdMode(string $legacyEndtime): void
    {
        $legacyId = (int)STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID, '0');
        $historyStart = STTSetting::cdrHistoryStartDateTime();

        $conditions = 'recordingfile <> :empty: AND endtime <> :empty: AND work_completed = :completed:'
            . ' AND (endtime > :legacy_endtime: OR (endtime = :legacy_endtime: AND id > :legacy_id:))';
        $bind = [
            'empty' => '',
            'completed' => '1',
            'legacy_endtime' => $legacyEndtime,
            'legacy_id' => $legacyId,
        ];
        if ($historyStart !== '') {
            $conditions .= ' AND endtime >= :history_start:';
            $bind['history_start'] = $historyStart;
        }

        $pendingMinId = CallDetailRecords::minimum([
            'column' => 'id',
            'conditions' => $conditions,
            'bind' => $bind,
        ]);
        $pendingMinId = $pendingMinId === null || $pendingMinId === '' ? null : (int)$pendingMinId;

        $newCursorId = $pendingMinId !== null ? min($legacyId, $pendingMinId - 1) : $legacyId;

        $this->saveScanCursor($newCursorId);
        STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_MODE, STTSetting::CDR_SCAN_CURSOR_MODE_ID);

        LocalSpeechToTextLogger::event(
            'scanner',
            'realtime_cursor_migrated',
            'Realtime CDR scan cursor migrated from endtime ordering to id ordering',
            LOG_NOTICE,
            [
                'legacy_endtime' => $legacyEndtime,
                'legacy_id' => $legacyId,
                'new_cursor_id' => $newCursorId,
            ]
        );
    }

    public function scanCursor(): int
    {
        return (int)STTSetting::getValue(STTSetting::CDR_SCAN_CURSOR_ID, '0');
    }

    public function saveScanCursor(int $recordId): void
    {
        STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ID, (string)max(0, $recordId));
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function backfillCursor(): array
    {
        return [
            trim(STTSetting::getValue(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, '')),
            (int)STTSetting::getValue(STTSetting::CDR_BACKFILL_CURSOR_ID, '0'),
        ];
    }

    public function saveBackfillCursor(string $endtime, int $recordId): void
    {
        STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ID, (string)max(0, $recordId));
        STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, $endtime);
    }
}
