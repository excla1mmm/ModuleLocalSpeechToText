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

## Fix Round 1 — binding, ownership, compensation, and executable coverage

The queue-resilience review reported five Important findings. All were reproduced and addressed:

- A shared `WorkerBindingDecision` now requires exactly one row for the key/UID decision. Duplicate legacy bindings return 409 `worker_api_key_already_bound` before lifecycle acquisition or domain mutation for settings and every lease action.
- `JobLeaseOwnership` performs read-only job/worker/status/assignment/constant-time-token/freshness checks for PATCH and DELETE before lifecycle acquisition. Domain CAS checks remain authoritative against TOCTOU. Worker liveness is updated only after heartbeat/release CAS succeeds.
- Route/body mismatch uses Core's real `_idFromRequestBody` envelope field against the normalized route `data['id']` and returns 409.
- A post-claim exception conditionally releases only the exact claimed worker/token lease, leaves attempts unchanged, and never logs the token. An injected formatter failure proves the job returns to pending with ownership/start/lease state cleared.
- The focused suite now includes an executable processor harness with denial snapshots and lifecycle counters, the injected compensation harness, and a two-process WAL SQLite race proving exactly one contender claims a single pending row.

Fresh RED evidence began with `Shared exclusive worker binding decision is missing`. The first concurrency harness run then exposed test-fixture hydration rather than production behavior (`Cannot assign int to ... STTJob::$priority`); matching the existing string-backed model hydration resolved the harness and produced the intended two-process result.

Fix verification includes `check-worker-job-leases-v2.php`, `check-worker-job-leases-v2-runtime.php`, `check-worker-claim-compensation.php`, `check-worker-claim-concurrency-sqlite.php`, the existing lease/queue/settings/CRUD/binding/registration/delete-claim/migration suites, route/version mutation modes, PHP lint, and diff hygiene. Plain aggregates retain only the previously documented Task 6/7 deltas.

Follow-up subject: `fix: harden STT worker lease ownership`.

## Fix Round 2 — authoritative lease transitions

Three Important re-review findings and the deferred event-ordering Minor were addressed. Every fallible settings/model/language/vocabulary lookup now occurs in an immutable snapshot before claim CAS. The post-CAS formatter reads only claimed scalar fields and that snapshot; `job_claimed` follows payload construction and is best-effort. Exact-token compensation remains catastrophic defense-in-depth, with sanitized success/failure events and explicit false/throw handling.

Core `_idFromRequestBody` accepts only a positive JSON integer or canonical positive decimal string within `PHP_INT_MAX`, exactly equal to the normalized route ID. Booleans, floats, containers, null, zero/negative, leading zeros, whitespace/signs, suffix garbage, and overflow return 409 before lifecycle acquisition.

Heartbeat/release CAS is authoritative. Liveness and release telemetry run best-effort after CAS; injected failures still return 200 with refreshed/pending state and unchanged attempts. An injected domain exception proves lifecycle release occurs in `finally`.

RED began with `Canonical job ID parser is missing`. Updated executable tests cover the canonical-ID adversarial table, preparation failure before claim, successful pure payload, authoritative side-effect failures, and lifecycle-finally. The existing ambiguity, denial snapshots, compensation secrecy, and two-process claim coverage remains green.

## Fix Round 3 — close the model post-CAS gap

Two Important re-review findings were reproduced. RED was `Successful claim must return the selected hydrated job without fallible reload`. `STTJob::claimNextForWorker()` now populates and returns the already-selected model from the known CAS inputs once affected rows confirms success, eliminating the reload-null path.

If `affectedRows()` throws after UPDATE, the model performs an exact job/worker/token conditional UPDATE back to pending, clearing ownership/start/lease/expiry/finished/error without changing attempts. Successful compensation rethrows the original failure; false/throw compensation surfaces the distinct sanitized `STT claim outcome is unknown and compensation failed` exception for caller logging, with no token text.

The processor now uses `array_key_exists('_idFromRequestBody', $data)`. The executable Core-shaped table resets a fresh valid processing lease for every malformed value, verifies 409 `job_id_mismatch`, and proves zero lifecycle/domain dispatch. Positive integer and canonical decimal-string IDs both dispatch successfully. Normal and two-process claim tests remain green.
