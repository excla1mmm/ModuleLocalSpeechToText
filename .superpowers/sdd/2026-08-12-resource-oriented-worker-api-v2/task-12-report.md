# Task 12 report — STT v2 suite alignment

## Root causes and fixes

- Six aggregate checks still encoded removed v1 Workers/Jobs action and schema shapes after the v2 resource split.
- The controller grew to 508 lines after compatibility rendering was added. `WorkerViewService` now owns the compatibility projection while preserving the exact `WorkerApiContract::API_VERSION` comparison used by the view.
- The real Jobs, recording, and result maintenance paths returned HTTP 503 without the retry metadata required by lifecycle consumers. Each now returns `retry_after: 5`; JobFailures delegates to the gated JobResults dispatch.
- Tombstone checks now permit only the Task 8 fail-closed revoked-key provenance prelude before direct HTTP 426 rejection. Version checks treat the normalized lowercase header lookup as semantically equivalent to the canonical header name and retain UID/lease and gate-first checks.
- REST/settings contracts now exercise `WorkerProcessingSettings` as the bearer-authenticated, caller-bound singleton and prove old Workers settings remains only a tombstone.

## Verification

- Focused checks: all six assigned checks passed.
- Mutation checks: `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php` and `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php` passed.
- Full suite: `php Tests/run-all.php` exited 0 with `All module contract tests passed`.

## Contract Fix 1 — JSON write identity

- RED: executable lease and result regressions removed synthetic ownership headers while retaining only the version header and JSON `worker_uid` / `lease_token`; production processors denied the requests because they incorrectly required duplicated UID headers.
- GREEN: JobLeases and JobResults now authorize their JSON body identity through the restricted API-key binding and preserve canonical route/body-ID and lease-token ownership preflight. Optional UID headers are ignored by these write resources, while JobRecordings remains the header-only body-less download path and settings remains caller-bound by its UID header.
- Verification: focused lease/artifact/recording/version-mutation/negotiation/OpenAPI/authorization checks and the full module suite passed.

## Queue Fix 1 — heartbeat CAS expiry

- RED: the SQLite lease test set a valid owned lease expired after conceptual preflight but before heartbeat CAS; the old UPDATE revived it.
- GREEN: `refreshProcessingLeaseForWorker()` now requires `lease_expires_at >= :now` in its authoritative UPDATE, matching the timeout-boundary policy. A zero-row CAS leaves the lease untouched, so the existing conflict path produces no liveness or telemetry side effect.
- Verification: lease SQLite/runtime, authoritative-side-effects, queue, claim, migration, and full suite passed.

## Quality Fix 1 — canonical transcript segment order

- RED: out-of-order timed segments were integer-normalized but persisted in worker arrival order.
- GREEN: timed segments are now stably ordered by `(start_ms, end_ms, original_index)` before filtering, persistence, events, and call-revision composition. Existing untimed compatibility is retained: untimed segments follow all timed segments while preserving their original relative order.
- Verification: normalizer regression covers integer conversion, stable equal timestamps, and untimed ordering; the production terminal submission fixture now supplies out-of-order input and verifies normalized stored/result/event/revision flow. Focused quality/terminal/assembler/revision/receipt/migration and full suite passed.

## Whole-module review closure

- Contract review found and closed JSON identity/header duplication (`e34cf00`).
- Queue review found and closed the heartbeat-expiry CAS race (`0ffd042`).
- STT quality review found and closed non-canonical segment ordering (`f8ad77c`).
- Security review: PASS, 0 Critical/Important.
- Final module code review: PASS, 0 Critical/Important.
- Parked Minors: unified authorization harness consolidation, native-speaker locale polish, live PBX visual/Range matrix, optional request-size and fd-based local-file hardening.
