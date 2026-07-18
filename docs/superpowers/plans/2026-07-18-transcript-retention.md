# Transcript Retention Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a one-year-by-default transcript retention policy, a compatible settings slider, and safe batched cleanup measured from recognition completion.

**Architecture:** Store discrete processing and retention policies in `m_STTSetting`, validate their relationship in both browser and controller, and run a dedicated SQL cleanup service from the existing scanner worker. Delete complete call-level transcript graphs transactionally while preserving job rows as deduplication tombstones.

**Tech Stack:** PHP 8.3, Phalcon models/controllers, SQLite, Volt, Semantic UI slider/form validation, jQuery, module PHP contract tests.

## Global Constraints

- Retention presets are exactly `30`, `90`, `180`, `365`, and `0`; default is `365`.
- Processing presets are `1`, `7`, `30`, `90`, `180`, `365`, and `0`; legacy `730` normalizes to `365`.
- A finite retention allows only a finite processing window less than or equal to retention; processing `0` requires retention `0`.
- Invalid combinations must be rejected before any setting is written and must not be silently corrected during user interaction.
- Expiration uses `m_STTResult.created_at`; a non-empty call is removed only when its newest result is expired.
- Never delete `m_STTJob`, CDR rows, or recording files.
- Cleanup is bounded, transactional, retried by the existing transaction helper, and scheduled by the existing STT worker.
- Russian is the source catalog; every `Messages/*.php` file must retain identical keys.

---

### Task 1: Settings policy and upgrade migration

**Files:**
- Modify: `Models/STTSetting.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Modify: `Lib/Services/ModuleDatabaseIndexService.php`
- Modify: `Tests/fixtures/schema/legacy-current.sql`
- Modify: `Tests/Integration/check-module-upgrade-sqlite.php`
- Create: `Tests/check-transcript-retention-settings-contract.php`

**Interfaces:**
- Produces: `STTSetting::TRANSCRIPT_RETENTION_DAYS`, `STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT`, `transcriptRetentionDays(): int`, `normalizeTranscriptRetentionDays(string|int): int`, and `retentionAllowsProcessing(int, int): bool`.
- Produces: schema migration 10, which creates retention defaults, normalizes legacy processing values, and creates `m_STTResult_created_at`.

- [ ] **Step 1: Write failing policy and migration contracts**

```php
expectRetentionSettings(
    STTSetting::normalizeCdrHistoryDays(730) === 365,
    'legacy two-year processing window must normalize to one year'
);
expectRetentionSettings(
    STTSetting::normalizeTranscriptRetentionDays(999) === 365,
    'unsupported retention must use the one-year default'
);
expectRetentionSettings(STTSetting::retentionAllowsProcessing(365, 365), 'equal finite windows must be valid');
expectRetentionSettings(!STTSetting::retentionAllowsProcessing(0, 365), 'all-recordings processing requires unlimited retention');
expectRetentionSettings(STTSetting::retentionAllowsProcessing(0, 0), 'both unlimited windows must be valid');
```

Update the isolated upgrade rehearsal to expect migrations `1..10`, two new settings, normalization of legacy `cdr_history_days`, and the retention timestamp index.

- [ ] **Step 2: Run tests and verify RED**

Run: `php Tests/check-transcript-retention-settings-contract.php && php Tests/check-module-structure-contract.php`

Expected: failure because the new constants, methods, and migration do not exist.

- [ ] **Step 3: Implement policy and migration**

Add the exact preset arrays and normalization rules to `STTSetting`. Add migration 10 to `PbxExtensionSetup::moduleSchemaMigrations()` and use bound SQL to insert:

```php
STTSetting::TRANSCRIPT_RETENTION_DAYS => '365',
STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT => '0',
```

Normalize stored processing values through `STTSetting::normalizeCdrHistoryDays()` and ensure:

```sql
CREATE INDEX IF NOT EXISTS m_STTResult_created_at ON m_STTResult (created_at)
```

- [ ] **Step 4: Run focused settings and migration tests**

Run: `php Tests/check-transcript-retention-settings-contract.php && php Tests/check-module-indexes.php && php Tests/check-module-schema-migrator.php`

Expected: every command exits 0.

- [ ] **Step 5: Commit settings policy**

```bash
git add Models/STTSetting.php Setup/PbxExtensionSetup.php Lib/Services/ModuleDatabaseIndexService.php Tests/fixtures/schema/legacy-current.sql Tests/Integration/check-module-upgrade-sqlite.php Tests/check-transcript-retention-settings-contract.php
git commit -m "feat: add transcript retention settings policy"
```

### Task 2: Settings UI and dual-layer validation

**Files:**
- Modify: `App/Forms/ModuleLocalSpeechToTextForm.php`
- Modify: `App/Controllers/LocalSpeechToTextBaseController.php`
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`
- Modify: `public/assets/js/src/module-local-speech-to-text.js`
- Modify: `public/assets/js/module-local-speech-to-text.js`
- Modify: `public/assets/css/module-local-speech-to-text.css`
- Create: `Tests/check-transcript-retention-ui-contract.php`
- Create: `Tests/check-transcript-retention-save-contract.php`

**Interfaces:**
- Consumes: Task 1 setting constants and `retentionAllowsProcessing()`.
- Produces: hidden `transcript_retention_days`, slider `#stt-transcript-retention-days-slider`, visible `.stt-retention-conflict-message`, and Semantic UI rule `sttRetentionCompatibility`.

- [ ] **Step 1: Write failing UI and server-save contracts**

Assert that the view renders the retention slider below the processing slider, JavaScript contains only the supported values, the former `730` choice is absent, source and deployed JS match, and `saveSettingsFromPost()` evaluates compatibility before its first `STTSetting::setValue()`.

```php
expectRetentionSave(
    $compatibilityPosition < $firstWritePosition,
    'retention compatibility must be validated before any setting write'
);
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php Tests/check-transcript-retention-ui-contract.php && php Tests/check-transcript-retention-save-contract.php`

Expected: failure because the new form field, slider, and validation are absent.

- [ ] **Step 3: Implement form, markup, CSS, and JavaScript**

Add the hidden field with default `365`, the second segment and explanatory copy, plus an initially hidden negative message. Initialize the slider with `['30', '90', '180', '365', '0']`. Change the processing slider to `['1', '7', '30', '90', '180', '365', '0']`.

Implement one shared predicate in JavaScript:

```javascript
function retentionAllowsProcessing(processingDays, retentionDays) {
    return retentionDays === 0 || (processingDays > 0 && processingDays <= retentionDays);
}
```

Toggle the conflict message on either slider change and attach `sttRetentionCompatibility` to both Form validation paths. Apply the identical JavaScript patch to `public/assets/js/src/module-local-speech-to-text.js` and `public/assets/js/module-local-speech-to-text.js`, then verify them with `cmp`.

- [ ] **Step 4: Implement authoritative pre-write validation**

Read and normalize both posted values before the first setting write. Throw `InvalidArgumentException` with the localized conflict text if incompatible. Save retention and reset `transcript_retention_last_cleanup_at` to `0` when retention changes.

- [ ] **Step 5: Run focused UI and save tests**

Run: `php Tests/check-transcript-retention-ui-contract.php && php Tests/check-transcript-retention-save-contract.php && cmp public/assets/js/src/module-local-speech-to-text.js public/assets/js/module-local-speech-to-text.js`

Expected: both tests pass and `cmp` exits 0.

- [ ] **Step 6: Commit UI and validation**

```bash
git add App/Forms/ModuleLocalSpeechToTextForm.php App/Controllers/LocalSpeechToTextBaseController.php App/Views/ModuleLocalSpeechToText/index.volt public/assets/js/src/module-local-speech-to-text.js public/assets/js/module-local-speech-to-text.js public/assets/css/module-local-speech-to-text.css Tests/check-transcript-retention-ui-contract.php Tests/check-transcript-retention-save-contract.php
git commit -m "feat: add transcript retention controls"
```

### Task 3: Transactional retention cleanup and worker scheduling

**Files:**
- Create: `Lib/Services/TranscriptRetentionService.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Create: `Tests/check-transcript-retention-sqlite.php`
- Create: `Tests/check-transcript-retention-worker-contract.php`

**Interfaces:**
- Consumes: Task 1 retention settings and existing `ModuleTransaction`.
- Produces: `TranscriptRetentionService::purgeExpired(int $retentionDays, int $now, int $batchSize = 100): array{calls:int,results:int,has_more:bool,cutoff:int}`.
- Produces: worker method `purgeExpiredTranscripts()` with a one-hour completed-sweep interval and immediate continuation while `has_more` is true.

- [ ] **Step 1: Write failing SQLite behavior test**

Build an isolated SQLite graph containing expired, recent, mixed-age multi-part, and empty-call results. Assert that a 365-day purge:

```php
expectRetentionSqlite($summary['calls'] === 2, 'expired call count mismatch');
expectRetentionSqlite($summary['results'] === 2, 'expired result count mismatch');
expectRetentionSqlite(tableCount($pdo, 'm_STTJob') === 5, 'job tombstones must remain');
expectRetentionSqlite(tableCount($pdo, 'm_STTResult') === 3, 'recent and mixed-age calls must remain');
```

Also assert unlimited no-op, batch continuation, and rollback after an injected SQL failure.

- [ ] **Step 2: Run test and verify RED**

Run: `php Tests/check-transcript-retention-sqlite.php`

Expected: failure because `TranscriptRetentionService` does not exist.

- [ ] **Step 3: Implement the cleanup service**

Select at most `batchSize + 1` retention keys grouped by non-empty `call_id`, using `MAX(r.created_at) < :cutoff`. Treat empty call IDs as per-result keys. Inside `ModuleTransaction`, resolve bound result and aggregate IDs and delete in the six-table dependency order from the design. Never issue a delete against `m_STTJob`.

- [ ] **Step 4: Run SQLite test and verify GREEN**

Run: `php Tests/check-transcript-retention-sqlite.php`

Expected: `Transcript retention SQLite behavior OK`.

- [ ] **Step 5: Write failing worker scheduling contract**

Assert the worker invokes retention after publishing ready call transcripts, skips completed sweeps newer than 3600 seconds, leaves the marker due while `has_more`, advances it after draining, and logs only counts/cutoff.

- [ ] **Step 6: Implement worker scheduling and run focused checks**

Call `purgeExpiredTranscripts()` once per normal worker iteration under the existing lifecycle lease. Use `STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT` for scheduling and `LocalSpeechToTextLogger::syslog()` for count-only notices.

Run: `php Tests/check-transcript-retention-worker-contract.php && php Tests/check-transcript-retention-sqlite.php`

Expected: both checks pass.

- [ ] **Step 7: Commit cleanup mechanics**

```bash
git add Lib/Services/TranscriptRetentionService.php bin/WorkerLocalSpeechToTextJobs.php Tests/check-transcript-retention-sqlite.php Tests/check-transcript-retention-worker-contract.php
git commit -m "feat: purge expired transcripts safely"
```

### Task 4: Translation catalogs and full verification

**Files:**
- Modify: `Messages/ru.php`
- Modify: `Messages/en.php`
- Modify: every other `Messages/*.php`
- Create: `Tests/check-transcript-retention-translations.php`

**Interfaces:**
- Produces: identical new translation keys for retention title, hint, conflict, and slider labels in every module catalog.

- [ ] **Step 1: Write failing translation parity check**

Require exact Russian and English values and verify that every catalog key set equals the sorted Russian source key set.

- [ ] **Step 2: Run test and verify RED**

Run: `php Tests/check-transcript-retention-translations.php`

Expected: failure listing missing retention keys.

- [ ] **Step 3: Add translations**

Add these keys to every catalog:

```php
'module_local_speech_to_text_TranscriptRetentionDays',
'module_local_speech_to_text_TranscriptRetentionDaysHint',
'module_local_speech_to_text_TranscriptRetention30Days',
'module_local_speech_to_text_TranscriptRetention90Days',
'module_local_speech_to_text_TranscriptRetention180Days',
'module_local_speech_to_text_TranscriptRetention365Days',
'module_local_speech_to_text_TranscriptRetentionUnlimited',
'module_local_speech_to_text_TranscriptRetentionConflict',
```

Use these Russian values:

```php
'module_local_speech_to_text_TranscriptRetentionDays' => 'Срок хранения расшифровок',
'module_local_speech_to_text_TranscriptRetentionDaysHint' => 'Срок отсчитывается от момента завершения распознавания звонка. CDR и аудиозаписи не удаляются.',
'module_local_speech_to_text_TranscriptRetention30Days' => '30 дней',
'module_local_speech_to_text_TranscriptRetention90Days' => '90 дней',
'module_local_speech_to_text_TranscriptRetention180Days' => '180 дней',
'module_local_speech_to_text_TranscriptRetention365Days' => '1 год',
'module_local_speech_to_text_TranscriptRetentionUnlimited' => 'Бесконечно',
'module_local_speech_to_text_TranscriptRetentionConflict' => 'Период обработки записей не может превышать срок хранения расшифровок. Увеличьте срок хранения или уменьшите период обработки.',
```

Use these English values in `en.php` and the remaining catalogs:

```php
'module_local_speech_to_text_TranscriptRetentionDays' => 'Transcript retention',
'module_local_speech_to_text_TranscriptRetentionDaysHint' => 'Retention starts when call recognition completes. CDR records and audio recordings are not deleted.',
'module_local_speech_to_text_TranscriptRetention30Days' => '30 days',
'module_local_speech_to_text_TranscriptRetention90Days' => '90 days',
'module_local_speech_to_text_TranscriptRetention180Days' => '180 days',
'module_local_speech_to_text_TranscriptRetention365Days' => '1 year',
'module_local_speech_to_text_TranscriptRetentionUnlimited' => 'Unlimited',
'module_local_speech_to_text_TranscriptRetentionConflict' => 'The recording processing window cannot exceed transcript retention. Increase retention or reduce the processing window.',
```

- [ ] **Step 4: Validate catalogs**

Run: `for file in Messages/*.php; do php -l "$file"; done && php Tests/check-transcript-retention-translations.php`

Expected: no syntax errors and translation contract passes.

- [ ] **Step 5: Run complete verification**

Run:

```bash
php Tests/run-all.php
git diff --check
./scripts/package-module.sh /tmp/module-local-stt-retention-package
```

Expected: all module contract checks pass, diff check is clean, and packaging prints a SHA-256 for the module ZIP.

- [ ] **Step 6: Commit translations and verification contract**

```bash
git add Messages Tests/check-transcript-retention-translations.php docs/superpowers/plans/2026-07-18-transcript-retention.md
git commit -m "feat: translate transcript retention settings"
```
