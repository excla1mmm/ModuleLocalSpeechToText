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
