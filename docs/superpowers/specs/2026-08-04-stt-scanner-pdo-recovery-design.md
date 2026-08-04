# STT Scanner PDO Recovery Design

## Purpose

Make the long-running STT scanner recover automatically from a poisoned or stale PDO connection without changing MikoPBX Core, losing queued work, or creating duplicate scanner activity.

The production incident produced 1,803 `pdo_exception` iterations at the fixed 15-second scan interval. The same pending transcript revision published immediately after the scanner process restarted, which shows that the database and persisted result were intact while the process-local connection state remained unusable. A second incident also showed two scanner PIDs starting concurrently. The module must therefore treat an unrecoverable connection as a process-lifetime failure and must enforce one active scanner process.

## Scope

This change is entirely contained in `ModuleLocalSpeechToText`.

It changes:

- scanner process ownership;
- scanner database-error classification and recovery decisions;
- scanner restart throttling;
- scanner phase diagnostics;
- module tests for these behaviors.

It does not change:

- MikoPBX Core or its dependency-injection container;
- the module database schema;
- REST endpoints or response envelopes;
- worker authentication, leases, heartbeats, or payloads;
- transcript event, result, or call-revision formats;
- job retry semantics for ordinary STT failures.

## Required Invariants

- At most one `WorkerLocalSpeechToTextJobs` scanner owns the module scan loop at a time.
- The singleton lock remains held for the complete process lifetime, including startup backoff and shutdown.
- A duplicate scanner exits before it opens the lifecycle scan loop or mutates jobs.
- Every scanner iteration releases its lifecycle write lease before the process exits or sleeps.
- An active database transaction is rolled back and verified after an iteration exception.
- A non-busy PDO failure never repeats indefinitely on the same process-local connection.
- SQLite `BUSY` and `LOCKED` remain bounded transient failures; three consecutive occurrences recycle the process.
- A failed transaction recovery always recycles the process.
- Non-PDO application exceptions preserve the current continue-after-delay behavior.
- A successful complete iteration resets the consecutive failure counter and persistent restart backoff.
- Restart throttling survives process replacement and is bounded at 60 seconds.
- Scanner recovery never re-registers database services or reconstructs PDO connections inside the poisoned process.
- Persisted jobs, results, revisions, and transcript events remain the source of truth across restart.

## Why Process Recycling Is the Recovery Boundary

The scanner obtains module models and services from the process-wide dependency-injection container. Some long-lived services, including retry state storage, retain a connection object. Re-registering only one database service would leave other cached models or services pointing to the previous connection and could produce a mixed-connection process.

MikoPBX Core's database recreation path also warns that re-registration can invalidate cached models. Calling that Core mechanism from the module in the middle of a scanner iteration would broaden the failure surface and would not prove that every retained connection was replaced.

Returning from the scanner worker lets its existing supervisor create a new PHP process. The new process reconstructs the DI container, models, PDO connections, and module services as one consistent unit. Because all durable queue and publication state is already in SQLite, this is both the narrowest and the most reliable module-owned recovery boundary.

## Architecture

### 1. Process singleton and persistent restart state

Add `ScannerProcessGuard`, backed by an advisory `flock(LOCK_EX | LOCK_NB)` file under the module's directory in `Directories::CORE_TEMP_DIR`.

The lock file must be outside the replaceable module database directory. The scanner acquires it before logging `scanner_started`, applying recovery backoff, or entering `ModuleLifecycleGate`. It holds the open lock handle until the outermost `finally` block. If acquisition fails, the process logs `scanner_duplicate_rejected` and returns successfully without touching scanner state.

The same guarded runtime directory contains an atomic JSON state file with:

```json
{"failure_count":2,"last_failure_at":1785819600}
```

Only the process that owns the singleton lock reads or writes this state. Writes use a sibling temporary file followed by atomic rename and restrictive permissions.

At scanner startup, a recent recorded recycle applies the following delay:

| Consecutive recycle count | Startup delay |
|---:|---:|
| 1 | 5 seconds |
| 2 | 15 seconds |
| 3 | 30 seconds |
| 4 or more | 60 seconds |

Recycle history older than five minutes is treated as a new series. One complete successful scanner iteration clears the stored failure count. The lock stays held while applying the delay so a second process cannot overtake the recovering scanner.

### 2. Database failure classification

Add `ScannerDatabaseFailurePolicy`, a pure service that walks the complete exception `getPrevious()` chain and locates a `PDOException`. It classifies SQLite errors from `errorInfo[1]`, a numeric exception code, and then a conservative lowercase message fallback.

The stable classifications are:

- `not_pdo`;
- `busy` for SQLite code 5;
- `locked` for SQLite code 6;
- `readonly` for SQLite code 8;
- `io` for SQLite code 10;
- `corrupt` for SQLite code 11;
- `cantopen` for SQLite code 14;
- `notadb` for SQLite code 26;
- `unknown_pdo` for every other PDO failure.

Only `busy` and `locked` are transient. They return `continue` for the first two consecutive database failures and `recycle` on the third. Every other PDO classification returns `recycle` immediately. `not_pdo` returns `continue` when transaction recovery succeeded. Any failed transaction recovery overrides classification and returns `recycle`.

Message matching is a fallback for drivers or wrappers that omit SQLite's numeric extended information. It recognizes only the equivalent well-known phrases and never logs the original exception message.

### 3. Recovery outcome orchestration

Add `ScannerIterationFailureHandler` to compose the existing `ScannerTransactionRecovery` with `ScannerDatabaseFailurePolicy`.

Its input is the iteration exception, every connection used by the scanner, and the preceding consecutive database-failure count. Its output is a value array containing:

```php
array{
    database_error_kind: string,
    recovery_action: 'continue'|'recycle',
    recovery_succeeded: bool,
    consecutive_database_failures: int,
    recovery_exception: ?TransactionRecoveryException
}
```

The handler attempts recovery for every iteration exception, preserving current rollback behavior. It resets the consecutive database-failure count to zero for `not_pdo`; otherwise it increments the count before asking the policy for an action. A `TransactionRecoveryException` is returned as structured outcome data instead of being rethrown from the scanner catch block, allowing the worker to finish its lifecycle-lease cleanup and request a controlled recycle.

The handler has no logging, sleeping, signal, or process-exit responsibility.

### 4. Controlled scanner recycle

The worker tracks the current scan phase and whether a recycle was requested. Its catch path asks `ScannerIterationFailureHandler` for the recovery outcome and emits safe structured diagnostics.

For `continue`, the worker completes the iteration `finally`, releases the lifecycle write lease, and uses the existing scan delay.

For `recycle`, the worker:

1. records the persistent recycle state while it still owns the process lock;
2. logs `scanner_recycle_requested` with stable context;
3. completes the iteration `finally` and releases the lifecycle write lease;
4. leaves the scan loop without the normal 15-second sleep;
5. logs `scanner_stopped` with `stop_reason=database_recycle`;
6. returns from `start()`;
7. releases the process singleton in the outermost `finally`.

The module does not call `exit`, kill its own PID, reopen a connection, or rebuild the DI container. Returning keeps shutdown deterministic and delegates normal process replacement to the existing worker supervisor.

### 5. Phase-aware diagnostics

Before each database-bearing operation, the worker assigns one of these stable phases:

- `runtime_pragmas`;
- `cdr_schema`;
- `stale_workers`;
- `stale_leases`;
- `delayed_recordings`;
- `skipped_recordings`;
- `cdr_scan`;
- `call_transcript_publish`;
- `retention_cleanup`.

Iteration error logs add only sanitized, bounded fields:

- `scan_phase`;
- `database_error_kind`;
- `consecutive_failures`;
- `recovery_action`;
- `recovery_succeeded`;
- existing sanitizer-derived `error_code`.

No SQL text, database path, DSN, exception message, stack trace, worker key, or transcript content is added. A recovery failure is logged separately with its sanitizer-derived error code, then the controlled recycle proceeds.

## Control Flow

```text
process starts
  -> acquire singleton lock
     -> duplicate: log and return
     -> owner: apply bounded persistent backoff
        -> enter lifecycle-aware scan loop
           -> complete iteration: clear counters/backoff, sleep 15s
           -> exception: recover transactions, classify exception
              -> non-PDO: release lease, sleep 15s, continue
              -> BUSY/LOCKED #1-2: release lease, sleep 15s, continue
              -> BUSY/LOCKED #3: record recycle, release lease, return
              -> other PDO: record recycle, release lease, return
              -> recovery failure: record recycle, release lease, return
        -> release singleton lock
```

## Failure Handling

- Lock directory creation failure: log a sanitized startup failure and do not run an unlocked scanner.
- Lock acquisition contention: treat as a duplicate process, not as a database failure.
- Malformed or unreadable restart state: log a bounded state error code, treat it as one recent recycle, and apply exactly the five-second minimum delay; never block forever.
- Backoff state write failure: log the error and continue with process recycling; safety does not depend on persistence of throttling.
- Transaction recovery failure: request recycle even when the original exception was not PDO-related.
- Unknown PDO failure: recycle immediately.
- Repeated SQLite busy/locked failure: recycle on the third consecutive database-bearing iteration failure.
- Signal-driven shutdown: preserve the existing stop path and report a non-recycle stop reason.

## Testing Strategy

### Unit and service tests

- Classify SQLite codes 5, 6, 8, 10, 11, 14, and 26.
- Detect a PDO exception wrapped inside an application exception.
- Verify conservative message fallback and `unknown_pdo` behavior.
- Continue for busy/locked counts one and two, recycle at count three.
- Recycle immediately for every non-transient PDO classification.
- Continue for a non-PDO exception after successful transaction recovery.
- Recycle after `TransactionRecoveryException` regardless of original classification.
- Verify successful rollback and zero transaction level on all unique connections.

### Process-guard tests

- Two guards pointed at the same temporary directory cannot both acquire the lock.
- Releasing the first guard allows the second to acquire it.
- Backoff progresses through 5, 15, 30, and 60 seconds and remains capped.
- A healthy iteration resets backoff.
- A recycle older than five minutes starts a new series.
- Malformed state cannot grant a second process the singleton lock.

### Worker contract and integration tests

- The singleton is acquired before the lifecycle loop and released by an outer `finally`.
- Duplicate startup returns before job scanning and transcript publication.
- Every required scan phase is assigned before its operation.
- A recycle path releases the lifecycle write lease and skips the normal scan sleep.
- A successful iteration records healthy state.
- A fatal PDO outcome returns from `start()` without re-registering database services.
- The existing full module test suite passes.
- The module packaging check succeeds without Core or schema changes.

## Acceptance Criteria

- Injecting a non-busy PDO failure causes the current scanner to stop after one failed iteration and permits a freshly constructed scanner process to continue pending work.
- Injecting three consecutive SQLite busy/locked failures causes a controlled recycle; fewer than three retain current retry behavior.
- Starting two scanner processes concurrently leaves exactly one process in the scan loop and produces no duplicate jobs or transcript publication.
- Restart storms are delayed by 5, 15, 30, then at most 60 seconds, and a successful scan clears the delay state.
- All exits release both the lifecycle lease and process singleton.
- Logs identify the scan phase, safe database classification, consecutive count, recovery result, and selected action without exposing database or transcript data.
- No file outside `ModuleLocalSpeechToText` is modified.

## Rollout and Compatibility

The change requires no migration and can be installed over the current module database. On first start there is no restart-state file, so the scanner starts without delay. Existing supervisors continue launching the same worker command; a requested recycle appears as a normal worker return followed by a clean supervisor restart.

Rollback to the previous module version safely ignores the module-owned temporary lock and state files. They contain no credentials or business data and may remain until normal temporary-directory cleanup.
