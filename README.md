# ModuleLocalSpeechToText

Local speech-to-text module for MikoPBX.

`ModuleLocalSpeechToText` keeps call recordings inside the PBX, creates a local transcription queue, and delegates recognition to an external Apple Silicon worker. The module provides the PBX-side REST API, queue scanner, worker key management, transcript storage, integration events, and the administrator UI.

The package version is defined in `module.json`.

## What It Does

- Scans completed CDR records and creates transcription jobs for readable recordings in the MikoPBX monitor directory.
- Serves recording files only to the worker currently assigned to the job.
- Lets administrators choose the Whisper model, language hint, queue limits, processing timeout, and custom vocabulary terms.
- Generates restricted Bearer API keys for external workers.
- Tracks worker status, IP address, runtime model, API key binding, and last activity.
- Stores transcript segments, detected language, duration, model used, and worker technical metrics.
- Applies a server-side transcript quality filter that trims or rejects obvious hallucination artifacts before saving results.
- Shows transcripts as a conversation view, including left/right speaker columns for stereo recordings.
- Exposes queue, delayed recording, skipped recording, worker, transcript, and log status in the MikoPBX admin UI.
- Publishes idempotent `transcript.completed` events for downstream modules such as `ModuleAISupervisor`.

## Architecture

The module has two moving parts:

1. PBX module: installed under `Extensions/ModuleLocalSpeechToText`.
2. External worker: a separate macOS Apple Silicon process that polls the PBX API, downloads assigned audio, runs local Whisper transcription, and submits the result.

The PBX module does not run Whisper itself. It stores jobs in SQLite and streams a recording only after the job has been claimed by an authenticated worker.

## PBX Queue Worker

`Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs` is registered through `LocalSpeechToTextConf::getModuleWorkers()`.

It periodically scans completed CDR rows with recordings and creates one `m_STTJob` per recording path. Duplicate jobs are prevented by the unique `file_path` index. If a completed CDR row appears before its recording file is readable, the row is kept in a delayed-recording buffer and retried. Oversized or permanently missing recordings are tracked as skipped records so administrators can see why no job was created.

Processing jobs are leased to a worker. If the worker stops refreshing the lease, stale jobs are returned to the pending queue after the configured processing timeout.

## Transcript Quality Filter

Submitted worker segments are normalized to integer millisecond timestamps, then passed through `TranscriptQualityFilter` before persistence. The filter removes empty text and common Whisper hallucination patterns such as:

- dense text that is impossible for the segment duration;
- repeated token, phrase, substring, or character floods;
- punctuation floods;
- replacement characters;
- common subtitle or movie-tail markers.

When possible, repeated prefixes or suffixes are trimmed instead of rejecting the whole segment. Filter statistics are merged into `technical_info.quality` and `technical_info.server_quality`, so the transcript card can show how many segments were submitted, trimmed, or rejected by the server.

## Admin UI

The main module page has six tabs:

- `Settings`: language hint, polling interval, maximum recognizable recording duration, CDR scan limit, processing timeout, custom vocabulary terms, and TXT term import.
- `Model marketplace`: built-in and custom Hugging Face WhisperKit/Core ML models, labels, runtime IDs, and selected model.
- `Queue`: pending, processing, done-today, error, delayed-recording, and skipped-recording counters.
- `Workers`: generated worker API keys and registered workers with IP address, status, bound key, model, and last seen time.
- `Transcripts`: recent transcript list with date filtering and links to the conversation view.
- `Logging`: aggregated recent module logs with quick time filters.

The conversation view includes source recording metadata, playback/download links when the recording is still available, transcript timeline, speaker columns, and technical information submitted by the worker. For playback display the module can resolve both raw monitor paths and matching `pretty-monitor` files after `realpath` validation.

### Access control through ModuleUsersUI

When `ModuleUsersUI` (System Access Management) is installed and enabled, Local Speech To Text exposes three independent permissions:

- view transcripts and call recordings;
- manage module settings and the model catalog;
- connect workers and view the queue and logs.

The transcript list, transcript detail, and recording stream all follow the access group's call-history restrictions. Each selected employee expands to all of their internal extensions and active external phones, and every CDR leg of the logical call is checked so transfers and calls answered on a mobile phone are covered. Restricted access fails closed when the rule cannot be resolved. Web-user permissions never expose the worker transport API.

When `ModuleUsersUI` is absent or disabled, the previous administrator-only behavior remains unchanged and no additional access section is required.

The maximum recognizable recording duration defaults to 60 minutes and accepts values from 1–1440 minutes. A recording exactly at the limit is allowed. A missing, invalid, or zero CDR duration is treated as unknown and does not block processing. To avoid probing every backfill file, the scanner invokes `ffprobe` only when the CDR duration exceeds the configured limit. The measured duration then decides whether the recording enters the queue or remains skipped. A failed probe is retained in retry state instead of losing the recording. The accepted duration is persisted with the job and exposed to the worker as `duration_hint` in seconds.

## Worker Setup Flow

1. Open the module page in MikoPBX.
2. Go to `Workers`.
3. Generate a worker API key.
4. Copy the generated worker API key.
5. Paste the PBX URL and API key into the macOS worker app, then register this Mac.

Generated API keys are stored as normal MikoPBX REST API keys with limited permissions for this module only. A worker is bound to the API key used during registration; later job actions must use the same key.

## Worker API

Base path:

```text
/pbxcore/api/v3/module-local-speech-to-text
```

Worker API v2 is a breaking change in module `1.45`. New worker requests use the numeric database `{id}` or `{job_id}` shown below; `worker_uid` remains the worker's stable identity and is sent in the appropriate JSON body. Processing settings are a caller-bound singleton: `GET /worker-processing-settings`, with no worker ID in the path.

| Previous v1 route | v2 route |
| --- | --- |
| `POST /workers:register` | `POST /workers` |
| `GET /workers:getProcessingSettings` | `GET /worker-processing-settings` |
| `GET /jobs:getNext` | `POST /job-leases` |
| `GET /jobs/{id}:download` | `GET /job-recordings/{job_id}` |
| `POST /jobs/{id}:submitResult` | `PUT /job-results/{job_id}` |
| `POST /jobs/{id}:submitError` | `PUT /job-failures/{job_id}` |
| `POST /jobs/{id}:heartbeat` | `PATCH /job-leases/{job_id}` |
| `POST /jobs/{id}:release` | `DELETE /job-leases/{job_id}` |

New workers first call `GET /worker-api-contract`, then send `X-MikoPBX-Worker-API-Version: 2` on every worker request other than that contract call. All JSON endpoints use the standard MikoPBX REST v3 `PBXApiResult` envelope and Bearer-token security rules.

The eight former worker routes are no longer registered. Requests to them are rejected by the routing or authorization layer and do not have a module-level compatibility response. A v2 worker talking to an older module receives `404` or `405` from `/worker-api-contract`, classifies it locally as `module_upgrade_required`, and must not fall back to v1 registration or polling.

Update in this order: update this module to `1.45`; the previous macOS worker becomes incompatible/offline; update `LocalSTTWorkerMac` to `1.7` build `34`; then retry contract negotiation and a job claim. Existing worker API keys are preserved and are not rotated. Windows and Python workers are outside this migration and are not release artifacts. The Core floor remains `2025.1.1`; no Core prerequisite is required. See [the migration guide](docs/worker-api-v2-migration.md) for data preservation and rollback limits.

`POST /job-leases` returns:

- `id`
- `call_id`
- `file_url`
- `language_hint`
- `model_id`
- `model_repo`
- `model_engine`
- `model_artifact_type`
- `whisperkit_model`
- `model_display_name`
- `model_source_url`
- `vocabulary_terms`

The external worker should use `whisperkit_model` or `model_id` as the WhisperKit model name and `model_repo` as the Core ML repository for lazy local model download, then include `vocabulary_terms` in its recognition context. Marketplace entries keep the real Hugging Face repository and model folder names in the job payload. Custom marketplace entries must point to WhisperKit/Core ML compatible Hugging Face repositories.

Recording download, lease heartbeat/release, and final result/failure submission validate that the job remains assigned to the requesting worker.

## Result Payload

`PUT /job-results/{job_id}` requires:

- `worker_uid`
- `lease_token`
- `transcript` with integer `start_ms` and `end_ms` segment timestamps

Optional fields:

- `language`
- `duration_ms`
- `model_used`
- `technical_info`

All worker-to-PBX timestamps use integer milliseconds. PBX converts them to seconds only for database duration fields and UI rendering.

`technical_info` is stored as JSON and rendered in the transcript card. It is intended for worker-side diagnostics such as input duration, VAD statistics, selected model, language votes, rejected segments, command timing, and real-time factor. Server-side quality filtering appends its own counters without discarding worker diagnostics.

## Integration API

The module exposes read-only completed transcript endpoints for other local modules:

- `GET /transcripts?limit=50&offset=0&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `GET /transcripts/{result_id}`
- `GET /transcripts/events?cursor=created_at:event_id&limit=100`

Transcript list responses return compact summaries only. Transcript detail responses return normalized raw segments with stable `segment_id` values in the form `stt:{result_id}:{index}`, plus readable coalesced `turns` and plain `text` for downstream modules that do not need low-level STT chunks.

Public segments and turns use integer `start_ms`/`end_ms` and one `speaker` field. Physical `channel` metadata and duplicate floating-point `start`/`end` seconds remain internal. Historical rows without a speaker label are projected with their stored channel label as `speaker`.

Event responses read idempotent `transcript.completed` records from `m_STTTranscriptEvent`, so consumers can store `next_cursor` and resume polling. Event rows preserve `result_id`, `job_id`, and `call_id` when available. Summary generation can fall back from job data to event and CDR lookup, which keeps integrations usable even when older rows have partial job metadata.

These endpoints use the same MikoPBX REST v3 `PBXApiResult` envelope and should be used instead of direct table reads by new modules.

## Data Tables

The module stores its state in module tables:

- `m_STTSetting`: module settings, selected model, vocabulary, queue cursors, and worker API key ids.
- `m_STTWorker`: registered worker identity, status, IP, model, API key binding, and heartbeat timestamp.
- `m_STTJob`: transcription queue records.
- `m_STTResult`: completed transcript JSON and optional technical report.
- `m_STTTranscriptEvent`: integration event outbox for completed transcripts.
- `m_STTRetryState`: durable delayed/skipped recording retry state.
- `m_STTCallTranscript*`: assembled call transcripts, parts, revisions, and integration events.
- `m_STTSchemaMigration`: applied module database migration ledger.

## Database Upgrades and Maintenance

Module updates briefly drain STT database writers before files or schema are changed. During the bounded drain grace period, `getNext` stops assigning new work while a worker may still download, heartbeat, submit, or release an already leased job. After the exclusive maintenance barrier is acquired, worker/admin API writes return HTTP 503 with `retry_after=5`, and the scanner pauses. A process killed while acquiring the drain is recovered after its advisory lock disappears and either its process is confirmed dead or the marker exceeds the conservative stale-age limit. Telephony and call recording continue normally; queued jobs, active lease fields, results, transcript event cursors, settings, and worker identities remain in the module database. No coordinated macOS worker update is required.

The module uses ordered, idempotent SQLite migrations. Large event and call-transcript backfills run in deterministic batches until no source rows remain, and corrective migration versions complete databases produced by an earlier bounded backfill. Before migration the module creates a verified SQLite backup snapshot; an error rolls back open module transactions, closes the adapter, restores the snapshot, and verifies database integrity. Reinstalling the same module version does not reapply completed migrations.

After the first successful bridge installation, subsequent updates use the module-owned SQLite backup path during uninstall/reinstall. The first update from an older package is the one limitation: Core removes the old package before the new lifecycle override can run, so the new setup can automatically restore the legacy database but cannot restore the removed old code without reinstalling that old package. The live database is kept in DELETE journal mode until the verified-backup capability has been installed.

WAL is enabled only after successful migration and integrity verification, and only on a supported local filesystem. NFS, SMB/CIFS, SSHFS, 9p, and unknown filesystem types remain in DELETE mode. Logical backups and release archives never include SQLite WAL/SHM/journal sidecars.

## Build and CI

Build an installable module package locally:

```bash
./scripts/package-module.sh /tmp/module-build
```

By default the package version is read from `module.json`. To stamp a CI-style version without changing the working tree, pass it through `VERSION`:

```bash
VERSION=1.999 ./scripts/package-module.sh /tmp/module-build
```

The package script writes `ModuleLocalSpeechToText.<version>.zip` and validates that development artifacts such as `.git`, `.gitlab`, `Tests`, and `scripts` are not included in the module archive. Live databases, WAL/SHM/journal files, lifecycle markers, setup snapshots, and safe-backup capability markers are also excluded. Packaging fails closed if an allowlisted source or staging tree contains a symbolic link, and the completed ZIP is checked for symlink entries before publication.

GitLab CI runs standalone `Tests/check-*.php` checks before packaging. On `develop` it runs test and package jobs only. Publishing to WebDAV, release registration, and release notes are limited to `master` through the shared `infrastructure/ci-templates` pipeline. Release note context is stored in `.gitlab/release-context.json`.

## Notes

- Recordings are served only from the MikoPBX monitor directory after `realpath` validation.
- CDR duration is checked before queue insertion; an over-limit value is verified from the already validated media path before the recording is skipped. The accepted duration is checked again before a worker downloads an assigned file, so a reduced setting also applies to existing jobs.
- Recording files have a fixed, non-configurable 500 MiB safety guard that is enforced both when creating jobs and when a worker downloads a job file.
- The module supports multiple worker API keys, but one API key cannot be bound to multiple worker UIDs.
- The macOS worker app is released separately from this PBX module.
