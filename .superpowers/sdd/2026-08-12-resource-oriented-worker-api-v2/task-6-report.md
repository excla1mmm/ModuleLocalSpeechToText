# Task 6 report — STT recording, result, and failure resources

## Outcome

Added numeric `GET /job-recordings/{job_id}`, `PUT /job-results/{job_id}`, and `PUT /job-failures/{job_id}` resources. Every processor requires worker API v2 before request normalization, accepts only a live restricted key, requires an exclusive key/UID binding, performs canonical route/body checks, and performs read-only lease ownership preflight before lifecycle acquisition. Lifecycle leases are released in `finally`.

Recording identity is taken only from `X-MikoPBX-Worker-UID` and `X-MikoPBX-Job-Lease-Token`. Existing recording containment, duration, size, readability, Range, and envelope behavior remains in `LocalSpeechToTextMain::downloadJobFile()`. Worker liveness now occurs only after its domain ownership check. Result/failure retain body `worker_uid` and `lease_token`, with the caller UID header as a second identity gate.

The four executable legacy Job action classes were deleted. Jobs controller/processor tombstones remain Task 7 work.

## RED / GREEN evidence

- RED: `php Tests/check-worker-job-artifacts-v2.php` exited 255: `Task 6 resource is missing: Lib/RestAPI/JobRecordings/Controller.php`.
- GREEN: the same command prints `STT worker job artifacts v2 contract OK`.
- Passing related checks: recording duration, transcript time normalizer, technical info, SQLite job lease, queue lifecycle, Task 5 lease contract, route mutation mode, version mutation mode, changed-file PHP lint, and `git diff --check`.
- Optional brief names `check-recording-path-security.php` and `check-recording-size-limit-contract.php` are absent from this checkout and were not invented.

## Staged aggregate deltas and concerns

- Plain route aggregate reaches the expected Task 7 delta: management Jobs routes are absent and legacy Jobs mappings remain reflected.
- Plain version aggregate still reports the established aggregate symbol delta; mutation mode passes.
- Core was not changed or used as a dependency. `min_pbx_version` remains `2025.1.1`. No client, AI, macOS, Windows, or Python file changed.

Commit subject: `feat: split STT job artifact resources`.

## Fix Round 1 — authoritative terminal receipts

The four Important review findings were reproduced and addressed. RED was `Task 6 resource is missing: Lib/Services/JobSubmissionReceiptService.php`. Migration 14 now creates the idempotent `m_STTJobSubmissionReceipt` table with a unique job/hashed-token key. Receipts store only the operation, canonical semantic digest, worker/job IDs, response envelope, status, and timestamp; neither raw lease tokens nor full transcript/error payloads are persisted.

The processor performs live-key, exclusive binding, caller UID, and canonical route checks before receipt replay. A matching receipt returns its original response before active-lease preflight; another operation or digest returns `409 job_submission_conflict`. The authoritative in-transaction recheck serializes races, and result/failure mutation plus receipt insert commit or roll back together. Stable recursive payload canonicalization ignores transport/envelope metadata but includes semantic result/failure fields.

Recording now calls the existing `bestEffortWorkerTouch()` after ownership, so liveness failures cannot replace the file response. Runtime pragmas are configured after lifecycle acquisition. `markFailedForWorker()` hydrates its known post-CAS state directly without a fallible reload.

Fresh GREEN evidence: focused artifact and receipt SQLite tests; recording duration, transcript timestamp/technical-info, queue/lease runtime and concurrency, Worker CRUD/binding/API-v2 migration, and upgrade contract checks. Artifact mutation mode exits 255 after bypassing receipt preflight; route/version mutation modes pass. The host production-shaped upgrade rehearsal remains skipped because `ext-phalcon` is unavailable.

Follow-up subject: `fix: make STT artifact submissions idempotent`.
