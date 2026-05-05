# ModuleLocalSpeechToText

Local speech-to-text module for MikoPBX.

The module stores transcription jobs in a local SQLite database, exposes REST endpoints for an external Apple Silicon worker, and provides an admin UI for settings, queue state, workers, transcripts, and conversation view.

The selected Whisper model is configured in the MikoPBX web UI. `jobs:getNext` returns the model preset and Hugging Face repo to the worker, so the worker downloads the model lazily only when that model is first used.

## PBX worker

`Modules\ModuleLocalSpeechToText\Lib\WorkerCreateTranscriptionJobs` is registered through `LocalSpeechToTextConf::getModuleWorkers()`.

It periodically scans completed CDR rows under the MikoPBX monitor directory and creates one `m_STTJob` per readable recording path. Duplicate jobs are prevented by the `file_path` lookup/index, records whose audio file appears with a delay are kept in a retry buffer, and stalled `processing` jobs are returned to the queue after the configured timeout.

## Admin UI

The main module page uses MikoPBX-style tabs:

- `Settings`: language, polling interval, max file size, CDR scan limit, processing timeout, Whisper model.
- `Queue`: pending, processing, done today, and error counters.
- `Workers`: registered workers with IP, model, status, and last seen time.
- `Transcripts`: last 100 results with a link to the conversation view.

Conversation view renders stereo-channel transcripts as a two-column dialogue: left channel is Speaker 1, right channel is Speaker 2.

## Worker API

Base path:

```text
/pbxcore/api/v3/module-local-speech-to-text
```

Endpoints:

- `POST /workers:register`
- `GET /jobs:getNext?worker_uid=...`
- `GET /jobs/{job_id}:download?worker_uid=...`
- `POST /jobs/{job_id}:submitResult`
- `POST /jobs/{job_id}:submitError`

All JSON endpoints use the standard MikoPBX REST v3 `PBXApiResult` envelope and are protected by Bearer token or localhost access rules.

`jobs:getNext` returns `model_id` and `model_repo`; the external worker uses `model_repo` for lazy local model download. Download, `submitResult`, and `submitError` validate that the job is currently assigned to the requesting worker.
