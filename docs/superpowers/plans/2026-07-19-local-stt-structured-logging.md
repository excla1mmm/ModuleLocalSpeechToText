# Structured STT Module Logging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a two-generation, privacy-safe JSONL journal and a structured STT diagnostics interface without changing queue or worker contracts.

**Architecture:** A bounded writer and sanitizer sit behind one event logger; a tail reader exposes compatible plus structured response fields; a strict allowlist migration runs during setup. Existing Volt/Semantic UI/jQuery renders and filters escaped structured entries.

**Tech Stack:** PHP 8.3, standalone PHP contract tests, Volt, Semantic UI, jQuery, module message catalogs.

## Global Constraints

- Journal files are only `module.log` and `module.log.1`, each at most 5,242,880 bytes.
- Directory mode is `0750`; journal and lock modes are `0640`; all filesystem operations reject symlinks and paths outside the validated module directory.
- Context contains bounded scalars only; secrets, transcript/prompt/audio content, phone numbers, recording paths, handler payloads, and worker-controlled error text are forbidden.
- Only warning and error events mirror to MikoPBX syslog.
- Queue, lease, heartbeat, retry, recording delivery, and publication semantics remain unchanged.
- Preserve all pre-existing dirty-worktree changes, especially REST/OpenAPI and translation additions.

---

### Task 1: Lock the new contracts with failing tests

**Files:**
- Create: `Tests/check-structured-log-writer.php`
- Create: `Tests/check-structured-log-reader.php`
- Create: `Tests/check-legacy-log-migration.php`
- Create: `Tests/check-structured-log-ui-contract.php`
- Modify: `Tests/check-module-logging-contract.php`

- [ ] Add real writer/logger tests for six-field JSONL, every required redaction family, warning/error-only mirror, exact rotation set, permissions, concurrent append completeness, and symlink refusal.
- [ ] Add reader fixtures for both generations, tail ordering, 1000 valid events, malformed counts/states, missing/unavailable files, source allowlist, and all supported periods including zero.
- [ ] Add migration fixtures for every real legacy channel, rotations, unknown admin files, symlinks, source basenames, deletion guards, replacement failure, and idempotence.
- [ ] Add source contracts for structured production events, no heartbeat/progress/empty-poll/log-request noise, no worker error text, compatible response fields, filters/states, escaped rendering, and request sequence checks.
- [ ] Run the five focused tests and confirm they fail because the new classes/contracts do not exist.

### Task 2: Implement storage, sanitization, logger, reader, and migration

**Files:**
- Create: `Lib/Services/LocalSpeechToTextLogWriter.php`
- Create: `Lib/Services/LocalSpeechToTextLogSanitizer.php`
- Replace: `Lib/Services/LocalSpeechToTextLogger.php`
- Replace: `Lib/Services/ModuleLogReader.php`
- Create: `Lib/Services/LocalSpeechToTextLegacyLogMigration.php`

**Interfaces:**
- `LocalSpeechToTextLogWriter::__construct(string $directory, int $maxBytes = 5242880)`; `append(string): bool`; `replacePrevious(array): bool`.
- `LocalSpeechToTextLogSanitizer::message(string): string`; `context(array): array`; `errorCode(Throwable|string): string`.
- `LocalSpeechToTextLogger::event(string $component, string $event, string $message, int $level = LOG_INFO, array $context = []): void`; injectable instance `writeEvent(...)`.
- `ModuleLogReader::__construct(?string $directory = null, int $maxEntries = 1000)`; `recentForPeriod(int): array`.
- `LocalSpeechToTextLegacyLogMigration::run(): array{imported:int,removed:int,skipped:int}`.

- [ ] Implement verified directory/file handles, exclusive stable locking, bounded append, and one atomic previous-generation replacement.
- [ ] Implement bounded sanitization and machine identifier normalization without accepting nested context.
- [ ] Build structured events and safe warning/error summaries; keep `SystemMessages::sysLogMsg` only inside the logger.
- [ ] Implement newest-first 64 KiB tail iteration and the full compatibility/structured payload.
- [ ] Implement the exact legacy-name allowlist and guarded deletion workflow.
- [ ] Run writer, reader, and migration tests until green; rerun after refactoring.

### Task 3: Adopt useful structured lifecycle events

**Files:**
- Modify: `Lib/LocalSpeechToTextMain.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Modify: `App/Controllers/LocalSpeechToTextBaseController.php`
- Modify: `App/Controllers/ModuleLocalSpeechToTextController.php`
- Modify: `Lib/LocalSpeechToTextConf.php`
- Modify: `Lib/Services/ModuleMetadataCache.php`
- Modify: `Lib/Services/ModuleDatabasePragmas.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Modify: `Tests/check-worker-retry-overflow.php`

- [ ] Replace every plaintext call with a stable component/event/message and scalar safe context.
- [ ] Capture only committed result metrics and the internal completion event ID; never log transcript data or worker `error_msg`.
- [ ] Return created job objects from existing creation calls only to obtain numeric IDs; do not alter queue ordering or transitions.
- [ ] Log delayed/skipped records only on first observation and non-zero periodic summaries only.
- [ ] Keep heartbeat, empty poll, log retrieval, progress, and segments silent.
- [ ] Run event/noise contracts and queue lifecycle tests until green.

### Task 4: Run guarded setup migration

**Files:**
- Modify: `Setup/PbxExtensionSetup.php`
- Test: `Tests/check-legacy-log-migration.php`

- [ ] Invoke migration during `installDB()` before new setup events are written.
- [ ] Emit only a counter summary when migration performed work; failures use a stable operation/error code and preserve sources.
- [ ] Verify second run is a no-op and unknown files remain byte-identical.

### Task 5: Replace the raw log viewer

**Files:**
- Modify: `App/Controllers/ModuleLocalSpeechToTextController.php`
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`
- Modify: `public/assets/js/src/module-local-speech-to-text.js`
- Modify: `public/assets/js/module-local-speech-to-text.js`
- Modify: `public/assets/css/module-local-speech-to-text.css`
- Test: `Tests/check-structured-log-ui-contract.php`

- [ ] Return every reader field directly from `logsAction()` and accept only `0/1h/3h/12h/1d`.
- [ ] Add compact title/help, period/level/component/search controls, refresh, counts, updated time, cards, details, and explicit state containers.
- [ ] Render DOM nodes with `.text()` and filter locally without mutating server data.
- [ ] Increment a request generation before each load and ignore every callback whose generation is stale.
- [ ] Copy the reviewed source JavaScript to the deployed asset and run the UI contract.

### Task 6: Synchronize translations without overwriting existing work

**Files:**
- Modify: `Messages/ru.php`
- Modify: `Messages/en.php`
- Modify: the remaining 27 `Messages/*.php` catalogs

- [ ] Add native Russian and English operational UI strings and component/level labels.
- [ ] Add English fallback values under identical keys to the other catalogs while preserving all pre-existing OpenAPI additions.
- [ ] Parse every PHP catalog, compare exact key sets with Russian, and run `php -l` for all changed catalogs.

### Task 7: Full verification and reviews

**Files:**
- Modify only files required by review findings.

- [ ] Run focused logging, REST, UI, queue, setup, and translation tests.
- [ ] Run `php Tests/run-all.php`, `./scripts/package-module.sh /tmp/module-local-stt-structured-log-build`, and `git diff --check`.
- [ ] Run PHP syntax checks for all changed PHP files and static searches for direct syslog, arbitrary channel logs, recursive/shared readers, `.2`, and `.gz` behavior.
- [ ] Inspect the ZIP to confirm it contains no `.env`, logs, tests, docs, or temporary files; compute SHA-256.
- [ ] Request `module-security-reviewer`, `module-contract-guardian`, and `queue-resilience-reviewer`; fix all Blocking/High/Medium findings and request re-review.
- [ ] Re-run the complete evidence suite after the final fixes and report unrelated failures separately.
