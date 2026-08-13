# Task 11 brief — publish STT worker API v2 upgrade

Implement STT Task 11 only. Own `module.json`, `README.md`, `docs/worker-api-v2-migration.md`, the structure/legacy-route checks, test registration, and Task 11 SDD records. Preserve all other work; Core is strictly read-only.

## Binding contract

- Publish exact module version `1.45`; retain exact `min_pbx_version` `2025.1.1` and no Core prerequisite.
- Add a focused executable check that enumerates all eight v1 STT worker routes and proves they map only to `LegacyRouteRejector::worker()` with no retained v1 action class.
- Document the exact old-to-new route mapping, numeric IDs, caller-bound settings singleton, version negotiation/header, module-only 426 body without `Upgrade`, and old-module 404/405 local `module_upgrade_required` behavior with no fallback.
- Document the preservation/migration/rollback limits, release order (module, incompatible/offline old macOS worker, `LocalSTTWorkerMac` 1.7 build 34, negotiation/claim), no key rotation, and Windows/Python exclusion.
- Do not invent removed administrative routes. If a separately specified removed action is documented later, its module response must be 410 with `replacement_path`.

## TDD and verification

First add exact manifest and legacy-tombstone checks and record RED on the current `1.44` manifest. Then make the minimal metadata/documentation changes. Run focused structure, legacy, and route checks; record an unchanged pre-existing route-contract failure if it remains. Run relevant version/mutation checks, JSON parsing, PHP lint, changed-doc stale-version/Core-prerequisite scans, diff/status, and commit only Task 11 files as `docs: publish STT worker API v2 upgrade`.
