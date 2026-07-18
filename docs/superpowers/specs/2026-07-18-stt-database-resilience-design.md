# STT Database Resilience and Smooth Migration Design

## Purpose

Eliminate leaked SQLite transactions, stale schema metadata, duplicate indexes, and reinstall races in `ModuleLocalSpeechToText`, while preserving all STT jobs, transcript results, worker ownership contracts, and existing settings during upgrade.

Core is an immutable dependency for this work. The module therefore provides its own lifecycle gate, SQLite backup override, setup snapshot, metadata invalidation, and migration rollback without changing MikoPBX Core.

The first upgrade from an older module cannot execute lifecycle code that does not yet exist in that installed version. That bridge upgrade starts from the legacy `DELETE` journal mode, creates a verified setup snapshot before changing schema, and restores the database on setup failure. After the bridge version installs successfully, its lifecycle and uninstall overrides protect every subsequent update, and WAL may be enabled. Returning the previous package's code after a failure of the first bridge upgrade remains outside module control; preserving the database and making the installation safely retryable are mandatory.

## Required Invariants

- Preserve `worker_uid`, `job_id`, and `lease_token` ownership semantics.
- Preserve transcript timestamps as integer milliseconds and do not change worker payloads.
- Preserve all committed jobs, results, transcript events, call transcript revisions, and settings.
- Scanner failures never leave an open module database transaction.
- A transaction helper commits or rolls back only a transaction it owns.
- A failed rollback in the scanner terminates the process so the supervisor creates a clean connection.
- Schema migrations are ordered, idempotent, restartable, and recorded only after commit.
- Fresh install and upgraded install converge on equivalent schema constraints and index coverage.
- WAL is enabled only on a safe local filesystem and only after the installed module has created the module-owned safe-backup capability marker.

## Architecture

### 1. Unified transaction helper

`ModuleTransaction` wraps module database operations. Its public API accepts a database adapter, an operation, and bounded retry settings for SQLite busy/locked failures.

For an outer transaction it:

1. Verifies the connection is not unexpectedly under a transaction.
2. Calls `begin()` inside the protected block and verifies both its return value and `isUnderTransaction()`.
3. Runs the operation.
4. Calls `commit()` and verifies that the connection is no longer under a transaction.
5. On any exception, rolls back only if it started the transaction.
6. Verifies that rollback cleared the transaction; otherwise throws `TransactionRecoveryException`.

The helper retries a complete operation only for SQLite `BUSY` or `LOCKED`, only after rollback succeeded, and with a small bounded backoff. It never retries validation, ownership, or application exceptions.

Unexpected ambient transactions at top-level module write boundaries are treated as an error. This prevents a request from accidentally committing a transaction started by Core propagation.

All explicit STT transactions, including result submission and worker processing settings, use this helper. Raw `BEGIN IMMEDIATE`, `COMMIT`, and `ROLLBACK` statements are removed. Processing settings updates remain atomic through a transaction and bounded busy retry.

### 2. Scanner recovery

The scanner loop has a top-level database recovery step in its catch path:

- Inspect every connection used by the scanner during the iteration.
- Roll back only connections that report an active transaction.
- Verify every connection returned to transaction level zero.
- If recovery succeeds, log the failed stage and continue after the normal scan delay.
- If recovery throws or any transaction remains active, log a critical error and terminate with a non-zero exit status.

Ordinary per-record data errors do not force a process exit after successful rollback. Logs include the scanner stage and record/job identifier so deterministic bad input can be isolated without an opaque crash loop.

### 3. Versioned schema migrations

`ModuleSchemaMigrator` is the sole owner of STT schema upgrades. It uses a raw SQLite migration ledger named `m_STTSchemaMigration`, avoiding ORM metadata while the schema is changing. Each migration has an integer version, stable name, and checksum.

Migration flow:

1. Create the ledger if absent.
2. Inspect legacy schema rather than inferring it from the package version.
3. Validate required legacy invariants and establish the matching baseline.
4. Apply pending migrations in order, one transaction per migration.
5. Insert the migration ledger row in the same transaction as its schema/data changes.
6. Re-run schema validation after the last migration.

The generic annotation updater must not mutate STT tables before the versioned migrator. Model annotations remain the ORM mapping contract, but upgrade DDL and index ownership move to migrations. Module registration and sidebar setup remain in the normal setup lifecycle.

Long data backfills use deterministic primary-key checkpoints and bounded batches. Re-running after interruption skips already migrated rows without duplicating transcript events or revisions.

### 4. Canonical index coverage

An index migration compares index definitions using `PRAGMA index_list` and `PRAGMA index_info`, not only names.

For `m_STTTranscriptEvent` it preserves:

- Exactly one unique coverage of `event_key`.
- One cursor index on `(created_at, id)`.
- One lookup index on `result_id`.

Legacy tables with `sqlite_autoindex_*` keep that unique constraint and drop both redundant explicit unique indexes. Fresh tables use one explicit canonical unique index. The migration does not rebuild a populated table merely to rename an auto-index. Duplicate cursor/result indexes are removed with `DROP INDEX IF EXISTS` after equivalent coverage is verified.

Schema creation and annotations are changed first so a reinstall cannot recreate removed duplicates.

### 5. WAL policy

`ModuleDatabasePragmas` configures each new connection:

- Always set a bounded `busy_timeout`.
- Enable WAL only when the database is on a local WAL-safe filesystem and the module-owned safe-backup capability marker exists.
- Use `synchronous=NORMAL` with WAL.
- Leave the existing `DELETE` mode unchanged when either capability check fails.

The migration to WAL occurs only after versioned migrations and the setup snapshot validation succeed. The installed bridge version writes the safe-backup marker only after its `unInstallFiles()` override and lifecycle hooks are present. WAL activation validates that `PRAGMA journal_mode=WAL` returns `wal`. A failed change leaves the module in `DELETE` mode and does not fail an otherwise compatible upgrade.

Before handing control back to Core after a successful migration, the module runs a best-effort `PRAGMA wal_checkpoint(TRUNCATE)`. Sidecar files are never included in module packages or logical backups.

### 6. Lifecycle behavior

The module implements a small graceful-drain hook compatible with Core:

- `RUNNING`: normal API and scanner behavior.
- `DRAINING`: stop assigning new jobs; allow bounded heartbeat and submit-result completion for existing leases.
- `MAINTENANCE`: reject module writes with the existing REST envelope, HTTP 503, and `Retry-After`.

`ModuleLifecycleGate` stores an atomic marker and advisory lock in the module database directory because the existing Core backup/restore path preserves that directory. API and admin write boundaries hold a shared lock for one complete operation. Disable writes the draining marker, waits for the exclusive lock, and retains the blocked state until enable. The scanner sleeps while blocked instead of exiting, preventing a supervisor restart loop. The design does not restore the previous complex transition state machine.

### 7. Module-owned backup and metadata coordination

The installed module overrides `unInstallFiles()`. When settings must be preserved, it first creates a verified SQLite backup in a temporary sibling path, delegates normal file cleanup to the parent implementation, then atomically replaces the parent's raw database copy with the verified snapshot. WAL and SHM sidecars are excluded. This override protects updates after the bridge release is installed.

During `installDB()`, the new package snapshots the restored legacy database before metadata or schema changes. Failure closes the module connection, restores that snapshot atomically, removes sidecars, verifies integrity, and returns installation failure with the original database preserved.

`ModuleMetadataCache` deletes only Redis DB 2 model metadata keys for `Modules\\ModuleLocalSpeechToText\\Models\\...`, resets in-process metadata, and recreates annotations/connections. It runs before schema inspection and after migration. Redis unavailability is logged and never triggers a global flush from the module.

## Upgrade Compatibility

- The first migration supports the currently deployed unversioned schema through introspection.
- Existing data is not rewritten unless a migration requires it.
- The migration ledger is additive and does not require a Phalcon model.
- Upgrade is idempotent: reinstalling the same package applies no completed migration twice.
- Downgrade is performed from the module-owned verified snapshot, not reverse migrations.
- A short STT-only pause is expected. Telephony and recording continue; the macOS worker retries after 503 without losing saved run intent.

## Failure Handling

- Begin failure: no operation runs and an error is returned.
- Commit failure: roll back if still active; report recovery failure if the connection remains poisoned.
- Scanner rollback failure: terminate the scanner process with a critical log entry.
- Migration failure: leave its ledger row absent, close the module connection, restore the pre-migration snapshot, verify it, and return installation failure.
- Index mismatch: abort before dropping indexes.
- Safe-backup marker absent: retain `DELETE` journal mode and continue safely.
- WAL transition failure: restore `DELETE` when possible, log the failure, and continue without WAL.

## Testing Strategy

Tests use real temporary SQLite databases where possible and PBX integration checks for Phalcon behavior.

- Begin false/exception prevents the operation.
- Commit false/exception causes rollback and never reports success.
- Rollback failure produces `TransactionRecoveryException`.
- Busy retry runs only after a successful rollback and stops at the configured limit.
- Scanner continues after recoverable errors and exits after poisoned-connection recovery.
- Processing settings remain atomic under competing writers.
- Legacy schemas at every known historical shape bootstrap to the correct migration baseline.
- Migration interruption at every version is safely retryable.
- Current production-shaped data preserves row counts and logical relationships.
- Index cleanup leaves required query coverage and is idempotent.
- WAL capability gating keeps a legacy first upgrade in `DELETE` mode until the module-owned backup path is installed.
- The module-owned backup marker enables WAL and backup/restore retains all committed rows.
- Disable/reinstall while scanner, heartbeat, and submit-result traffic are active either completes safely or aborts before destructive steps.

## Rollout

1. The bridge upgrade runs transaction recovery and versioned migrations from the legacy `DELETE` database and creates a verified setup snapshot.
2. After successful setup, install the lifecycle hooks and verified uninstall backup path, then write the safe-backup capability marker.
3. Enable WAL only after that marker exists; all later updates are drained and backed up by the installed STT version.

The module package does not change worker API payloads or require a coordinated macOS application update.
