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
