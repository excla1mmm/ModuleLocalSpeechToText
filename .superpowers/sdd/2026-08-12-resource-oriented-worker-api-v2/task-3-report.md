# Task 3 Report: Transactional Worker API v1-to-v2 Migration

## Status

Implemented schema migration 11 (`stt-schema-011-worker-api-v2`) through the existing `ModuleSchemaMigrator`. The migration adds the three worker contract columns, marks only v1 workers offline/incompatible, requeues only v1/unowned processing leases without incrementing attempts, writes the marker as the last module-owned schema/data migration operation, and returns immediately after the marker is present. Existing setup permission reconciliation remains a separate post-transaction operation through the existing Core models/API interfaces.

Scoped commit: `663f598 feat: migrate STT workers to API v2`.

No Core files or Core database schema were changed.

## Fix Round 1: Marker and Ledger Ordering

The reviewer finding identified a wording conflict, not a production transaction defect. The supported framework sequence is:

1. `WorkerApiV2Migration` performs all worker/job schema and data changes.
2. It writes `worker_api_contract_migration_v2=1` as its final module-owned migration statement and returns.
3. `ModuleSchemaMigrator` writes version 11 to `m_STTSchemaMigration` as framework bookkeeping in the same transaction.
4. `ModuleTransaction` commits. Until that commit succeeds, the marker is not externally visible.

If the marker write, framework ledger insert, or commit fails, `ModuleTransaction::recover()` rolls back the marker, worker/job schema/data changes, and ledger row together. The canonical design and STT component plan now state this boundary explicitly. The forced pre-marker failure requirement and its rollback test remain unchanged.

No production or test file changed in Fix Round 1. The three reviewer test-strengthening Minors remain deferred to their existing ledger entries.

Fix Round 1 validation:

- Targeted `rg` scans found the new module-owned/ledger/external-visibility/rollback wording in both canonical documents.
- The obsolete `immediately before commit` / post-transaction marker wording has no match in the owned STT plan/spec section.
- The plan still contains `Inject an exception immediately before marker creation` unchanged.
- `git diff --check` passed, and the tracked source diff before staging named only the canonical spec and STT component plan.

## TDD Evidence

### RED

Command:

```text
php Tests/check-worker-api-v2-migration-sqlite.php
```

The first invocation exposed a test-bootstrap error because the standalone test loaded `STTSetting` without the Core `ModulesModelsBase`. Root cause: isolated harness dependency, not product behavior. After adding only the standard test stub for that base class, the required RED was:

```text
Fatal error: Uncaught RuntimeException: WorkerApiV2Migration implementation is missing
EXIT_CODE=255
```

No production migration code existed at that point.

### GREEN

The first GREEN attempt reached the migrated row but failed a test assertion because PHP's `??` operator treats an intentional SQL `NULL` as absent. Root cause: the assertion could not distinguish a present-null column from a missing key. The test was corrected to use `array_key_exists`; production code was unchanged for that iteration.

Fresh focused output at the final gate:

```json
{
    "migration": 11,
    "rollback_verified": true,
    "idempotency_hash": "348e8a761bedb6f01cfa87ca062db8ebf4a269b940e6a94a89bc6aad674400ff",
    "preservation_hash": "3ce0bbd910d17ee6e26c3069cf157fa7c1173df67fc86e8665cb77f43cc58a88",
    "legacy_api_key_binding": 41,
    "v2_lease_preserved": true
}
```

The post-commit rerun also passed with idempotency hash `02e3a094d7259a4328cbf159cc4226964c2d659bb2ff902a763e11274e1e0e49`; the per-run hash differs because the successful isolated migration intentionally populates current timestamps.

## Rollback, Ordering, and Idempotency Evidence

- The SQLite adapter injects `injected pre-marker failure` before executing the marker upsert.
- The adapter verifies both worker and job updates were attempted before the marker, proving the marker is the final module-owned schema/data migration operation.
- `ModuleSchemaMigrator` rolls back the same explicit transaction. The test confirms all three added columns disappear, worker status/key binding is restored, lease ownership/token/expiry is restored, preserved data hash is unchanged, and migration-ledger version 11 is absent.
- After a successful migration, the test hashes every `m_STT%` table, runs `ModuleSchemaMigrator` again, and requires an empty applied-version list plus the identical all-table hash.
- It then calls `WorkerApiV2Migration::migrate()` directly with marker `1` and requires the same all-table hash, proving the data migration itself is marker-idempotent.
- A partially upgraded fixture with an `api_version=2` online worker and active lease is migrated. Both worker and lease rows remain byte-for-byte identical.

## Preserved Fields and Data

- Legacy worker: numeric ID, `worker_uid`, name, model/IP/history fields, and `api_key_id=41` are preserved. Only `api_version`, status, settings-change flag, and zero `updated_at` receive planned values.
- Legacy processing job: `attempts=1` is unchanged; only status, owner/start/lease fields, timestamp, and migration diagnostic change.
- Terminal job 11 remains byte-for-byte identical.
- Already-v2 worker and active processing lease remain byte-for-byte identical.
- Results, transcript events, call transcript aggregates/parts/revisions/events, and scan/backfill cursor settings are covered by preservation hash `3ce0bbd910d17ee6e26c3069cf157fa7c1173df67fc86e8665cb77f43cc58a88`.
- Permission reconciliation remains after module migration commit and is idempotent. The existing reconciliation contract passed. Its production field whitelist changes only `full_permissions` and `allowed_paths`; API-key IDs, worker bindings, hashes, suffixes, displays, and key material are not assigned or regenerated.

## Verification Commands and Results

Final combined verification exited `0`:

```text
php Tests/check-worker-api-v2-migration-sqlite.php
php Tests/Integration/check-module-upgrade-sqlite.php
php Tests/check-module-setup-rollback-contract.php
php -l Models/STTWorker.php
php -l Models/STTSetting.php
php -l Lib/Services/WorkerApiV2Migration.php
php -l Setup/PbxExtensionSetup.php
php -l Tests/check-worker-api-v2-migration-sqlite.php
php -l Tests/Integration/check-module-upgrade-sqlite.php
php -l Tests/run-all.php
php Tests/check-module-schema-migrator.php
php Tests/check-module-transaction-adoption.php
php Tests/check-worker-api-key-reconciliation.php
php Tests/check-worker-processing-settings-flag-contract.php
php Tests/check-call-transcript-model-annotations-contract.php
git diff --check
```

Observed results:

```text
Worker API v2 SQLite migration: PASS (JSON evidence above)
Production-shaped upgrade rehearsal: SKIP, ext-phalcon unavailable
Module setup rollback contract OK
All seven edited PHP files: no syntax errors
Module schema migrator behavior OK
Module transaction adoption OK
Worker API-key reconciliation behavior OK
Worker processing settings flag contract OK
Call transcript model annotations contract OK
git diff --check: clean
```

The broader progressive `check-worker-api-v2-version-contract.php` still fails on the credential-bearing legacy `file_url`. This is the explicit next unmet later-task contract described by the plan; Task 3 does not own `LocalSpeechToTextMain` or resource route conversion, so no assertion was weakened and no out-of-scope runtime code was changed.

## Files

- `Models/STTWorker.php`
- `Models/STTSetting.php`
- `Lib/Services/WorkerApiV2Migration.php`
- `Setup/PbxExtensionSetup.php`
- `Tests/fixtures/schema/legacy-current.sql`
- `Tests/check-worker-api-v2-migration-sqlite.php`
- `Tests/Integration/check-module-upgrade-sqlite.php`
- `Tests/run-all.php`
- `.superpowers/sdd/2026-08-12-resource-oriented-worker-api-v2/task-3-report.md`

## Self-Review and Concerns

- Confirmed migration 11 uses the planned version/name/checksum and is ordered after migration 10.
- Confirmed the marker check occurs before schema/data mutation and the marker upsert is the service's last statement. `ModuleSchemaMigrator` then records its normal schema-ledger row before committing the same transaction.
- Confirmed no post-marker worker/job reconciliation exists; repeated setup can only run the separate permission reconciliation.
- Confirmed the v1 lease predicate exactly excludes leases owned by `api_version >= 2` workers.
- Confirmed no SQL writes `attempts`, terminal statuses, results, transcripts, events, cursors, worker/API-key IDs, or key material.
- Mutation review: removing the worker/job update, changing the v1 predicate, incrementing attempts, changing terminal rows, moving the marker before data updates, touching v2 rows, or performing a second-run write is caught by the focused test.
- Limitation: the production-shaped Phalcon rehearsal could not execute on this host because `ext-phalcon` is absent; its PHP syntax is valid and the non-Phalcon real-SQLite test plus setup/schema contracts executed successfully.
