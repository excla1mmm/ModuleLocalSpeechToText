# STT Scanner PDO Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the STT scanner self-heal from stale or poisoned PDO state by safely recycling its process, rejecting duplicate scanners, and applying bounded restart backoff entirely inside `ModuleLocalSpeechToText`.

**Architecture:** Keep transaction cleanup in the existing recovery service, add a pure PDO failure policy and a recovery-outcome coordinator, and make the worker return after fatal database outcomes so its supervisor builds a clean process and DI graph. Protect the complete scanner lifetime with a module-owned advisory lock and persist only a small restart counter in the stable Core-managed worker lock directory. The clearable Core temp directory is unsafe because pathname replacement can split singleton ownership across two inodes.

**Tech Stack:** PHP 8.3, Phalcon database adapters/models, PDO/SQLite, POSIX `flock`, MikoPBX `WorkerBase`, module structured logging, standalone PHP contract tests.

**Design specification:** `docs/superpowers/specs/2026-08-04-stt-scanner-pdo-recovery-design.md`

## Global Constraints

- Modify only `Extensions/ModuleLocalSpeechToText`; do not change MikoPBX Core.
- Do not recreate or re-register PDO/DI services inside the running scanner.
- Do not change database schema, REST contracts, worker payloads, job ownership, leases, or event formats.
- Keep the process lock outside the replaceable module `db` directory.
- Hold the process lock during startup backoff and until the worker's outermost `finally` completes.
- Revalidate retained process-guard ownership after backoff and before every lifecycle write lease.
- Publish composite CDR cursor IDs before end times so interrupted writes are conservative.
- Release the lifecycle write lease before every sleep, normal stop, or database recycle.
- Treat only SQLite `BUSY` and `LOCKED` as transient; recycle them on the third consecutive database failure.
- Recycle immediately for every other PDO failure and for every transaction-recovery failure.
- Preserve current continue-after-delay behavior for non-PDO exceptions when recovery succeeds.
- Never log exception messages, SQL, DSNs, paths, transcript data, or credentials in the new recovery context.
- Add new standalone tests under `Tests/check-*.php`; `Tests/run-all.php` discovers them automatically.

---

## File and Responsibility Map

| File | Responsibility |
|---|---|
| `Lib/Services/ScannerDatabaseFailurePolicy.php` | Pure PDO-chain classification and continue/recycle decision |
| `Lib/Services/ScannerIterationFailureHandler.php` | Transaction recovery, consecutive-count update, and structured recovery outcome |
| `Lib/Services/ScannerProcessGuard.php` | Process singleton plus atomic persistent restart/backoff state |
| `bin/WorkerLocalSpeechToTextJobs.php` | Phase tracking, structured diagnostics, healthy reset, and controlled return |
| `Tests/check-scanner-database-failure-policy.php` | Numeric, wrapped, message-fallback, and decision unit contracts |
| `Tests/check-scanner-transaction-recovery.php` | Existing rollback checks plus failure-handler orchestration |
| `Tests/check-scanner-process-guard.php` | Lock contention/failure, ownership integrity, warning confidentiality, release, backoff, stale, and malformed-state contracts |
| `Tests/check-scanner-process-guard-compatibility.php` | Old-Core default acquisition through the stable `/var/run/php-workers` fallback |
| `Tests/check-scanner-cursor-interruption.php` | Stateful failure injection for realtime/backfill cursor and completion publication |
| `Tests/check-scanner-worker-recycle.php` | Executable worker duplicate/recycle matrix, lease release, logging, and process-lock-loss behavior |

---

### Task 1: Classify scanner PDO failures and choose a bounded action

**Files:**
- Create: `Lib/Services/ScannerDatabaseFailurePolicy.php`
- Create: `Tests/check-scanner-database-failure-policy.php`

**Interfaces:**
- Produces: `ScannerDatabaseFailurePolicy::classify(Throwable $exception): string`.
- Produces: `ScannerDatabaseFailurePolicy::recoveryAction(string $kind, int $consecutiveFailures, bool $recoverySucceeded): string`.
- Produces stable `KIND_*`, `ACTION_CONTINUE`, `ACTION_RECYCLE`, and `BUSY_RECYCLE_THRESHOLD` constants.

- [ ] **Step 1: Write the failing numeric-code and exception-chain tests**

Create a standalone test with this exception helper:

```php
function scannerPdoException(int|string $code, string $message = 'database failure'): PDOException
{
    $exception = new PDOException($message, is_int($code) ? $code : 0);
    $exception->errorInfo = ['HY000', $code, $message];

    return $exception;
}
```

Assert the exact map:

```php
$expectedKinds = [
    5 => ScannerDatabaseFailurePolicy::KIND_BUSY,
    6 => ScannerDatabaseFailurePolicy::KIND_LOCKED,
    8 => ScannerDatabaseFailurePolicy::KIND_READONLY,
    10 => ScannerDatabaseFailurePolicy::KIND_IO,
    11 => ScannerDatabaseFailurePolicy::KIND_CORRUPT,
    14 => ScannerDatabaseFailurePolicy::KIND_CANTOPEN,
    26 => ScannerDatabaseFailurePolicy::KIND_NOTADB,
];
```

Also wrap a PDO exception as the `previous` exception of `RuntimeException` and assert that it is still classified. Test extended SQLite codes `261` and `262`; masking the low byte must classify them as `busy` and `locked`.

- [ ] **Step 2: Add failing fallback and decision assertions**

Cover these message fallbacks without exposing them to logging code:

```php
$fallbacks = [
    'sqlite_busy: database is busy' => ScannerDatabaseFailurePolicy::KIND_BUSY,
    'database table is locked' => ScannerDatabaseFailurePolicy::KIND_LOCKED,
    'attempt to write a readonly database' => ScannerDatabaseFailurePolicy::KIND_READONLY,
    'disk I/O error' => ScannerDatabaseFailurePolicy::KIND_IO,
    'database disk image is malformed' => ScannerDatabaseFailurePolicy::KIND_CORRUPT,
    'unable to open database file' => ScannerDatabaseFailurePolicy::KIND_CANTOPEN,
    'file is not a database' => ScannerDatabaseFailurePolicy::KIND_NOTADB,
];
```

Assert `RuntimeException` becomes `not_pdo`, an unmapped `PDOException` becomes `unknown_pdo`, busy/locked continue for counts 1 and 2, busy/locked recycle at count 3, and all other PDO kinds recycle at count 1. Assert `recoverySucceeded=false` always recycles, including `not_pdo`.

- [ ] **Step 3: Run the new test and verify RED**

Run: `php Tests/check-scanner-database-failure-policy.php`

Expected: failure because `ScannerDatabaseFailurePolicy.php` does not exist.

- [ ] **Step 4: Implement stable constants and PDO-chain discovery**

Create the final service with this public surface:

```php
final class ScannerDatabaseFailurePolicy
{
    public const string KIND_NOT_PDO = 'not_pdo';
    public const string KIND_BUSY = 'busy';
    public const string KIND_LOCKED = 'locked';
    public const string KIND_READONLY = 'readonly';
    public const string KIND_IO = 'io';
    public const string KIND_CORRUPT = 'corrupt';
    public const string KIND_CANTOPEN = 'cantopen';
    public const string KIND_NOTADB = 'notadb';
    public const string KIND_UNKNOWN_PDO = 'unknown_pdo';
    public const string ACTION_CONTINUE = 'continue';
    public const string ACTION_RECYCLE = 'recycle';
    public const int BUSY_RECYCLE_THRESHOLD = 3;

    public function classify(Throwable $exception): string;
    public function recoveryAction(
        string $kind,
        int $consecutiveFailures,
        bool $recoverySucceeded
    ): string;
}
```

Walk `getPrevious()` until a `PDOException` is found. Prefer numeric `errorInfo[1]`, then numeric `getCode()`, and normalize extended SQLite results with `$numericCode & 0xff`. Use message matching only when no known numeric base code exists.

- [ ] **Step 5: Implement the fail-safe decision table**

Use this order:

```php
if (!$recoverySucceeded) {
    return self::ACTION_RECYCLE;
}
if ($kind === self::KIND_NOT_PDO) {
    return self::ACTION_CONTINUE;
}
if (in_array($kind, [self::KIND_BUSY, self::KIND_LOCKED], true)) {
    return $consecutiveFailures >= self::BUSY_RECYCLE_THRESHOLD
        ? self::ACTION_RECYCLE
        : self::ACTION_CONTINUE;
}

return self::ACTION_RECYCLE;
```

An unrecognized kind therefore fails closed to process recycling.

- [ ] **Step 6: Run focused tests and verify GREEN**

Run: `php Tests/check-scanner-database-failure-policy.php && php Tests/check-module-transaction.php`

Expected: both commands exit 0; existing transaction-level busy retry remains unchanged.

- [ ] **Step 7: Commit the failure policy**

```bash
git add Lib/Services/ScannerDatabaseFailurePolicy.php Tests/check-scanner-database-failure-policy.php
git commit -m "feat: classify scanner database failures"
```

### Task 2: Turn rollback and connection-resolution failures into structured outcomes

**Files:**
- Create: `Lib/Services/ScannerIterationFailureHandler.php`
- Modify: `Tests/check-scanner-transaction-recovery.php`

**Interfaces:**
- Consumes: Task 1 policy and existing `ScannerTransactionRecovery`.
- Produces: `ScannerIterationFailureHandler::handle(Throwable $exception, callable $connectionsProvider, int $previousConsecutiveFailures): array`.
- Returns the exact shape below and does not log, sleep, exit, or throw for an expected recovery failure.

```php
array{
    database_error_kind: string,
    recovery_action: string,
    recovery_succeeded: bool,
    consecutive_database_failures: int,
    recovery_exception: ?TransactionRecoveryException
}
```

- [ ] **Step 1: Extend the recovery test with a failing successful-recovery outcome**

Reuse `ScannerConnectionProbe`. Create a busy `PDOException`, provide one active connection through a closure, and assert:

```php
$outcome = (new ScannerIterationFailureHandler())->handle(
    $busy,
    static fn (): array => [$active],
    0
);
expectScannerRecovery($active->rollbackCalls === 1, 'active transaction was not recovered');
expectScannerRecovery($outcome['database_error_kind'] === 'busy', 'busy classification was lost');
expectScannerRecovery($outcome['consecutive_database_failures'] === 1, 'busy count was not incremented');
expectScannerRecovery($outcome['recovery_action'] === 'continue', 'first busy failure must continue');
expectScannerRecovery($outcome['recovery_succeeded'] === true, 'successful rollback was not reported');
```

- [ ] **Step 2: Add failing poison and non-PDO outcome assertions**

Assert all three paths:

- a rollback returning false produces `recovery_succeeded=false`, retains a `TransactionRecoveryException`, and selects `recycle`;
- a connection-provider closure throwing `TransactionRecoveryException` produces the same controlled outcome;
- a non-PDO exception after successful recovery resets a previous count of 2 to 0 and selects `continue`.

Remove the old worker-source assertion requiring `throw $recoveryException;`. Worker integration is deliberately deferred to Task 4 and will be covered by `check-scanner-worker-recycle.php`; this task must test the handler without depending on a future worker edit.

- [ ] **Step 3: Run the updated test and verify RED**

Run: `php Tests/check-scanner-transaction-recovery.php`

Expected: failure because `ScannerIterationFailureHandler` does not exist.

- [ ] **Step 4: Implement the outcome coordinator**

Use optional concrete dependencies for isolated construction:

```php
public function __construct(
    ?ScannerTransactionRecovery $transactionRecovery = null,
    ?ScannerDatabaseFailurePolicy $failurePolicy = null
) {
    $this->transactionRecovery = $transactionRecovery ?? new ScannerTransactionRecovery();
    $this->failurePolicy = $failurePolicy ?? new ScannerDatabaseFailurePolicy();
}
```

Inside `handle()`:

1. classify the original exception;
2. reset the count for `not_pdo`, otherwise increment `max(0, $previousConsecutiveFailures)`;
3. invoke the connection provider inside the recovery `try` block;
4. require its result to be an array;
5. call `ScannerTransactionRecovery::recover()`;
6. catch `TransactionRecoveryException` and retain it in the outcome;
7. wrap any other provider/recovery `Throwable` in `TransactionRecoveryException`;
8. call `recoveryAction()` with the actual recovery status.

- [ ] **Step 5: Run focused recovery checks and verify GREEN**

Run: `php Tests/check-scanner-transaction-recovery.php && php Tests/check-scanner-database-failure-policy.php`

Expected: both commands exit 0 and the test confirms all connections are still attempted once.

- [ ] **Step 6: Commit structured recovery outcomes**

```bash
git add Lib/Services/ScannerIterationFailureHandler.php Tests/check-scanner-transaction-recovery.php
git commit -m "feat: coordinate scanner transaction recovery"
```

### Task 3: Enforce one scanner process and persist bounded restart backoff

**Files:**
- Create: `Lib/Services/ScannerProcessGuard.php`
- Create: `Tests/check-scanner-process-guard.php`

**Interfaces:**
- Produces: `acquire(): bool`, integrity-only `assertOwnership(): void`, `startupBackoffSeconds(): int`, `recordRecycle(): void`, `recordHealthy(): void`, `lastStateErrorCode(): ?string`, and `release(): void`.
- Constructor: `__construct(?string $runtimeDirectory = null, mixed $clock = null)`.
- Default root: use `Processes::getLockFileDir()` when the method exists, otherwise the literal stable `/var/run/php-workers` fallback required by advertised PBX compatibility. Append `/ModuleLocalSpeechToText/scanner`. Do not use `Directories::CORE_TEMP_DIR`: temp cleanup can replace the pathname while the old process retains the original inode.
- Files: `.scanner-process.lock` and `.scanner-recovery-state.json` with directory mode `0750` and file mode `0640`.

- [ ] **Step 1: Write the failing singleton contract**

Create a unique test directory beneath `sys_get_temp_dir()`. Construct two guards for it and assert:

```php
expectScannerGuard($first->acquire(), 'first scanner must acquire the singleton');
expectScannerGuard(!$second->acquire(), 'second scanner must be rejected');
$first->release();
expectScannerGuard($second->acquire(), 'lock must become available after release');
$second->release();
```

Register a test cleanup function that removes only files beneath the exact generated directory; do not use a broad shell delete.

- [ ] **Step 2: Add failing restart-state and backoff assertions**

Inject a closure clock by reference. Across successive guard instances, call `recordRecycle()` and assert startup delays of `5`, `15`, `30`, `60`, and `60` seconds. Then call `recordHealthy()` and assert the next startup delay is `0`.

Advance the clock by 301 seconds after a recycle and assert the old series produces no startup delay; the next `recordRecycle()` must begin again at count 1.

- [ ] **Step 3: Add failing malformed-state and destructor assertions**

Write invalid JSON to `.scanner-recovery-state.json`, acquire a guard, and assert:

```php
expectScannerGuard($guard->startupBackoffSeconds() === 5, 'invalid state must use minimum delay');
expectScannerGuard($guard->lastStateErrorCode() === 'scanner_state_invalid', 'invalid state code missing');
```

Unset an acquired guard, run `gc_collect_cycles()`, and verify another guard can acquire the lock. Assert a symlinked lock or state file is rejected rather than followed.

- [ ] **Step 4: Run the guard test and verify RED**

Run: `php Tests/check-scanner-process-guard.php`

Expected: failure because `ScannerProcessGuard.php` does not exist.

- [ ] **Step 5: Implement secure lock acquisition and lifetime release**

On `acquire()`:

1. create the exact runtime directory when absent;
2. reject an empty path or a symlinked runtime directory;
3. create/open `.scanner-process.lock` without following a symlink;
4. set mode `0640` for a newly created file;
5. call `flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)`, returning `false` only when `$wouldBlock === 1` and throwing a stable exception for other failures;
6. retain the resource only on success.

Make repeated `acquire()` on the same owning instance idempotently return true. Make `release()` unlock, close, and clear the resource. Call `release()` from `__destruct()`.

- [ ] **Step 6: Implement atomic state and exact backoff math**

Persist exactly:

```php
[
    'failure_count' => $failureCount,
    'last_failure_at' => $now,
]
```

Require lock ownership before reading or writing state. For writes, encode with `JSON_THROW_ON_ERROR`, write a randomized regular sibling temporary file, `chmod(0640)`, and atomically `rename()` it over the state file. Clean up only that exact temporary path if an exception occurs.

Use:

```php
private const array BACKOFF_SECONDS = [5, 15, 30, 60];
private const int RECYCLE_SERIES_TTL_SECONDS = 300;
```

`startupBackoffSeconds()` returns `0` for missing, healthy, or stale state. Invalid/unreadable state sets `scanner_state_invalid`, initializes the in-memory state as one recent recycle, and returns `5`. `recordRecycle()` increments only a recent series. `recordHealthy()` atomically stores zero state only when a failure series is active.

- [ ] **Step 7: Run focused guard checks and verify GREEN**

Run: `php Tests/check-scanner-process-guard.php && php Tests/check-module-lifecycle-gate.php`

Expected: both commands exit 0; the new process lock is independent of the existing lifecycle shared/exclusive lock.

- [ ] **Step 8: Commit the process guard**

```bash
git add Lib/Services/ScannerProcessGuard.php Tests/check-scanner-process-guard.php
git commit -m "feat: guard STT scanner process lifetime"
```

### Task 4: Integrate controlled recycling and phase diagnostics into the worker

**Files:**
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Create: `Tests/check-scanner-worker-recycle.php`

**Interfaces:**
- Consumes: Tasks 2 and 3.
- Produces events: `scanner_duplicate_rejected`, `scanner_recovery_backoff_applied`, `scanner_recycle_requested`, existing `operation_failed`, existing `database_operation_failed`, and `scanner_stopped` with a stable reason.
- Produces phases: `runtime_pragmas`, `cdr_schema`, `stale_workers`, `stale_leases`, `delayed_recordings`, `skipped_recordings`, `cdr_scan`, `call_transcript_publish`, and `retention_cleanup`.

- [ ] **Step 1: Write the failing executable duplicate-scanner behavior test**

Build the test harness using real `ScannerProcessGuard`, `ScannerDatabaseFailurePolicy`, `ScannerIterationFailureHandler`, `ScannerTransactionRecovery`, and the real worker class. Stub only MikoPBX framework boundaries and unrelated scanner collaborators, following the namespace pattern in `Tests/check-worker-retry-overflow.php`.

Point the `Processes::getLockFileDir()` stub at a unique temporary root. Acquire the real process guard before calling `WorkerLocalSpeechToTextJobs::start()` and assert that the worker:

- emits `scanner_duplicate_rejected`;
- returns without calling runtime pragmas, CDR schema setup, queue recovery, or publication;
- does not acquire a lifecycle write lease;
- leaves the original guard as the sole lock owner.

- [ ] **Step 2: Add failing fatal-PDO recycle and cleanup behavior assertions**

Release the competing guard, configure the `STTWorker::markStaleOffline()` stub to throw an unknown `PDOException`, and run a fresh worker. Assert observable effects:

- the lifecycle gate acquired exactly one write lease and released that same lease exactly once;
- `operation_failed` reports `scan_phase=stale_workers`, `database_error_kind=unknown_pdo`, `consecutive_failures=1`, `recovery_action=recycle`, and `recovery_succeeded=true`;
- `scanner_recycle_requested` is emitted;
- `scanner_stopped` reports `stop_reason=database_recycle`;
- no later queue/publication collaborator ran;
- a new real `ScannerProcessGuard` can acquire immediately after `start()` returns, proving outer-finally release;
- the test completes without the normal 15-second iteration delay.

Require these safe context keys in captured events:

```php
[
    'scan_phase',
    'database_error_kind',
    'consecutive_failures',
    'recovery_action',
    'recovery_succeeded',
    'stop_reason',
]
```

Forbid captured context keys named `exception_message`, `sql`, `dsn`, `database_path`, or `trace`. Require error values to be bounded sanitizer codes rather than the injected PDO message.

As an additional module-boundary guard only, read the worker source once and assert that `ModulesDBConnectionsProvider` and `recreateModulesDBConnections` are absent. Do not use source text to prove recycle, ordering, logging, or lock behavior.

- [ ] **Step 3: Run the worker contract and verify RED**

Run: `php Tests/check-scanner-worker-recycle.php && php Tests/check-scanner-transaction-recovery.php`

Expected: behavior assertions fail because the worker has no singleton or controlled recycle outcome.

- [ ] **Step 4: Wrap scanner startup with the process guard**

At the beginning of `start()` construct the guard and initialize:

```php
$processGuard = new ScannerProcessGuard();
$processGuardAcquired = false;
$consecutiveDatabaseFailures = 0;
$recycleRequested = false;
$stopReason = 'signal';
```

Acquire inside an outer `try/finally`. On `false`, log `scanner_duplicate_rejected` with only `pid` and return. On an acquisition exception, log `operation_failed` with `operation=scanner_process_guard` and a sanitized `error_code`, then return without running unlocked.

After acquisition, obtain `startupBackoffSeconds()`. Log `scanner_recovery_backoff_applied` with `delay_seconds` and the guard's bounded state error code when present, sleep exactly that delay, dispatch signals, and return if shutdown was requested. Only then log `scanner_started` and create `ModuleLifecycleGate`.

- [ ] **Step 5: Assign a phase immediately before each scanner operation**

Set `$scanPhase` immediately before the corresponding current call:

```php
$scanPhase = 'runtime_pragmas';          // configureRuntime()
$scanPhase = 'cdr_schema';               // ensureCdrTables()
$scanPhase = 'stale_workers';            // markStaleOffline()
$scanPhase = 'stale_leases';             // requeueStaleProcessing()
$scanPhase = 'delayed_recordings';        // retryDelayedRecordings()
$scanPhase = 'skipped_recordings';        // retrySkippedRecordings()
$scanPhase = 'cdr_scan';                  // createJobsFromCompletedCdr()
$scanPhase = 'call_transcript_publish';   // publishReady()
$scanPhase = 'retention_cleanup';         // purgeExpiredTranscripts()
```

Set `$iterationSucceeded = true` only after retention cleanup returns.

- [ ] **Step 6: Replace the catch path with outcome-driven recovery**

Call:

```php
$outcome = (new ScannerIterationFailureHandler())->handle(
    $exception,
    fn (): array => $this->scannerConnections(),
    $consecutiveDatabaseFailures
);
$consecutiveDatabaseFailures = $outcome['consecutive_database_failures'];
```

Log `operation_failed` with the safe phase, kind, count, action, recovery status, and sanitizer-derived original `error_code`. If `recovery_exception` is non-null, log existing `database_operation_failed` at `LOG_CRIT` with only `operation=scanner_database_recovery` and its sanitizer-derived `error_code`; do not throw it.

When the action is `recycle`, attempt `recordRecycle()` in its own `try/catch`, log a sanitized warning if state persistence fails, set `$recycleRequested=true` and `$stopReason='database_recycle'`, and log `scanner_recycle_requested`.

- [ ] **Step 7: Complete iteration cleanup before reset, sleep, or return**

Keep `releaseWriteLease($writeLease)` in the iteration `finally`. After that block:

1. if `$iterationSucceeded`, reset `$consecutiveDatabaseFailures=0` and call `recordHealthy()` in an isolated `try/catch`;
2. if `$recycleRequested`, `break` before `sleep(self::SCAN_INTERVAL_SECONDS)`;
3. otherwise retain the current 15-second sleep.

After the loop, log `scanner_stopped` with `pid` and `stop_reason`. Release the process guard only in the outermost `finally`, including the signal and recycle paths.

- [ ] **Step 8: Run focused scanner and logging tests**

Run:

```bash
php Tests/check-scanner-worker-recycle.php
php Tests/check-scanner-transaction-recovery.php
php Tests/check-scanner-process-guard.php
php Tests/check-module-logging-contract.php
php Tests/check-module-lifecycle-gate.php
php Tests/check-queue-lifecycle-contract.php
```

Expected: every command exits 0; existing queue and lifecycle contracts remain unchanged.

- [ ] **Step 9: Commit worker integration**

```bash
git add bin/WorkerLocalSpeechToTextJobs.php Tests/check-scanner-worker-recycle.php
git commit -m "fix: recycle poisoned STT scanner connections"
```

### Task 4A: Harden singleton integrity and interruption boundaries

**Files:**
- Modify: `Lib/Services/ScannerProcessGuard.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Modify: `Tests/check-scanner-process-guard.php`
- Modify: `Tests/check-scanner-worker-recycle.php`
- Create: `Tests/check-scanner-cursor-interruption.php`

- [ ] Add executable RED coverage for runtime/lock replacement after backoff and between iterations, then stop with `process_guard_lost` before another database scan.
- [ ] Add a standalone old-Core `Processes` stub without `getLockFileDir()` and prove default construction/acquisition uses `/var/run/php-workers`, never Core temp.
- [ ] Add `assertOwnership()` checks for acquired resources, retained canonical pathname and handle identity, owner/type/device/inode, and exact modes without normalization or state mutation.
- [ ] Compare exact modes with `07777`; conditionally exercise real setuid/setgid/sticky bits for runtime, lock, state, temporary, and publication paths.
- [ ] Convert guard filesystem warnings to stable path-free exceptions with a narrowly scoped error handler that always restores its predecessor; place all startup guard operations inside the sanitized worker boundary.
- [ ] Cover real contention separately from non-contention `flock()` failure by using `$wouldBlock`.
- [ ] Cover BUSY threshold, success reset, non-PDO continue, and recovery-failure recycle with one release per acquired lifecycle lease and no normal post-recycle sleep.
- [ ] Failure-inject all nine documented worker phases and assert exact phase, bounded safe context, controlled recycle, no later collaborator, one lifecycle release, and no normal sleep.
- [ ] Persist realtime/backfill cursor IDs before end times, and the backfill history snapshot before the done flag; prove second-write interruption cannot skip ordered rows.
- [ ] Register randomized-root cleanup immediately, track lifecycle lock/marker pre-existence independently, and remove only artifacts created by the test.

### Task 5: Verify regression safety and package boundaries

**Files:**
- Verify only; no planned production file changes.

**Interfaces:**
- Confirms the design acceptance criteria and module-only packaging boundary.

- [ ] **Step 1: Lint every changed PHP file**

Run:

```bash
php -l Lib/Services/ScannerDatabaseFailurePolicy.php
php -l Lib/Services/ScannerIterationFailureHandler.php
php -l Lib/Services/ScannerProcessGuard.php
php -l bin/WorkerLocalSpeechToTextJobs.php
php -l Tests/check-scanner-database-failure-policy.php
php -l Tests/check-scanner-transaction-recovery.php
php -l Tests/check-scanner-process-guard.php
php -l Tests/check-scanner-process-guard-compatibility.php
php -l Tests/check-scanner-worker-recycle.php
php -l Tests/check-scanner-cursor-interruption.php
```

Expected: every file reports `No syntax errors detected`.

- [ ] **Step 2: Run the complete module contract suite**

Run: `php Tests/run-all.php`

Expected: final line `All module contract tests passed`.

- [ ] **Step 3: Build the module package in an isolated temporary directory**

Run:

```bash
module_build_dir="$(mktemp -d /tmp/module-local-stt-pdo-recovery.XXXXXX)"
./scripts/package-module.sh "$module_build_dir"
find "$module_build_dir" -maxdepth 5 -type f -print | sort
```

Expected: packaging exits 0, contains the three new services, and includes no MikoPBX Core file or runtime lock/state file.

- [ ] **Step 4: Verify scope, whitespace, and forbidden reconnect APIs**

Run:

```bash
git diff --check origin/develop...HEAD
git diff --name-only origin/develop...HEAD
scanner_recovery_files=(
    bin/WorkerLocalSpeechToTextJobs.php
    Lib/Services/ScannerDatabaseFailurePolicy.php
    Lib/Services/ScannerIterationFailureHandler.php
    Lib/Services/ScannerProcessGuard.php
)
if rg -n "recreateModulesDBConnections|ModulesDBConnectionsProvider" "${scanner_recovery_files[@]}"; then
    exit 1
fi
if git diff -U0 origin/develop...HEAD -- Lib bin \
    | rg -q '^\+.*(recreateModulesDBConnections|ModulesDBConnectionsProvider)'; then
    exit 1
fi
rg -n "recreateModulesDBConnections|ModulesDBConnectionsProvider" Tests/check-scanner-worker-recycle.php
```

Expected: `git diff --check` exits 0; every changed path is inside this module; the scanner recovery files and new production diff additions contain no reconnect call. Pre-existing lifecycle/metadata services outside the scanner scope are not part of this check. A literal may exist only in the worker behavior test's additional forbidden-string assertion.

- [ ] **Step 5: Inspect commit and worktree state**

Run:

```bash
git log --oneline --decorate -6
git status --short --branch
```

Expected: the four implementation commits are present after the documentation commits, and the worktree is clean.

## Implementation Completion Checklist

- [ ] One scanner owns the loop; a duplicate returns before database work.
- [ ] Non-busy PDO errors request process recycle after one failed iteration.
- [ ] Busy/locked errors continue twice and recycle on the third consecutive failure.
- [ ] Recovery failure requests recycle without escaping lease cleanup.
- [ ] A successful iteration resets in-memory and persisted restart state.
- [ ] Restart delays are exactly 5, 15, 30, and at most 60 seconds.
- [ ] Every database-bearing phase is logged with bounded, non-sensitive context.
- [ ] No in-process PDO or DI recreation was introduced.
- [ ] Full module tests and package verification pass.
- [ ] No file outside `ModuleLocalSpeechToText` changed.
