# Task 11 report — publish STT worker API v2 upgrade

## Scope

Published the STT module metadata version `1.45` while retaining `min_pbx_version` `2025.1.1`. Added the release-facing Worker API v2 migration material and executable manifest/legacy tombstone structure coverage. No Core, AI, macOS, Windows, or Python source changed; no package, publish, tag, or push was performed.

## RED / GREEN evidence

- RED: before changing metadata, `php Tests/check-module-structure-contract.php` failed exactly with `module.json version must be exactly 1.45` because the manifest contained `1.44`.
- RED baseline: `php Tests/check-worker-api-v2-route-contract.php` failed with `Legacy terminal return must be top-level and unconditional`. This is an existing production route-contract condition in the Workers processor; Task 11 did not edit controllers/processors.
- GREEN: the new `check-no-working-legacy-worker-routes.php` enumerates all eight legacy routes and confirms each has one direct `LegacyRouteRejector::worker()` dispatch, no deleted v1 action class remains, and the shared rejection is the body-only HTTP 426 `worker_upgrade_required` / `required_worker_api_version=2` contract. Final focused command results are recorded in the Task 11 ledger entry.

## Published compatibility guidance

README and `docs/worker-api-v2-migration.md` contain the exact eight-route v1-to-v2 table; numeric path-ID and stable `worker_uid` rules; caller-bound settings singleton; contract header; module-only 426 body with no `Upgrade` header; local 404/405 `module_upgrade_required` classification without fallback; data preservation and rollback limits; and the ordered `1.45` / old-worker-offline / `LocalSTTWorkerMac` 1.7 build 34 / renegotiate-and-claim update sequence.

Existing API keys are retained without rotation. Windows and Python workers are explicitly outside the migration and are not release artifacts. No removed STT administrative route was introduced or documented; any future specified removal must use the existing 410 `api_route_removed` plus `replacement_path` contract.
