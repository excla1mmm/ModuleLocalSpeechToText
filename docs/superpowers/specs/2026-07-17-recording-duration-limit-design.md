# Recording Duration Limit Design

## Goal

Replace the configurable recording file-size limit with a configurable maximum recognizable recording duration, while retaining a fixed 500 MiB safety limit for recording files throughout the transcription pipeline.

## Settings Contract

- Add `max_recording_duration_min` as the only operator-facing recording-limit setting.
- Default the setting to 60 minutes.
- Normalize submitted and stored values to the inclusive range 1–1440 minutes.
- A recording whose known duration equals the configured limit is allowed; only a greater duration is rejected.
- Stop rendering, reading, writing, and installing the legacy `max_file_size_mb` setting. An existing database row may remain, but runtime code must ignore it so an old value such as `500` is never interpreted as 500 minutes.
- Define the maximum recording file size in code as exactly `500 * 1024 * 1024` bytes. It is a non-configurable safety guard and remains enforced both before job creation and before recording download.

## Duration Source and Unknown Values

The scanner uses the completed CDR record's duration, expressed in seconds. Positive numeric values are normalized to whole seconds. Missing, invalid, negative, or zero values are treated as unknown.

Unknown duration does not reject a recording. Such a recording continues through the pipeline when its file is readable and no larger than 500 MiB. The module will not invoke an external media-probing process during CDR scans.

## Queue and Retry Flow

Realtime, backfill, delayed-recording retry, and skipped-recording retry paths apply the same duration rule before creating a job. A recording with a known duration over the configured limit is retained as skipped with reason `duration_too_long` and is not sent to a worker.

Skipped over-duration recordings remain eligible for the existing finite retry process. If the operator later increases the configured duration limit, a subsequent retry can create the transcription job. Existing history-window and retry-lifetime behavior remains unchanged.

The queue UI maps `duration_too_long` to a dedicated localized status instead of reporting it as an oversized file.

## Job Persistence and Worker API

Add a nullable `recording_duration_sec` integer column to `m_STTJob` through an idempotent installation migration. New jobs persist the known positive CDR duration; existing jobs and jobs with unknown duration store `NULL`.

The claimed-job payload exposes this value in the existing `duration_hint` property, in seconds. Unknown values remain `null`; the REST response schema continues to describe `duration_hint` as a nullable number.

Before serving an assigned recording, the download endpoint revalidates both guards:

1. a known persisted duration must not exceed the current `max_recording_duration_min` value;
2. the file must not exceed the fixed 500 MiB safety limit.

If a setting reduction makes an already-created job too long, the download endpoint marks that owned job as a terminal error and returns a stable `duration_too_long` error with HTTP 413. Existing worker ownership and lease-token checks run before this validation and remain unchanged. The existing `file_too_large` response remains reserved for the fixed file-size guard.

## Administration UI and Localization

Replace the numeric `max_file_size_mb` form element with `max_recording_duration_min`, using value 60, minimum 1, and maximum 1440. Replace the visible Russian label with `Максимальная длительность распознаваемой записи, мин.` and update the hint to explain that longer known-duration recordings are shown under skipped recordings. English and all other module message catalogs receive the corresponding new keys so their key sets stay synchronized; existing localization fallback conventions are preserved.

The settings controller loads and saves only the new duration setting. Settings-save logging includes the normalized duration limit so the active operational value is observable without exposing unrelated data.

## Installation and Compatibility

Fresh installations create `max_recording_duration_min=60` and do not create `max_file_size_mb`. Upgrades add the new default when absent and add the nullable job-duration column. They neither rename nor copy the old size value because the units and semantics are incompatible.

Old jobs remain downloadable when their duration is unknown, subject to the fixed 500 MiB guard. No worker update is required because `duration_hint` already exists in the job response contract and accepts `null`.

## Documentation

Update the Russian and English readmes to describe the configurable duration limit, the treatment of unknown CDR duration, the fixed 500 MiB safety guard, and enforcement at queue creation and download time. Remove statements that describe file size as configurable.

## Tests and Verification

Tests must establish the behavior before production changes:

- settings normalization defaults to 60 and clamps values to 1–1440 minutes;
- the fixed file-size helper always returns exactly 500 MiB and does not read the legacy setting;
- the admin form, controller, view, and setup use `max_recording_duration_min` and no longer use `max_file_size_mb`;
- duration boundary behavior allows an equal value, rejects a greater value, and allows unknown/zero duration;
- realtime and both retry paths use the shared duration decision and record `duration_too_long`;
- job creation persists known duration and the claimed-job payload exposes it as `duration_hint`;
- installation adds the nullable `recording_duration_sec` column idempotently;
- download revalidation marks over-duration assigned jobs terminal without weakening worker, job, or lease ownership checks;
- queue reason localization exists in every message catalog.

Run the complete module contract suite, PHP syntax checks for changed PHP files, translation key comparison, and the module package build/integrity check.

## Non-Goals

- No audio truncation, splitting, resampling, or external duration probing.
- No change to the worker's transcription algorithm or result `duration_ms` contract.
- No change to scan cursors, backfill retention, job lease duration, retry limits, or API-key security.
- No migration of the numeric value stored under `max_file_size_mb`.
- No deployment, version bump, push, or PBX restart unless separately requested.
