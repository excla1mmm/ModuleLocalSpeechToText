# STT Database Resilience and Smooth Migration Design

## Purpose

Eliminate leaked SQLite transactions, stale schema metadata, duplicate indexes, and reinstall races in `ModuleLocalSpeechToText`, while preserving all STT jobs, transcript results, worker ownership contracts, and existing settings during upgrade.

This design depends on the Core safe module-upgrade state machine. The module must remain compatible with an older Core, but it must not enable WAL on a Core that still uses raw filesystem copies for module database backups.

## Required Invariants

- Preserve `worker_uid`, `job_id`, and `lease_token` ownership semantics.
- Preserve transcript timestamps as integer milliseconds and do not change worker payloads.
- Preserve all committed jobs, results, transcript events, call transcript revisions, and settings.
- Scanner failures never leave an open module database transaction.
- A transaction helper commits or rolls back only a transaction it owns.
- A failed rollback in the scanner terminates the process so the supervisor creates a clean connection.
- Schema migrations are ordered, idempotent, restartable, and recorded only after commit.
- Fresh install and upgraded install converge on equivalent schema constraints and index coverage.
- WAL is enabled only on a safe local filesystem and only when Core advertises safe SQLite module backups.

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
- Enable WAL only when the database is on Core-approved local storage and Core exposes the safe module-backup capability.
- Use `synchronous=NORMAL` with WAL.
- Leave the existing `DELETE` mode unchanged when either capability check fails.

The migration to WAL occurs under the Core lifecycle barrier, after a verified pre-migration backup and before normal writers restart. It validates that `PRAGMA journal_mode=WAL` returns `wal`. A failed change leaves the module in `DELETE` mode and does not fail an otherwise compatible upgrade.

Before handing control back to Core after a successful migration, the module runs a best-effort `PRAGMA wal_checkpoint(TRUNCATE)`. Sidecar files are never included in module packages or logical backups.

### 6. Lifecycle behavior

The module implements a small graceful-drain hook compatible with Core:

- `RUNNING`: normal API and scanner behavior.
- `DRAINING`: stop assigning new jobs; allow bounded heartbeat and submit-result completion for existing leases.
- `MAINTENANCE`: reject module writes with the existing REST envelope, HTTP 503, and `Retry-After`.

Core owns the authoritative state outside the module directory. The module does not recreate the removed file-lease guard and does not make the scanner repeatedly exit while the module is still enabled. After Core activates the new version, scanner and workers resume from persisted jobs and leases.

### 7. Metadata coordination

The module requests Core metadata invalidation before schema inspection and after migration. On older Core versions it falls back to recreating annotations/connections and logs that WAL remains disabled. It does not issue a global Redis flush itself.

## Upgrade Compatibility

- The first migration supports the currently deployed unversioned schema through introspection.
- Existing data is not rewritten unless a migration requires it.
- The migration ledger is additive and does not require a Phalcon model.
- Upgrade is idempotent: reinstalling the same package applies no completed migration twice.
- Downgrade is performed by Core snapshot restore, not reverse migrations.
- A short STT-only pause is expected. Telephony and recording continue; the macOS worker retries after 503 without losing saved run intent.

## Failure Handling

- Begin failure: no operation runs and an error is returned.
- Commit failure: roll back if still active; report recovery failure if the connection remains poisoned.
- Scanner rollback failure: terminate the scanner process with a critical log entry.
- Migration failure: leave its ledger row absent and return failure to Core for full snapshot restore.
- Index mismatch: abort before dropping indexes.
- WAL capability absent: retain `DELETE` journal mode and continue safely.
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
- WAL capability gating keeps older Core installations in `DELETE` mode.
- Safe Core capability enables WAL and backup/restore retains all committed rows.
- Disable/reinstall while scanner, heartbeat, and submit-result traffic are active either completes safely or aborts before destructive steps.

## Rollout

1. Ship transaction recovery and versioned-migration support with WAL capability-gated off.
2. Ship or require the Core safe-upgrade capability.
3. Enable WAL automatically only after Core reports that capability.

The module package does not change worker API payloads or require a coordinated macOS application update.
