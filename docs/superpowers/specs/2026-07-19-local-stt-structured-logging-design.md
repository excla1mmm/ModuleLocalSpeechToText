# Structured STT Module Logging Design

## Goal

Replace channel-specific plaintext logs with one bounded, structured, privacy-safe module journal and a diagnostic UI that is useful without exposing recordings, transcripts, credentials, phone numbers, or filesystem paths.

## Existing behavior and constraints

The current logger derives a filename from every channel, appends plaintext with `file_put_contents`, creates the directory as `0755`, creates files as `0644`, and mirrors every `syslog()` call. Existing production channels create `api.log`, `web-ui.log`, `setup.log`, `workerlocalspeechtotextjobs.log`, `localspeechtotextmain.log`, and possibly `logger.log`. The reader recursively scans arbitrary module `*.log` files and shared `messages` files, then returns up to 2000 plaintext lines. The browser renders that result in a raw `<pre>`, offers week periods, and has no structured filters or stale-response guard.

The queue, lease, heartbeat, retry, recording download, transcript persistence, and `transcript.completed` state transitions remain unchanged. `LocalSTTWorkerMac` is outside the change because its API payload does not need to change.

## Approaches considered

1. **Adapt the proven AI Supervisor split and harden it for STT (selected).** Use focused writer, sanitizer, logger, reader, and migration classes, but keep the existing Volt/Semantic UI/jQuery stack. This offers direct tests for security boundaries and minimal behavioral coupling.
2. **Keep one expanded logger/reader pair.** This changes fewer files but mixes storage, redaction, event construction, migration, and reading, making concurrency and symlink behavior difficult to verify independently.
3. **Add a general logging dependency.** A third-party handler could rotate JSON, but it would not provide the required STT-specific migration, scalar context policy, REST compatibility, or package simplicity.

## Architecture

- `LocalSpeechToTextLogWriter` owns `<CORE_LOGS_DIR>/ModuleLocalSpeechToText/module.log`, `module.log.1`, and the stable non-journal lock `.module-log.lock`. It creates the directory as `0750`, all owned files as `0640`, serializes rotation and append under one exclusive lock, rejects symlinks and non-regular files, verifies opened inode/device pairs, and never creates `.2` or compressed rotations. Each journal generation is capped at 5,242,880 bytes.
- `LocalSpeechToTextLogSanitizer` removes control characters, line breaks, Bearer/Basic credentials, secret assignments and JSON fields, prompt/transcript material, Unix/macOS/Linux/Windows paths, URL userinfo, and phone-like digit sequences. It caps messages and context strings, accepts only scalar context values with machine-safe keys, drops nested values, and redacts semantic secret keys.
- `LocalSpeechToTextLogger` creates exactly the six required JSONL fields, normalizes level/component/event identifiers, writes through the writer, and mirrors only warning/error summaries through `SystemMessages::sysLogMsg`. Local exception logging uses a stable message plus a safe `error_code`; worker-provided error text is never copied.
- `ModuleLogReader` reads only `module.log` and `module.log.1`, newest-first in 64 KiB blocks, validates directory/source/handle containment, returns the newest 1000 valid events in chronological order, and counts malformed lines. It preserves `logText`, `lineCount`, `fileCount`, `generatedAt`, and `period`, and adds `entries`, `availableFrom`, `truncated`, `sourceStatus`, and `malformedCount`.
- `LocalSpeechToTextLegacyLogMigration` scans only the module directory and only the six known legacy basenames with numeric rotations. It converts the newest useful lines to `legacy_log_imported` events in `module.log.1`. A source is deleted only after a complete read, successful closed replacement, and unchanged device/inode/size/mtime/type/containment verification. Unknown files and symlinks remain untouched. Re-running after success is a no-op.

## REST states

Allowed periods are `3600`, `10800`, `43200`, `86400`, and `0`. Invalid periods fall back to one hour.

`sourceStatus` values are:

- `ok`: valid events are available;
- `empty`: readable journal files exist but contain no event in the selected period;
- `missing`: the validated directory exists but both journal files are absent;
- `unavailable`: the directory or every present source is unsafe/unreadable;
- `corrupt`: non-empty input contains malformed records and no valid event;
- `partial`: valid records are returned but malformed or inaccessible input was also encountered.

`truncated` means more than 1000 valid matching events were available. The compatibility `logText` is generated only from the returned structured entries.

## Diagnostic UI

The existing Logs tab becomes a compact “Module log” diagnostic view with a short explanation, period buttons, level and component selects, search, refresh, result count, updated time, and readable event cards. Cards show localized component, level, time, message, and expandable escaped technical details. The client distinguishes loading, no files, unavailable, empty period, locally filtered empty, corrupt, partial, and truncated states. All user-controlled data is inserted with text APIs. Every request gets a monotonically increasing ID; callbacks from older IDs are ignored even when aborting the transport races.

Russian is the source catalog, English has native copy, and the remaining catalogs receive the English operational fallback while preserving exact key parity.

## Event catalogue

| Component | Event | When | Safe context |
|---|---|---|---|
| `setup` | `module_setup_completed` | successful database setup/update | none |
| `setup` | `legacy_logs_migrated` | legacy migration imported or removed data | `imported_count`, `removed_count`, `skipped_count` |
| `settings` | `settings_changed` | settings/model selection/custom-model change | comma-separated `changed_fields` only |
| `settings` | `worker_api_key_created` / `worker_api_key_deleted` | key lifecycle | `api_key_id` |
| `worker` | `worker_registered` | registration succeeds | `worker_id`, `worker_uid` |
| `queue` | `job_created` | immediate, delayed, or skipped-recovery job creation | `job_id`, optional `recording_duration_ms`, `skip_reason` |
| `queue` | `job_claimed` | non-empty get-next succeeds | `job_id`, `worker_uid`, `attempt`, `max_attempts` |
| `queue` | `transcription_completed` | accepted result commits | `job_id`, `worker_uid`, `duration_ms`, `recording_duration_ms`, `segment_count`, `language`, `model` |
| `transcript` | `transcript_completed_published` | individual completion event commits | `job_id`, `published_event_id` |
| `queue` | `job_retry` / `job_failed` | worker error is accepted | `job_id`, `worker_uid`, `attempt`, `max_attempts`, stable `error_code` |
| `queue` | `job_released` | worker releases ownership | `job_id`, `worker_uid`, `attempt` |
| `queue` | `stale_leases_recovered` | expired leases are requeued/failed | `recovered_count` |
| `scanner` | `recording_delayed` / `recording_skipped` | recording is first deferred/skipped | `skip_reason`, optional `recording_duration_ms` |
| `scanner` | `recording_retry_expired` / `recording_retry_dropped` | durable retry is removed | `skip_reason` |
| `scanner` | `retry_cache_overflow` | display cache evicts entries | `cache_type` |
| `transcript` | `call_transcripts_published` | aggregated revisions publish | `published_count`, `scanned_count`, `blocked_jobs` |
| `transcript` | `retention_cleanup_completed` | cleanup removes data | `calls_count`, `results_count`, `has_more` |
| `api` | `recording_access_failed` | assigned recording is unavailable or rejected | `job_id`, `worker_uid`, `error_code` |
| `system` | `scanner_started` / `scanner_stopped` | background scanner lifecycle | `pid` |
| `system` | `operation_failed` | trusted local operation raises | `operation`, stable `error_code` |
| `storage` | `database_operation_failed` | metadata/pragma/setup storage warning | `operation`, stable `error_code` |
| source-derived | `legacy_log_imported` | one migrated legacy line | basename-only `source` |

Heartbeat success, empty polling, progress updates, segments, page/list/log requests, and repetitive successful loop iterations remain silent.

## Verification

Tests cover JSONL schema, sanitizer cases, permissions, two-file rotation, concurrent writers, symlink refusal, tail reading, 1000-event limit, malformed input, `period=0`, safe migration and unknown files, REST compatibility, useful-event coverage, noise exclusion, worker error isolation, UI filters/states, and stale responses. Final checks include the full test runner, PHP syntax, package build and contents, `git diff --check`, direct syslog search, old-channel creation search, and the required security/contract/queue reviews.
