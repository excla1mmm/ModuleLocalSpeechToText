# ModuleLocalSpeechToText

Local speech-to-text module for MikoPBX.

The module stores transcription jobs in a local SQLite database, exposes REST endpoints for an external Apple Silicon worker, and provides a minimal admin UI for queue and transcript visibility.

## PBX worker

`Modules\ModuleLocalSpeechToText\Lib\WorkerCreateTranscriptionJobs` is registered through `LocalSpeechToTextConf::getModuleWorkers()`.

It periodically scans completed CDR rows under the MikoPBX monitor directory and creates one `m_STTJob` per readable recording path. Duplicate jobs are prevented by the `file_path` lookup/index, and records whose audio file appears with a delay are kept in a retry buffer.

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
