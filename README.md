# ModuleLocalSpeechToText

Local speech-to-text module for MikoPBX.

`ModuleLocalSpeechToText` keeps call recordings inside the PBX, creates a local transcription queue, and delegates recognition to an external Apple Silicon worker. The module provides the PBX-side REST API, queue scanner, worker key management, transcript storage, integration events, and the administrator UI.

Current module version: `1.33`.

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

The maximum recognizable recording duration defaults to 60 minutes and accepts values from 1–1440 minutes. A recording exactly at the limit is allowed. A missing, invalid, or zero CDR duration is treated as unknown and does not block processing. Longer known recordings are retained under skipped recordings and can enter the queue after the operator increases the limit. Known duration is persisted with the job and exposed to the worker as `duration_hint` in seconds.

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

Endpoints:

- `POST /workers:register`
- `GET /jobs:getNext?worker_uid=...`
- `GET /jobs/{job_id}:download?worker_uid=...`
- `POST /jobs/{job_id}:heartbeat`
- `POST /jobs/{job_id}:submitResult`
- `POST /jobs/{job_id}:submitError`

All JSON endpoints use the standard MikoPBX REST v3 `PBXApiResult` envelope and are protected by localhost or Bearer-token security rules.

`jobs:getNext` returns:

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

`download`, `heartbeat`, `submitResult`, and `submitError` validate that the job is still in `processing` state and assigned to the requesting worker.

## Result Payload

`submitResult` requires:

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

Event responses read idempotent `transcript.completed` records from `m_STTTranscriptEvent`, so consumers can store `next_cursor` and resume polling. Event rows preserve `result_id`, `job_id`, and `call_id` when available. Summary generation can fall back from job data to event and CDR lookup, which keeps integrations usable even when older rows have partial job metadata.

These endpoints use the same MikoPBX REST v3 `PBXApiResult` envelope and should be used instead of direct table reads by new modules.

## Data Tables

The module stores its state in module tables:

- `m_STTSetting`: module settings, selected model, vocabulary, queue cursors, and worker API key ids.
- `m_STTWorker`: registered worker identity, status, IP, model, API key binding, and heartbeat timestamp.
- `m_STTJob`: transcription queue records.
- `m_STTResult`: completed transcript JSON and optional technical report.
- `m_STTTranscriptEvent`: integration event outbox for completed transcripts.

## Build and CI

Build an installable module package locally:

```bash
./scripts/package-module.sh /tmp/module-build
```

By default the package version is read from `module.json`. To stamp a CI-style version without changing the working tree, pass it through `VERSION`:

```bash
VERSION=1.999 ./scripts/package-module.sh /tmp/module-build
```

The package script writes `ModuleLocalSpeechToText.<version>.zip` and validates that development artifacts such as `.git`, `.gitlab`, `Tests`, and `scripts` are not included in the module archive.

GitLab CI runs standalone `Tests/check-*.php` checks before packaging. On `develop` it runs test and package jobs only. Publishing to WebDAV, release registration, and release notes are limited to `master` through the shared `infrastructure/ci-templates` pipeline. Release note context is stored in `.gitlab/release-context.json`.

## Notes

- Recordings are served only from the MikoPBX monitor directory after `realpath` validation.
- Known recording duration is checked before queue insertion and again before a worker downloads an assigned file, so a reduced setting also applies to existing jobs.
- Recording files have a fixed, non-configurable 500 MiB safety guard that is enforced both when creating jobs and when a worker downloads a job file.
- The module supports multiple worker API keys, but one API key cannot be bound to multiple worker UIDs.
- The macOS worker app is released separately from this PBX module.
