# ModuleLocalSpeechToText

Local speech-to-text module for MikoPBX.

The module stores transcription jobs in a local SQLite database, exposes REST endpoints for an external Apple Silicon worker, and provides a minimal admin UI for queue and transcript visibility.

## PBX worker

`Modules\ModuleLocalSpeechToText\Lib\WorkerCreateTranscriptionJobs` is registered through `LocalSpeechToTextConf::getModuleWorkers()`.

It periodically scans completed CDR rows with a readable recording file under the MikoPBX monitor directory and creates one `m_STTJob` per recording path. Duplicate jobs are prevented by the `file_path` lookup/index.

## Worker API

Base path:

```text
/pbxcore/api/v3/module-local-speech-to-text
```

Endpoints:

- `POST /workers:register`
- `GET /jobs:getNext?worker_uid=...`
- `GET /jobs/{job_id}:download`
- `POST /jobs/{job_id}:submitResult`
- `POST /jobs/{job_id}:submitError`

All JSON endpoints use the standard MikoPBX REST v3 `PBXApiResult` envelope and are protected by Bearer token or localhost access rules.
