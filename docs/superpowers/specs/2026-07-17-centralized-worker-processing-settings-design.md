# Centralized Worker Processing Settings Design

## Goal

Make the MikoPBX Local Speech to Text module the single source of truth for the worker's expert audio-preparation and WhisperKit decoding settings. One global processing profile applies to every registered worker so administrators do not have to configure multiple macOS workers independently.

The module exposes the profile to authenticated workers, signals that a worker should refresh it through the existing next-job poll, and periodically serves it when workers perform a defensive three-hour refresh. The worker removes the corresponding local controls and applies only complete, validated remote profiles.

Language selection is outside this profile. A language hint supplied with a MikoPBX job continues to take precedence; when no hint is present, the worker always uses WhisperKit automatic language detection.

## Scope

The global profile contains exactly these settings:

| API field | Default | Valid range or type | Worker meaning |
| --- | ---: | --- | --- |
| `temperature` | `0.0` | finite number, `0...1` | Initial decoding temperature |
| `temperature_fallback_step` | `0.2` | finite number, `0...1` | Temperature increment between fallback attempts |
| `temperature_fallback_count` | `5` | integer, `0...10` | Number of fallback temperatures |
| `top_k` | `5` | integer, `1...100` | WhisperKit top-K sampling limit |
| `compression_ratio_threshold` | `2.4` | finite number, `0...10` | Repetition/compression fallback threshold |
| `log_probability_threshold` | `-1.0` | finite number, `-10...0` | Average log-probability threshold |
| `first_token_log_probability_threshold` | `-1.5` | finite number, `-10...0` | First-token log-probability threshold |
| `no_speech_threshold` | `0.6` | finite number, `0...1` | No-speech decision threshold |
| `audio_normalization` | `true` | boolean | Existing high-pass, low-pass, and dynamic normalization pipeline |
| `voice_activity_detection` | `true` | boolean | Existing RMS VAD segmentation |
| `max_segment_duration_ms` | `15000` | integer, `1000...120000` | Maximum VAD segment duration |
| `segment_overlap_ms` | `300` | integer, `0...10000`, less than maximum duration | VAD segment overlap |

Durations use integer milliseconds in the API. The module UI may display seconds, but it converts them to canonical integer milliseconds before validation and storage.

## Module UI and Persistence

Populate the existing collapsed `Advanced settings` accordion with the twelve controls. The section remains collapsed by default, is clearly described as expert-only, and explains that changes affect all registered workers. VAD-dependent duration controls may be visually disabled while VAD is off, but their stored values are preserved.

Store the canonical values under stable `STTSetting` keys matching the API field names. A dedicated processing-settings service owns defaults, normalization, validation, serialization, comparison, and save behavior so the web form and worker endpoint cannot diverge.

Add a non-null boolean `has_settings_changed` field to each `STTWorker`. Existing rows and newly created worker rows start with the flag set to `true`; re-registering an existing worker does not needlessly overwrite its current flag.

Saving the form follows this sequence:

1. Parse and normalize all twelve submitted values.
2. Reject the save if any value or cross-field constraint is invalid.
3. Compare the complete canonical profile with the currently stored profile.
4. If it is unchanged, perform no processing-profile writes and do not alter worker flags.
5. If it changed, save the complete profile and set `has_settings_changed=true` for every registered worker in one serialized database transaction.

This design intentionally has no settings revision or generation number, in either persistence or the public API.

## Next-Job Signal

The worker continues to call the existing endpoint without supplying a settings version:

```http
GET /pbxcore/api/v3/module-local-speech-to-text/jobs:getNext?worker_uid=<uid>
```

The normal REST v3 envelope keeps `job` in its current position and adds a boolean next to it:

```json
{
  "result": true,
  "data": {
    "job": null,
    "has_settings_changed": true
  }
}
```

The module returns the flag on every successful next-job response, including an empty queue response. Calling `getNext` never clears it. Older workers remain compatible because they ignore the additional response field.

## Full-Profile Endpoint

Add an authenticated worker action:

```http
GET /pbxcore/api/v3/module-local-speech-to-text/workers:getProcessingSettings?worker_uid=<uid>
```

It uses the same worker API-key binding checks as the jobs API. A worker token may request only the profile associated with its own registered `worker_uid`; an unknown worker, unbound API key, or mismatched identity is rejected with the established REST v3 error envelope.

The success response is:

```json
{
  "result": true,
  "data": {
    "schema_version": 1,
    "processing_settings": {
      "temperature": 0.0,
      "temperature_fallback_step": 0.2,
      "temperature_fallback_count": 5,
      "top_k": 5,
      "compression_ratio_threshold": 2.4,
      "log_probability_threshold": -1.0,
      "first_token_log_probability_threshold": -1.5,
      "no_speech_threshold": 0.6,
      "audio_normalization": true,
      "voice_activity_detection": true,
      "max_segment_duration_ms": 15000,
      "segment_overlap_ms": 300
    }
  }
}
```

Profile retrieval and flag clearing are serialized with profile saves. The endpoint begins a database transaction and acquires the SQLite write lock before clearing the requesting worker's flag and reading the complete canonical profile. Therefore either a fetch completes before a concurrent save, after which the save marks the worker dirty again, or the save completes first and the fetch returns the new profile before clearing the flag. A failed transaction leaves the flag unchanged. No custom module-lifecycle guard is introduced.

The server cannot know whether an HTTP response was delivered and applied after committing the transaction. Startup refreshes, local retry state, and the mandatory three-hour refresh repair that inherent limitation without adding an acknowledgement protocol.

## Worker Profile Lifecycle

Introduce a Codable remote processing-profile model that is separate from connection settings and from the existing editable `SettingsStore`. It includes `schema_version` and all twelve values. Validation rejects unknown schema versions, missing fields, wrong JSON types, non-finite numbers, out-of-range values, and invalid segment overlap. A profile is never partially applied.

The worker maintains one immutable effective processing snapshot and captures that snapshot when starting each job. A refresh that completes while a job is running affects only the next job; it never mutates an in-progress transcription.

Refresh triggers are:

1. Immediately after successful worker registration, before normal job polling begins.
2. Immediately after a next-job response reports `has_settings_changed=true`.
3. Every three hours after the last successful refresh, regardless of the flag.
4. A bounded retry loop after a request, decode, or validation failure until a valid profile is obtained.

Only one profile request may be in flight. Concurrent triggers coalesce into that request, and a trigger that occurs during the request schedules another refresh only when necessary. The three-hour interval uses a monotonic runtime clock and resets after every successful validated response.

On success, the worker validates the whole payload, writes it to a bundle-scoped cache using a replacement-safe operation, and atomically publishes the new effective snapshot. The cache has distinct keys or storage from the obsolete local controls. Existing locally edited values are not migrated into it and are ignored after this feature is installed.

The twelve local controls and their editable `SettingsStore` plumbing are removed. Built-in constants remain as bootstrap and legacy-module defaults, not user-configurable settings. Model management, PBX connection settings, worker identity, logging, and other unrelated local controls remain unchanged.

## Failure Semantics

If a refresh fails and a previously validated remote profile or cached profile exists, the worker keeps processing with that complete profile, records a warning in diagnostics, and retries later.

If no validated server profile has ever been available in this installation:

- A missing endpoint (`404` or the module's established unsupported-action response) identifies a legacy module. The worker enters compatibility mode and uses built-in defaults. A previously cached remote profile takes precedence over built-in defaults during module rollback.
- If the new endpoint exists but is temporarily unavailable, malformed, or fails validation, the worker does not start a newly claimed job until it has a valid profile.

Startup refresh occurs before normal polling, minimizing the chance of claiming a job without settings. If `getNext` returns both a job and `has_settings_changed=true`, and the required refresh cannot produce a valid profile or cache, the worker safely releases that job using the existing lease token and enters settings recovery. It does not retain the lease until timeout and does not submit a transcription error for a configuration-delivery failure.

When a cached profile exists, a failed refresh does not block the returned job; that job uses the cached immutable snapshot. Any MikoPBX language hint in the job is still applied independently, with `Auto` used when the hint is absent.

## Compatibility and Rollout

The components can be deployed in either order:

- A new module adds a response field that old workers ignore. Its processing-profile flags may remain set for old workers without affecting queue behavior.
- A new worker treats the absent endpoint and absent `has_settings_changed` field as a legacy module and uses its cache or built-in defaults.
- After a module rollback, a new worker retains and uses its last valid cached profile while operating in legacy mode.

The preferred rollout is module first, then workers. The module initially exposes defaults identical to the current worker defaults, so workers that did not previously customize these expert settings retain their behavior. Previously customized local values intentionally stop taking effect once the new worker successfully loads the module profile.

## Security and Existing Contracts

- Preserve the existing REST v3 response and error envelope.
- Reuse worker API-key authorization and `worker_uid` binding checks.
- Do not log API tokens or include them in the profile cache.
- Preserve job IDs, worker IDs, lease tokens, heartbeats, reconnect behavior, safe recording downloads, and transcript timestamps in integer milliseconds.
- Do not mix AI Supervisor or local-LLM functionality into the worker.

## Verification

Module coverage must verify:

- defaults, canonical serialization, every range, and cross-field validation;
- atomic all-or-nothing saving and no dirty flags for an unchanged profile;
- dirtying every existing worker after a real profile change;
- a new worker's initial dirty state and preservation on re-registration;
- `has_settings_changed` in next-job responses with and without a job;
- profile retrieval and flag clearing for only the authenticated worker;
- authorization failures for unknown, unbound, or mismatched workers;
- serialized fetch/save ordering without lost changes;
- REST, OpenAPI, API-key permission, form, accordion, and translation contracts;
- compatibility of existing job clients with the additive response field.

Worker coverage must verify:

- payload decoding, schema checks, every range, and overlap validation;
- atomic profile publication and cache restoration after restart;
- startup, server-flag, three-hour, and retry refresh triggers;
- single-flight request coalescing;
- last-known-good behavior after network, decoding, or validation failures;
- no-cache blocking and safe lease release;
- immutable per-job snapshots across a concurrent refresh;
- legacy module and module-rollback behavior;
- removal of the twelve local controls and non-use of old UserDefaults values;
- unchanged language-hint behavior with automatic fallback.

After repository-level PHP, REST-contract, Swift, and UI tests pass, verify the worker registration, empty and non-empty polls, profile fetch, authorization, dirty-flag clearing, and refresh-after-save behavior against a Docker/PBX instance.

## Non-Goals

- Per-worker profiles, worker groups, or local overrides.
- A settings revision, generation counter, ETag, acknowledgement request, or push channel.
- Live mutation of an in-progress job's processing settings.
- Centralizing model files, PBX credentials, paths, logging, diagnostics, or other worker preferences.
- Migrating previously customized local processing values into the module.
