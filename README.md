# ModuleLocalSpeechToText

Local speech-to-text module for MikoPBX.

`ModuleLocalSpeechToText` keeps call recordings inside the PBX, creates a local transcription queue, and delegates recognition to an external Apple Silicon worker. The module provides the PBX-side REST API, queue scanner, worker key management, transcript storage, and the administrator UI.

Current module version: `1.0.46`.

## What It Does

- Scans completed CDR records and creates transcription jobs for readable recordings in the MikoPBX monitor directory.
- Serves recording files only to the worker currently assigned to the job.
- Lets administrators choose the Whisper model, language hint, queue limits, processing timeout, vocabulary dictionaries, and custom vocabulary terms.
- Generates restricted Bearer API keys for external workers.
- Tracks worker status, IP address, runtime model, API key binding, and last activity.
- Stores transcript segments, detected language, duration, model used, and optional worker technical metrics.
- Shows transcripts as a conversation view, including left/right speaker columns for stereo recordings.
- Exposes queue, delayed recording, skipped recording, worker, and transcript status in the MikoPBX admin UI.

## Architecture

The module has two moving parts:

1. PBX module: installed under `Extensions/ModuleLocalSpeechToText`.
2. External worker: a separate macOS Apple Silicon process that polls the PBX API, downloads assigned audio, runs local Whisper transcription, and submits the result.

The PBX module does not run Whisper itself. It stores jobs in SQLite and only streams a recording after the job has been claimed by an authenticated worker.

## PBX Queue Worker

`Modules\ModuleLocalSpeechToText\Lib\WorkerCreateTranscriptionJobs` is registered through `LocalSpeechToTextConf::getModuleWorkers()`.

It periodically scans completed CDR rows with recordings and creates one `m_STTJob` per recording path. Duplicate jobs are prevented by the unique `file_path` index. If a completed CDR row appears before its recording file is readable, the row is kept in a delayed-recording buffer and retried. Oversized or permanently missing recordings are tracked as skipped records so administrators can see why no job was created.

Processing jobs are leased to a worker. If the worker stops refreshing the lease, stale jobs are returned to the pending queue after the configured processing timeout.

## Admin UI

The main module page has four tabs:

- `Settings`: language hint, polling interval, maximum recording size, CDR scan limit, processing timeout, Whisper model, predefined vocabulary dictionaries, and custom vocabulary terms.
- `Queue`: pending, processing, done-today, error, delayed-recording, and skipped-recording counters.
- `Workers`: generated worker API keys and registered workers with IP address, status, bound key, model, and last seen time.
- `Transcripts`: recent transcript list with date filtering and links to the conversation view.
- `Logging`: aggregated recent module logs with quick time filters.

The conversation view includes the source recording metadata, playback/download links when the recording is still available, transcript timeline, speaker columns, and technical information submitted by the worker.

## Worker Setup Flow

1. Open the module page in MikoPBX.
2. Go to `Workers`.
3. Generate a worker API key.
4. Copy the generated worker install command.
5. Run it on an Apple Silicon Mac with the external worker package.

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
- `vocabulary_terms`

The external worker should use `model_id` as the WhisperKit model name and `model_repo` as the Core ML repository for lazy local model download, then include `vocabulary_terms` in its recognition context.

`download`, `heartbeat`, `submitResult`, and `submitError` validate that the job is still in `processing` state and assigned to the requesting worker.

## Result Payload

`submitResult` requires:

- `worker_uid`
- `transcript` with integer `start_ms` and `end_ms` segment timestamps

Optional fields:

- `language`
- `duration_ms`
- `model_used`
- `technical_info`

All worker-to-PBX timestamps use integer milliseconds. PBX converts them to seconds only for database duration fields and UI rendering.

`technical_info` is stored as JSON and rendered in the transcript card. It is intended for worker-side diagnostics such as input duration, VAD statistics, selected model, language votes, rejected segments, command timing, and real-time factor.

## Data Tables

The module stores its state in module tables:

- `m_STTSetting`: module settings, selected model, vocabulary, queue cursors, and worker API key ids.
- `m_STTWorker`: registered worker identity, status, IP, model, API key binding, and heartbeat timestamp.
- `m_STTJob`: transcription queue records.
- `m_STTResult`: completed transcript JSON and optional technical report.

## Notes

- Recordings are served only from the MikoPBX monitor directory after `realpath` validation.
- The configured maximum recording size is enforced both when creating jobs and when a worker downloads a job file.
- The module supports multiple worker API keys, but one API key cannot be bound to multiple worker UIDs.
- The external worker package is released separately from this PBX module.
