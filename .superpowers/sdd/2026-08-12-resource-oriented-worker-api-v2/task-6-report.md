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

## Fix Round 2 — stable replay envelopes and executable processors

Re-review reported two Important gaps. RED was `first and receipt replay Core result envelopes must match`: receipt reconstruction omitted stable `processor`, `function`, `pagination`, and `reload` properties. The receipt now preserves every stable field exposed by Core `PBXApiResult::getResult()` while intentionally excluding only runtime `pid`. Both endpoint processors route initial mutation, fast replay, and in-transaction replay through one `decorate()` path; failures identify `JobFailures\\Processor::callBack`, results identify `JobResults\\Processor::callBack`, and both report function `update`.

Added an executable Core-shaped processor harness covering actual JobResults/JobFailures processor ordering, first and fast replay equivalence through `getResult()`, endpoint-specific processor identity, different-payload conflicts, single domain dispatch, and lifecycle balance. The real SQLite receipt harness also compares initial and replayed Core envelopes and retains canonicalization, rollback, idempotency, conflict, and secrecy coverage. Artifact mutation mode now removes the production replay-decoration path and is detected.

Live-only remainder: full recording byte delivery through Core controller/proxy, especially Range 206/416 and monitor symlink behavior, still requires the isolated PBX curl matrix because the host has no Phalcon/Core controller runtime. Existing module domain contract tests cover duration, timestamps, and technical info.

Follow-up subject: `test: verify STT artifact resource integration`.

## Fix Round 3 — durable races and recording path execution

Re-review retained two Important verification gaps. Added a committed file-backed WAL-compatible two-process harness using the production `JobSubmissionReceiptService`, `ModuleTransaction`, and receipt migration. A start-file barrier releases simultaneous identical and differing payloads. Repeated runs prove one domain mutation and one receipt; identical callers receive equivalent responses, while differing callers produce one decorated conflict without overwriting the winner. Child exit codes and stderr are asserted.

Added a module-side runtime security test loading the actual `LocalSpeechToTextMain` private recording containment, size, MIME, and `fileResponse` implementations with only Core/Phalcon plumbing stubbed. It executes a temporary monitor tree and proves inside-file success, traversal/outside regular-file/outside-symlink rejection, oversize rejection, and the exact `fpassthru` filename/delete/download/content-type envelope. Its mutation mode fails explicitly.

Live-only remainder remains Core proxy byte streaming and Range 206/416 curl verification. The local test executes the exact module `fpassthru` metadata handed to Core, but does not emulate Core controller streaming.

Follow-up subject: `test: exercise STT artifact races and paths`.

## Fix Round 4 — production terminal composition

Re-review retained the two composition-depth findings. Added `JobTerminalSubmissionService` as the production orchestration seam and adopted it from both `LocalSpeechToTextMain::submitResult()` and `submitError()`. The result path preserves normalization/result/event/job-CAS/revision ordering inside the outer receipt transaction; failure routes its authoritative job CAS through the same production service.

The file-backed synchronized race now invokes that production service inside the real receipt/ModuleTransaction path and maps its boundaries to the real module table names `m_STTJob`, `m_STTResult`, `m_STTTranscriptEvent`, `m_STTCallTranscriptRevision`, and `m_STTJobSubmissionReceipt`. It asserts one row at every terminal boundary for identical and differing races. A focused order test detects a skipped event boundary in mutation mode.

The recording runtime continues to execute actual `LocalSpeechToTextMain` containment/size/fpassthru code. Only Core proxy bytes and Range 206/416 remain live-only.

Follow-up subject: `test: compose STT artifact production paths`.

## Fix Round 5 — granular production result boundaries

The final re-review again required literal granular composition and a complete recording processor chain. `LocalSpeechToTextMain::submitResult()` now calls `JobTerminalSubmissionService::result()` with five distinct real production callables: normalization/preparation, `STTResult::saveForJob`, `STTTranscriptEvent::ensureCompletedForResult`, authoritative `markDoneForWorker` CAS, and `CallTranscriptRevisionService::recordCompletedPart`. They execute under the ambient receipt transaction in exact order, and CAS false throws the existing assigned-job conflict. The opaque `transactionalResult` entry point was removed. Failure continues through `failure()` with the real CAS callable and false is propagated.

The terminal focused test, receipt concurrency tests, artifact processor tests, transcript/time/technical tests, queue/lease/CRUD/migration regressions, and mutation modes are green. However, a literal actual `JobRecordings\\Processor` plus actual `downloadJobFile()` harness could not be completed within the final round without replacing production behavior under test; the prior actual recording-domain runtime remains. Core proxy Range bytes also remain live-only.

Follow-up subject: `test: bind STT artifact tests to production`.

## Task 6V — production-bound verification unblock

Added exactly two executable harnesses. `check-job-terminal-production-callsite.php` loads the actual `LocalSpeechToTextMain`, `JobTerminalSubmissionService`, `JobSubmissionReceiptService`, `ModuleTransaction`, timestamp normalizer, and quality filter. It calls the real `submitResult()`/`submitError()` methods under the real receipt transaction, asserts normalize/result/event/CAS/revision table effects and order, proves false CAS creates no receipt, and proves receipt-insert and commit failures roll back every terminal table. Its mutation evaluates a modified production `LocalSpeechToTextMain` fixture with the completion-event call bypassed; the ordinary order assertion fails.

`check-job-recording-production-composition.php` loads and invokes the actual `JobRecordings\\Processor::callBack()` through the actual `LocalSpeechToTextMain::downloadJobFile()`, with actual `WorkerApiContract`, `WorkerRequestContext`, `WorkerBindingDecision`, and `JobLeaseOwnership`. A temporary monitor tree proves valid `fpassthru` metadata, outside/traversal/symlink denial, size and duration limits, version/auth/binding/UID/token/route denials before lifecycle/liveness/file access, header precedence over body/query credentials, and balanced lifecycle release on success, error, and throw. Its mutation evaluates a modified production Processor fixture that sources the lease token from request data; the ordinary denial assertion fails. Core proxy byte streaming and Range 206/416 remain the only live-PBX checks.

RED: both new commands exited 255 with `Task 6V ... harness is not implemented`. GREEN: each command passed three consecutive executions. Both production-fixture mutation modes exited 255 at their ordinary assertions. All available Task 6/receipt/recording/Task 5/queue/Task 4 focused regressions passed except `check-worker-api-key-permissions.php`, which cannot load the absent relative Core `ActionType.php` on this host. The plain route aggregate remains staged on Task 7 management Jobs/legacy mappings, and the plain version aggregate retains the established missing literal symbol; both mutation modes pass. Core is unchanged and `min_pbx_version` remains `2025.1.1`.

Task 6V subject: `test: unblock STT artifact verification`.
