# Task 9 brief — split STT OpenAPI resource tags

Implement corrected plan Task 9 only. Keep Core read-only, preserve `min_pbx_version` at `2025.1.1`, and make no AI, worker-client, UI, or version changes.

- Start by replacing the shared-tag OpenAPI and translation contracts, then record their expected RED result.
- Map Workers, WorkerApiContract, and WorkerProcessingSettings to `Local STT Workers`; Jobs, JobLeases, JobResults, and JobFailures to `Local STT Jobs`; JobRecordings to `Local STT Recordings`; Transcripts and TranscriptEvents to `Local STT Transcripts`; and CallTranscripts and CallTranscriptEvents to `Local STT Call Transcripts`.
- Keep runtime tombstone processor routing unchanged, but make `register`, `getNext`, `download`, `submit`, `heartbeat`, and `release` absent from generated OpenAPI operations.
- Replace the retired tag key with the five required `rest_tag_LocalSTT*` keys in every catalog. English and Russian literals are prescribed; every other locale must contain a semantic localization rather than the English fallback.
- Run focused contracts, PHP syntax for changed catalogs, relevant route/version aggregate checks, `git diff --check`, and status. Commit only Task 9 files as `docs(api): split STT OpenAPI resource tags`.
