# Recording Duration Limit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the configurable file-size setting with a 1–1440 minute recording-duration setting while enforcing a fixed 500 MiB file guard throughout the local STT pipeline.

**Architecture:** `STTSetting` owns normalization and the fixed safety constant. The CDR scanner applies the duration decision before queue insertion and persists known duration on `STTJob`; the existing worker job payload exposes it through `duration_hint`. The download endpoint revalidates persisted duration and file size after ownership checks so setting changes also affect already-created jobs.

**Tech Stack:** PHP 8.3, Phalcon ORM/forms/Volt, SQLite module migrations, MikoPBX REST v3, standalone PHP contract tests.

## Global Constraints

- Operator setting key: `max_recording_duration_min`.
- Default: 60 minutes; inclusive range: 1–1440 minutes.
- Equal-to-limit duration is allowed; only greater known duration is rejected.
- Missing, invalid, negative, or zero CDR duration is unknown and allowed.
- Fixed file guard: exactly `500 * 1024 * 1024` bytes; no database setting controls it.
- Legacy `max_file_size_mb` values are ignored and are never migrated into minutes.
- Preserve worker ownership checks for `worker_uid`, `job_id`, and `lease_token` before download validation.
- Preserve existing scan cursors, history-window behavior, retry bounds, and worker result `duration_ms` contract.

---

## File Structure

- `Models/STTSetting.php`: setting names, normalization, duration boundary helpers, and fixed file-size constant.
- `App/Forms/ModuleLocalSpeechToTextForm.php`: numeric duration control.
- `App/Controllers/LocalSpeechToTextBaseController.php`: settings load/save contract and operational logging.
- `App/Views/ModuleLocalSpeechToText/index.volt`: duration field and localized hint.
- `Setup/PbxExtensionSetup.php`: fresh-install duration default and nullable job-duration migration.
- `Models/STTJob.php`: persisted known duration and creation API.
- `bin/WorkerLocalSpeechToTextJobs.php`: realtime/backfill/retry duration enforcement and skip state.
- `Lib/LocalSpeechToTextMain.php`: `duration_hint` and assigned-download revalidation.
- `Lib/Services/QueueViewService.php`: human-readable `duration_too_long` status.
- `Messages/*.php`: synchronized setting and queue-reason keys.
- `README.md`, `readme.ru.md`: updated operator/runtime behavior.
- `Tests/check-settings-normalization.php`: executable normalization and boundary tests.
- `Tests/check-recording-duration-limit-contract.php`: UI, queue, migration, REST, and legacy-removal contract.
- Existing affected tests: update model stubs and assertions where the `STTJob::createPending()` interface or error wording changes.

### Task 1: Settings Model and Administration UI

**Files:**
- Modify: `Tests/check-settings-normalization.php`
- Create: `Tests/check-recording-duration-limit-contract.php`
- Modify: `Models/STTSetting.php`
- Modify: `App/Forms/ModuleLocalSpeechToTextForm.php`
- Modify: `App/Controllers/LocalSpeechToTextBaseController.php`
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`
- Modify: `Setup/PbxExtensionSetup.php`

**Interfaces:**
- Produces: `STTSetting::MAX_RECORDING_DURATION_MIN`, `STTSetting::maxRecordingDurationMinutes(): int`, `STTSetting::maxRecordingDurationSeconds(): int`, `STTSetting::normalizeRecordingDurationMinutes(string|int): int`, `STTSetting::normalizeRecordingDurationSeconds(mixed): ?int`, `STTSetting::recordingDurationAllowed(mixed): bool`, and `STTSetting::maxFileSizeBytes(): int`.
- Consumes: existing `STTSetting::getValue()`, `setValue()`, and controller `postInt()` conventions.

- [ ] **Step 1: Replace the old size assertions with failing duration and fixed-size assertions**

Add to `Tests/check-settings-normalization.php`:

```php
ModulesModelsBase::$values = [];
expectSameSettingsNormalization(60, STTSetting::maxRecordingDurationMinutes(), 'Recording duration must default to 60 minutes');
expectSameSettingsNormalization(1, STTSetting::normalizeRecordingDurationMinutes(0), 'Recording duration must be at least one minute');
expectSameSettingsNormalization(1440, STTSetting::normalizeRecordingDurationMinutes(2000), 'Recording duration must be capped at 1440 minutes');

ModulesModelsBase::$values = [STTSetting::MAX_RECORDING_DURATION_MIN => '60'];
expectSameSettingsNormalization(3600, STTSetting::maxRecordingDurationSeconds(), 'Duration minutes must convert to seconds');
expectTrueSettingsNormalization(STTSetting::recordingDurationAllowed(3600), 'Boundary duration must be allowed');
expectTrueSettingsNormalization(!STTSetting::recordingDurationAllowed(3601), 'Duration above the boundary must be rejected');
expectTrueSettingsNormalization(STTSetting::recordingDurationAllowed(0), 'Unknown zero duration must be allowed');
expectTrueSettingsNormalization(STTSetting::recordingDurationAllowed(null), 'Missing duration must be allowed');

ModulesModelsBase::$values = ['max_file_size_mb' => '1'];
expectSameSettingsNormalization(500 * 1024 * 1024, STTSetting::maxFileSizeBytes(), 'File guard must remain fixed at 500 MiB');
```

- [ ] **Step 2: Add a failing static contract for the admin surface and legacy-key removal**

Create `Tests/check-recording-duration-limit-contract.php` to read the form, controller, view, setup, settings model, worker, job model, main API, and queue view. Initially assert the Task 1 slice:

```php
expectDurationContract(str_contains($settings, "MAX_RECORDING_DURATION_MIN = 'max_recording_duration_min'"), 'Duration setting key is missing');
expectDurationContract(!str_contains($form, 'max_file_size_mb'), 'Legacy size field must leave the form');
expectDurationContract(str_contains($form, "new Numeric('max_recording_duration_min'"), 'Duration field is missing');
expectDurationContract(str_contains($form, "'min' => 1") && str_contains($form, "'max' => 1440"), 'Duration field bounds are wrong');
expectDurationContract(str_contains($controller, 'STTSetting::MAX_RECORDING_DURATION_MIN'), 'Controller must load and save duration');
expectDurationContract(!str_contains($controller, 'STTSetting::MAX_FILE_SIZE_MB'), 'Controller must ignore the legacy size setting');
expectDurationContract(str_contains($view, "form.render('max_recording_duration_min')"), 'View must render the duration control');
expectDurationContract(!str_contains($setup, 'setDefaultSetting(STTSetting::MAX_FILE_SIZE_MB'), 'Fresh installs must not create the legacy setting');
```

- [ ] **Step 3: Run both tests and verify RED**

Run:

```bash
php Tests/check-settings-normalization.php
php Tests/check-recording-duration-limit-contract.php
```

Expected: both fail because the duration setting and form contract do not exist.

- [ ] **Step 4: Implement settings normalization and the fixed safety guard**

In `Models/STTSetting.php`, replace the legacy setting/constant and helper with:

```php
public const string MAX_RECORDING_DURATION_MIN = 'max_recording_duration_min';
private const int DEFAULT_MAX_RECORDING_DURATION_MIN = 60;
private const int MAX_RECORDING_DURATION_MINUTES = 1440;
private const int FIXED_MAX_FILE_SIZE_BYTES = 500 * 1024 * 1024;

public static function normalizeRecordingDurationMinutes(string|int $value): int
{
    return max(1, min(self::MAX_RECORDING_DURATION_MINUTES, (int)$value));
}

public static function maxRecordingDurationMinutes(): int
{
    return self::normalizeRecordingDurationMinutes(
        self::getValue(self::MAX_RECORDING_DURATION_MIN, (string)self::DEFAULT_MAX_RECORDING_DURATION_MIN)
    );
}

public static function maxRecordingDurationSeconds(): int
{
    return self::maxRecordingDurationMinutes() * 60;
}

public static function normalizeRecordingDurationSeconds(mixed $value): ?int
{
    if (!is_numeric($value) || (float)$value <= 0) {
        return null;
    }

    return (int)ceil((float)$value);
}

public static function recordingDurationAllowed(mixed $value): bool
{
    $seconds = self::normalizeRecordingDurationSeconds($value);

    return $seconds === null || $seconds <= self::maxRecordingDurationSeconds();
}

public static function maxFileSizeBytes(): int
{
    return self::FIXED_MAX_FILE_SIZE_BYTES;
}
```

Do not retain `MAX_FILE_SIZE_MB` or read the string `max_file_size_mb` in production code.

- [ ] **Step 5: Replace the form, controller, view, and installation default**

Use `max_recording_duration_min`, default `60`, min `1`, max `1440`. Save through `normalizeRecordingDurationMinutes()` and log `max_duration_min=%d`. In the view use translation keys `module_local_speech_to_text_MaxRecordingDuration` and `module_local_speech_to_text_MaxRecordingDurationHint`.

- [ ] **Step 6: Run Task 1 tests and full settings-administration contracts**

Run:

```bash
php Tests/check-settings-normalization.php
php Tests/check-recording-duration-limit-contract.php
php Tests/check-admin-settings-write-contract.php
```

Expected: all pass.

- [ ] **Step 7: Commit Task 1**

```bash
git add Models/STTSetting.php App/Forms/ModuleLocalSpeechToTextForm.php App/Controllers/LocalSpeechToTextBaseController.php App/Views/ModuleLocalSpeechToText/index.volt Setup/PbxExtensionSetup.php Tests/check-settings-normalization.php Tests/check-recording-duration-limit-contract.php
git commit -m "feat: replace file setting with duration limit"
```

### Task 2: Queue Enforcement and Job Duration Persistence

**Files:**
- Modify: `Tests/check-recording-duration-limit-contract.php`
- Modify: `Tests/check-worker-retry-overflow.php`
- Modify: `Tests/check-stt-job-lease-sqlite.php`
- Modify: `Models/STTJob.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`

**Interfaces:**
- Consumes: Task 1 duration helpers.
- Produces: nullable `STTJob::$recording_duration_sec`, `STTJob::createPending(..., ?int $recordingDurationSec = null)`, and an idempotent `ensureJobRecordingDurationColumn()` migration.

- [ ] **Step 1: Extend the contract with failing persistence and queue assertions**

Add assertions that production code contains:

```php
expectDurationContract(str_contains($job, 'public ?string $recording_duration_sec = null'), 'Job duration field is missing');
expectDurationContract(str_contains($setup, 'ensureJobRecordingDurationColumn'), 'Job duration migration is missing');
expectDurationContract(str_contains($setup, 'ALTER TABLE m_STTJob ADD COLUMN recording_duration_sec INTEGER'), 'Job duration column SQL is missing');
expectDurationContract(str_contains($worker, "'duration_too_long'"), 'Queue must retain an over-duration skip reason');
expectDurationContract(str_contains($worker, 'STTSetting::recordingDurationAllowed'), 'Queue must use shared duration validation');
expectDurationContract(str_contains($worker, "'recording_duration_sec'"), 'Retry payload must retain known duration');
```

Update the `STTJob` stub in `Tests/check-worker-retry-overflow.php` so `createPending()` accepts the new nullable duration argument and records it for assertions.

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
php Tests/check-recording-duration-limit-contract.php
php Tests/check-worker-retry-overflow.php
php Tests/check-stt-job-lease-sqlite.php
```

Expected: contract fails on the absent job field/migration/queue checks; existing stubs identify signature changes needed.

- [ ] **Step 3: Persist duration on `STTJob` and add the idempotent migration**

Add the annotated nullable property, append the parameter to `createPending()`, and insert the assignment immediately before the existing `save()` check:

```php
/** @Column(type="integer", nullable=true) */
public ?string $recording_duration_sec = null;
```

```diff
-        array $recordingIdentity = []
+        array $recordingIdentity = [],
+        ?int $recordingDurationSec = null
```

```php
$job->recording_duration_sec = $recordingDurationSec !== null && $recordingDurationSec > 0
    ? (string)$recordingDurationSec
    : null;
```

Call a new `ensureJobRecordingDurationColumn()` from `installDB()` and add the column only when `describeColumns()` does not contain it. Do not backfill old jobs.

- [ ] **Step 4: Apply duration validation consistently in all scanner paths**

Add focused worker helpers:

```php
private function recordingDurationSeconds(array $record): ?int
{
    return STTSetting::normalizeRecordingDurationSeconds(
        $record['recording_duration_sec'] ?? $record['duration'] ?? null
    );
}

private function recordingWithinDurationLimit(array $record): bool
{
    return STTSetting::recordingDurationAllowed($this->recordingDurationSeconds($record));
}
```

In initial processing, delayed retry, and skipped retry, record `duration_too_long`, log the configured limit, and defer/expire it using the existing skipped retry rules. Add `recording_duration_sec` to delayed and skipped payloads. Pass the normalized value to every `STTJob::createPending()` call.

- [ ] **Step 5: Add boundary and recovery coverage to the retry test**

Use reflection following the existing test style to prove:

- a record at 3600 seconds can create a job;
- a record at 3601 seconds is stored as `duration_too_long`;
- zero/absent duration can create a job;
- a saved `duration_too_long` record can recover after the configured value changes upward.

- [ ] **Step 6: Run queue and persistence tests**

Run:

```bash
php Tests/check-recording-duration-limit-contract.php
php Tests/check-worker-retry-overflow.php
php Tests/check-stt-job-lease-sqlite.php
php Tests/check-queue-lifecycle-contract.php
```

Expected: all pass.

- [ ] **Step 7: Commit Task 2**

```bash
git add Models/STTJob.php Setup/PbxExtensionSetup.php bin/WorkerLocalSpeechToTextJobs.php Tests/check-recording-duration-limit-contract.php Tests/check-worker-retry-overflow.php Tests/check-stt-job-lease-sqlite.php
git commit -m "feat: enforce recording duration in queue"
```

### Task 3: Worker Payload and Download Revalidation

**Files:**
- Modify: `Tests/check-recording-duration-limit-contract.php`
- Modify: `Tests/check-openapi-contract.php`
- Modify: `Tests/check-queue-lifecycle-contract.php`
- Modify: `Lib/LocalSpeechToTextMain.php`
- Modify: `Lib/RestAPI/Jobs/DataStructure.php`
- Modify: `Lib/Services/QueueViewService.php`

**Interfaces:**
- Consumes: persisted `STTJob::$recording_duration_sec` and Task 1 limits.
- Produces: claimed-job `duration_hint` in seconds; stable download error code `duration_too_long`; localized queue reason key `module_local_speech_to_text_QueueSkipReasonDurationTooLong`.

- [ ] **Step 1: Add failing payload, ownership-order, and download assertions**

Extend the duration contract:

```php
expectDurationContract(str_contains($main, "'duration_hint' => self::jobDurationSeconds(\$job)"), 'Claim response must expose job duration');
expectDurationContract(str_contains($main, "self::error('duration_too_long'"), 'Download must expose stable duration error');
expectDurationContract(str_contains($main, 'Recording duration exceeds configured maximum'), 'Download must terminally record the duration failure');
expectDurationContract(strpos($downloadBody, 'validateAssignedJob') < strpos($downloadBody, 'duration_too_long'), 'Ownership validation must precede duration validation');
expectDurationContract(str_contains($queueView, "'duration_too_long' =>"), 'Queue view must label duration skips');
```

Update OpenAPI assertions to require nullable numeric `duration_hint` with a seconds description.

- [ ] **Step 2: Run API contracts and verify RED**

Run:

```bash
php Tests/check-recording-duration-limit-contract.php
php Tests/check-openapi-contract.php
php Tests/check-queue-lifecycle-contract.php
```

Expected: fail on null-only `duration_hint`, absent duration download error, and absent queue mapping.

- [ ] **Step 3: Populate `duration_hint` without changing the worker request schema**

Add:

```php
private static function jobDurationSeconds(STTJob $job): ?int
{
    $duration = (int)$job->recording_duration_sec;

    return $duration > 0 ? $duration : null;
}
```

Use it from `formatJob()`. Clarify in `DataStructure.php` that `duration_hint` is seconds from CDR and may be null.

- [ ] **Step 4: Revalidate duration after assignment ownership and before file streaming**

In `downloadJobFile()`, after `validateAssignedJob()` succeeds and before the fixed size check:

```php
if (!STTSetting::recordingDurationAllowed(self::jobDurationSeconds($job))) {
    $message = 'Recording duration exceeds configured maximum';
    if (!$job->markTerminalErrorForWorker((int)$worker->id, $leaseToken, $message)) {
        return self::assignedJobConflict($jobId, $worker, $leaseToken);
    }

    LocalSpeechToTextLogger::write('api', sprintf('Job #%d failed permanently: %s', $jobId, $message), LOG_WARNING);

    return self::error('duration_too_long', $message, 413);
}
```

Keep the existing safe-path and 500 MiB validation. Rename user-visible size text from “configured maximum size” to “fixed maximum size” so logs do not imply an operator setting.

- [ ] **Step 5: Map the queue reason**

Add `duration_too_long` to `queueSkippedReasonLabel()` using `module_local_speech_to_text_QueueSkipReasonDurationTooLong`.

- [ ] **Step 6: Run API and queue contracts**

Run:

```bash
php Tests/check-recording-duration-limit-contract.php
php Tests/check-openapi-contract.php
php Tests/check-rest-api-contract.php
php Tests/check-queue-lifecycle-contract.php
php Tests/check-worker-api-key-permissions.php
```

Expected: all pass, including ownership-order assertions.

- [ ] **Step 7: Commit Task 3**

```bash
git add Lib/LocalSpeechToTextMain.php Lib/RestAPI/Jobs/DataStructure.php Lib/Services/QueueViewService.php Tests/check-recording-duration-limit-contract.php Tests/check-openapi-contract.php Tests/check-queue-lifecycle-contract.php
git commit -m "feat: revalidate duration for worker downloads"
```

### Task 4: Localization and Operator Documentation

**Files:**
- Modify: `Messages/ru.php`
- Modify: `Messages/en.php`
- Modify: every other `Messages/*.php`
- Modify: `README.md`
- Modify: `readme.ru.md`
- Modify: `Tests/check-recording-duration-limit-contract.php`

**Interfaces:**
- Consumes: UI and queue translation keys introduced in Tasks 1 and 3.
- Produces: synchronized keys in every module catalog.

- [ ] **Step 1: Add failing translation-key assertions**

In the duration contract, parse every `Messages/*.php` file and assert that it contains these keys and no legacy keys:

```php
$requiredMessageKeys = [
    'module_local_speech_to_text_MaxRecordingDuration',
    'module_local_speech_to_text_MaxRecordingDurationHint',
    'module_local_speech_to_text_QueueSkipReasonDurationTooLong',
];
```

Also assert that README files contain `60`, `1440`, and `500` adjacent to duration/file-limit explanations and no longer call maximum file size configurable.

- [ ] **Step 2: Run the duration contract and verify RED**

Run:

```bash
php Tests/check-recording-duration-limit-contract.php
```

Expected: fail on missing catalog keys and stale documentation.

- [ ] **Step 3: Update Russian and English source wording**

Use these canonical values:

```php
// Messages/ru.php
'module_local_speech_to_text_MaxRecordingDuration' => 'Максимальная длительность распознаваемой записи, мин.',
'module_local_speech_to_text_MaxRecordingDurationHint' => 'Записи с известной длительностью больше этого лимита будут пропущены и отмечены как «Пропущенные записи».',
'module_local_speech_to_text_QueueSkipReasonDurationTooLong' => 'Длительность записи превышает установленный лимит',

// Messages/en.php
'module_local_speech_to_text_MaxRecordingDuration' => 'Maximum recognizable recording duration, min.',
'module_local_speech_to_text_MaxRecordingDurationHint' => 'Recordings with a known duration above this limit are skipped and shown under “Skipped recordings”.',
'module_local_speech_to_text_QueueSkipReasonDurationTooLong' => 'Recording duration exceeds the configured limit',
```

Remove `MaxFileSize` and `MaxFileSizeHint`. Synchronize the same new keys across every other catalog, preserving existing translations where supplied and using the repository's current English fallback convention where the catalog lacks a maintained translation.

- [ ] **Step 4: Update both readmes**

Document the 60-minute default, 1–1440 range, equal boundary, unknown-duration allowance, fixed 500 MiB guard, retry-after-limit-increase behavior, `duration_hint` seconds, and download-time revalidation.

- [ ] **Step 5: Validate PHP catalogs and exact key parity**

Run:

```bash
find Messages -name '*.php' -print0 | xargs -0 -n1 php -l
php -r '$files=glob("Messages/*.php"); $base=array_keys(include "Messages/ru.php"); sort($base); foreach($files as $file){$keys=array_keys(include $file); sort($keys); if($keys!==$base){fwrite(STDERR,"Key mismatch: $file\n"); exit(1);}} echo "Translation keys synchronized\n";'
php Tests/check-recording-duration-limit-contract.php
```

Expected: no syntax errors, `Translation keys synchronized`, contract passes.

- [ ] **Step 6: Commit Task 4**

```bash
git add Messages README.md readme.ru.md Tests/check-recording-duration-limit-contract.php
git commit -m "docs: describe recording duration limits"
```

### Task 5: Full Verification and Packaging

**Files:**
- Verify only; modify production or tests only if a failing check exposes an in-scope defect.

**Interfaces:**
- Consumes: completed Tasks 1–4.
- Produces: fresh evidence that contracts, syntax, legacy-removal scans, and package integrity all pass.

- [ ] **Step 1: Scan production for legacy configurable-size references**

Run:

```bash
rg -n "MAX_FILE_SIZE_MB|max_file_size_mb|MaxFileSize|configured maximum size" App Lib Models Setup bin Messages README.md readme.ru.md
```

Expected: no matches. References may remain only in the design/plan and historical Git commits.

- [ ] **Step 2: Run all PHP syntax checks**

Run:

```bash
find App Lib Models Setup Tests bin Messages -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports no syntax errors.

- [ ] **Step 3: Run the complete module contract suite**

Run:

```bash
php Tests/run-all.php
```

Expected: `All module contract tests passed` with zero failed checks.

- [ ] **Step 4: Build and validate the release package**

Run:

```bash
package_output="$(mktemp -d /tmp/module-local-stt-duration-package.XXXXXX)"
./scripts/package-module.sh "$package_output"
unzip -t "$package_output"/*.zip
```

Expected: package script prints a SHA-256 checksum and `unzip -t` reports no errors.

- [ ] **Step 5: Review the final diff against the specification**

Run:

```bash
git diff HEAD~4 --check
git status --short
git log -5 --oneline
```

Confirm every design requirement maps to tested code and no unrelated files changed.
