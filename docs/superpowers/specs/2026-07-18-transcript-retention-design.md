# Transcript Retention Design

## Goal

Add an administrator-controlled retention period for transcriptions stored by ModuleLocalSpeechToText. Retention is measured from the time recognition completes, defaults to one year, and never deletes CDR rows or call recording files.

## Settings and compatibility

The module stores `transcript_retention_days` in `m_STTSetting`. Supported values are:

- `30`: 30 days
- `90`: 90 days
- `180`: 180 days
- `365`: 1 year and the default
- `0`: unlimited retention

The existing `cdr_history_days` processing-window presets become `1`, `7`, `30`, `90`, `180`, `365`, and `0` (all recordings). The former `730`-day preset is removed and legacy values above `365` are normalized to `365`.

A processing window is compatible when retention is unlimited, or when the processing window is finite and no longer than retention. Therefore `cdr_history_days=0` is valid only with `transcript_retention_days=0`.

The form validates compatibility before writing any setting. Invalid input produces the localized message: “The recording processing window cannot exceed transcript retention. Increase retention or reduce the processing window.” The browser provides the same immediate validation, but server validation remains authoritative and rejects the entire save request without partial setting updates.

On upgrade, the new retention setting defaults to `365`. A legacy processing window of `730` or `0` is normalized to `365` so the installed configuration starts in a valid state.

## User interface

A second discrete slider appears immediately below “Recording processing window”. Its choices are 30 days, 90 days, 180 days, 1 year, and Unlimited. The hidden form field is named `transcript_retention_days`.

The explanatory text states that retention starts when call recognition completes and that CDR records and audio files are not deleted. If the two sliders conflict, a visible negative message explains how to resolve it. The form save is rejected until the values are compatible; values are never silently changed in response to user interaction.

Both source and deployed JavaScript assets receive the same behavior. Russian remains the source translation, English receives a native translation, and all other module catalogs receive the English operational copy while preserving exact key parity.

## Retention service

`TranscriptRetentionService` owns policy evaluation and deletion. It receives the current timestamp for deterministic tests and returns a summary containing the number of calls and results deleted and whether another batch is due.

The expiration timestamp is `m_STTResult.created_at`, which is written when the PBX accepts the completed recognition result. A call is eligible only when the newest result associated with its non-empty `call_id` is older than the cutoff. This keeps multi-recording calls atomic: all transcript artifacts for one call are retained or removed together. Results with an empty call identifier are treated as independent candidates.

For each bounded batch, deletion occurs inside `ModuleTransaction` in dependency order:

1. `m_STTCallTranscriptEvent`
2. `m_STTCallTranscriptRevision`
3. `m_STTCallTranscriptPart`
4. `m_STTCallTranscript`
5. `m_STTTranscriptEvent`
6. `m_STTResult`

`m_STTJob` rows remain as deduplication tombstones, preventing the scanner from recreating jobs for recordings whose transcript payload was purged. CDR data and recording files are outside the module database and are never changed.

Unlimited retention returns without deleting data. SQL candidate limits are bounded and identifiers are passed through bound parameters; deletion does not interpolate user input.

## Scheduling and failure handling

The existing `WorkerLocalSpeechToTextJobs` process invokes retention maintenance while holding its normal module lifecycle write lease. No new worker or cron process is introduced.

`transcript_retention_last_cleanup_at` records the last completed sweep. A changed retention value resets it to `0`, making cleanup due on the next worker iteration. The worker deletes one bounded batch per iteration. While a full batch is returned, the last-cleanup marker remains due so the next iteration continues draining the backlog. When the sweep reaches fewer than one batch, the marker advances and the next routine sweep is due after one hour.

Exceptions roll back the current batch, leave cleanup due, and are caught by the worker’s existing error/recovery path. Logs contain counts and cutoff metadata but never transcript text.

## Testing

Contract and SQLite integration checks cover:

- setting defaults, allowed presets, legacy normalization, and compatibility rules;
- server validation occurring before any settings write;
- both sliders, removal of the two-year choice, immediate browser validation, and bundled-source parity;
- localized keys and identical key sets across all catalogs;
- finite retention deleting expired raw and aggregate artifacts in dependency order;
- call-level atomicity based on the newest processed result;
- unlimited retention as a no-op;
- preservation of `m_STTJob`, CDR boundaries, and recording paths;
- bounded batch continuation, cleanup scheduling, and rollback on failure;
- the full module contract suite and package build.
