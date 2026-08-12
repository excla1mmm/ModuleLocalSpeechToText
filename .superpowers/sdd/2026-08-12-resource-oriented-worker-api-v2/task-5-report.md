# Task 5 report — STT processing settings and job lease resources

## Outcome

Implemented caller-bound `GET /worker-processing-settings` and the numeric `POST/PATCH/DELETE /job-leases` lifecycle. Both processors require worker API v2 before context/model/lifecycle work, fail closed to the restricted live key binding, and acquire lifecycle leases only after identity/ownership preflight. Claim URLs are path-only `/job-recordings/{job_id}`.

## RED / GREEN evidence

- RED: `php Tests/check-worker-job-leases-v2.php` exited 255 with `settingsController is missing` before any production file was added.
- GREEN: `php Tests/check-worker-job-leases-v2.php` prints `STT worker processing settings and job leases v2 contract OK`.
- Adversarial mode: `WORKER_JOB_LEASES_V2_MUTATION=1 php Tests/check-worker-job-leases-v2.php` exits 255 with `Mutation mode: version/auth ordering or ownership weakened`.
- Existing SQLite/domain tests retain coverage of one-job atomic claim, empty queue, heartbeat lease refresh/idempotency, stale/wrong ownership denial, release to pending, and unchanged attempts.

## Contract details

- Settings ignores body `worker_uid`; required `X-MikoPBX-Worker-UID` must resolve to the live restricted key binding before lifecycle acquisition and before the atomic settings fetch/dirty-flag clear.
- Claim requires the stable body `worker_uid` to match both the UID header and restricted key binding.
- Heartbeat/release require numeric route identity plus the existing body `worker_uid`/`lease_token`; a separately supplied route ID cannot disagree with body ID.
- Claim uses `allowDuringDrain: false`; heartbeat and release use `true`.
- The legacy Worker settings action remains a terminal 426 tombstone. Legacy Job actions were not repurposed.

## Verification

Passing focused/related commands:

- `php Tests/check-worker-job-leases-v2.php`
- `php Tests/check-stt-job-lease-sqlite.php`
- `php Tests/check-queue-lifecycle-contract.php`
- `php Tests/check-worker-settings-next-job-contract.php`
- `php Tests/check-worker-crud-v2-contract.php`
- `php Tests/check-worker-api-v2-negotiation.php`
- `php Tests/check-worker-api-v2-migration-sqlite.php`
- `php Tests/check-worker-binding-integrity-sqlite.php`
- `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php`
- `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php`
- changed-file PHP lint and `git diff --check`

Expected progressive failures:

- Plain route aggregate exits 255 after including all four Task 5 tuples; its first remaining delta is the later Task 6/7 Job Recordings/Results/Failures and management Jobs resources while legacy Job actions are still reflected.
- Plain version aggregate exits 255 first because later resources have not yet introduced the remaining canonical wire-header symbols/resource processors. Its Task 5-owned runtime `file_url` assertion is now satisfied.

## Files and concerns

Created both resource directories and the focused test; moved the settings schema; updated claim URL/schema examples and the aggregate mutation fixture. No Core, AI, worker client, version metadata, or unrelated file was changed. `min_pbx_version` remains `2025.1.1`.

Commit subject: `feat: expose STT worker lease resources`. The exact SHA is recorded in the task handoff and ledger completion update by the coordinating agent after review.
