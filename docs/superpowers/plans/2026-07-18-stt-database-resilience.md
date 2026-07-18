# STT Database Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `ModuleLocalSpeechToText` recover from SQLite errors and upgrade its existing database through a module-owned, versioned, backup-protected migration path without changing MikoPBX Core or worker API contracts.

**Architecture:** A single transaction helper owns begin/commit/rollback recovery; a compact file-lock lifecycle gate drains module writers; a SQLite backup service protects uninstall and setup; a raw migration ledger serializes idempotent schema changes; targeted metadata invalidation prevents Phalcon from reusing stale column maps; WAL is enabled only after the safe module-owned backup path is installed and verified.

**Tech Stack:** PHP 8.3+, Phalcon 5.8 database adapters and ORM, SQLite3 backup API, Redis DB 2 metadata cache, file locks, existing PHP contract-test runner.

## Global Constraints

- Do not modify MikoPBX Core or `LocalSTTWorkerMac`.
- Preserve `worker_uid`, `job_id`, `lease_token`, transcript payloads, and integer-millisecond timestamps.
- Do not add new worker API endpoints or require a coordinated macOS worker update.
- Never copy `module.db-wal`, `module.db-shm`, or `module.db-journal` into a verified backup.
- Never enable WAL before the module-owned uninstall backup implementation and setup rollback are installed.
- Every production method added by this plan has a test that is observed failing before implementation.
- A bridge upgrade from an older package preserves and restores the database but cannot restore the previous package's code without Core support.

---

### Task 1: Unified transaction ownership and recovery

**Files:**
- Create: `Lib/Services/TransactionRecoveryException.php`
- Create: `Lib/Services/ModuleTransaction.php`
- Create: `Tests/check-module-transaction.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces: `ModuleTransaction::run(mixed $connection, callable $operation, int $busyRetries = 2): mixed`
- Produces: `ModuleTransaction::recover(mixed $connection): void`
- Produces: `TransactionRecoveryException extends RuntimeException`

- [ ] **Step 1: Write the failing transaction contract**

Create a deterministic adapter probe in `Tests/check-module-transaction.php` that exposes `begin()`, `commit()`, `rollback()`, and `isUnderTransaction()`. Assert that `ModuleTransaction` rejects an ambient transaction, checks false returns from begin/commit, rolls back after operation/commit exceptions, never executes the operation after begin failure, retries only SQLite busy/locked failures after successful rollback, and throws `TransactionRecoveryException` when rollback fails or leaves the adapter under transaction.

```php
$transaction = new ModuleTransaction(static fn(int $attempt): void => null);
$connection = new TransactionAdapterProbe();
$value = $transaction->run($connection, static fn(): string => 'ok');
expectModuleTransaction($value === 'ok', 'successful transaction must return operation result');
expectModuleTransaction($connection->events === ['begin', 'commit'], 'successful transaction order mismatch');

$connection = new TransactionAdapterProbe(beginResult: false);
expectTransactionException(
    static fn() => $transaction->run($connection, static fn() => throw new RuntimeException('must not run')),
    RuntimeException::class,
    'begin'
);

$connection = new TransactionAdapterProbe(rollbackResult: false, operationException: new RuntimeException('boom'));
expectTransactionException(
    static fn() => $transaction->run($connection, static fn() => throw new RuntimeException('boom')),
    TransactionRecoveryException::class,
    'rollback'
);
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php Tests/check-module-transaction.php`

Expected: failure because `ModuleTransaction` and `TransactionRecoveryException` do not exist.

- [ ] **Step 3: Implement transaction ownership**

Implement `ModuleTransaction` with an injected sleeper for deterministic tests. `run()` must reject an already-active transaction, put `begin()` inside `try`, verify transaction state after every transition, classify only error code 5/6 or messages containing `database is locked`, `database table is locked`, or `SQLITE_BUSY` as retryable, and call `recover()` before retrying or rethrowing.

```php
final class ModuleTransaction
{
    public function __construct(private readonly mixed $sleeper = null)
    {
    }

    public function run(mixed $connection, callable $operation, int $busyRetries = 2): mixed
    {
        if ($connection->isUnderTransaction()) {
            throw new RuntimeException('Unexpected ambient module database transaction');
        }
        $attempt = 0;
        do {
            try {
                if ($connection->begin() !== true || !$connection->isUnderTransaction()) {
                    throw new RuntimeException('Unable to begin module database transaction');
                }
                $result = $operation($connection);
                if ($connection->commit() !== true || $connection->isUnderTransaction()) {
                    throw new RuntimeException('Unable to commit module database transaction');
                }
                return $result;
            } catch (Throwable $exception) {
                $this->recover($connection);
                if (!$this->isBusy($exception) || $attempt >= max(0, $busyRetries)) {
                    throw $exception;
                }
                $attempt++;
                ($this->sleeper ?? static fn(int $microseconds): int => usleep($microseconds))(25_000 * $attempt);
            }
        } while (true);
    }

    public function recover(mixed $connection): void
    {
        if (!$connection->isUnderTransaction()) {
            return;
        }
        try {
            $rolledBack = $connection->rollback();
        } catch (Throwable $exception) {
            throw new TransactionRecoveryException('Module database rollback threw an exception', 0, $exception);
        }
        if ($rolledBack !== true || $connection->isUnderTransaction()) {
            throw new TransactionRecoveryException('Module database rollback did not clear the transaction');
        }
    }
}
```

- [ ] **Step 4: Verify GREEN and the complete contract suite**

Run: `php Tests/check-module-transaction.php && php Tests/run-all.php`

Expected: the focused contract and all module contracts pass.

- [ ] **Step 5: Commit**

```bash
git add Lib/Services/TransactionRecoveryException.php Lib/Services/ModuleTransaction.php Tests/check-module-transaction.php Tests/run-all.php
git commit -m "fix: centralize module database transactions"
```

### Task 2: Replace raw and unchecked transactions

**Files:**
- Modify: `Lib/LocalSpeechToTextMain.php`
- Modify: `Lib/Services/WorkerProcessingSettingsService.php`
- Modify: `Lib/Services/WorkerApiKeyService.php`
- Create: `Tests/check-module-transaction-adoption.php`
- Modify: `Tests/check-worker-processing-settings-sqlite.php`

**Interfaces:**
- Consumes: `ModuleTransaction::run()` from Task 1.
- Produces: all STT-owned explicit transactions use the helper; raw `BEGIN IMMEDIATE`, `COMMIT`, and `ROLLBACK` disappear from module code.

- [ ] **Step 1: Write failing adoption and atomicity tests**

Assert the production sources contain `ModuleTransaction` and contain no direct transaction SQL. Extend the real SQLite processing-settings test with two connections: hold a writer lock on connection A, invoke save through connection B, release A during the injected retry boundary, and assert either the complete old profile or complete new profile is visible, never a partial profile.

```php
foreach (['BEGIN IMMEDIATE', "execute('COMMIT')", "execute('ROLLBACK')"] as $forbidden) {
    expectTransactionAdoption(!str_contains($settingsService, $forbidden), "raw transaction SQL remains: {$forbidden}");
}
expectTransactionAdoption(
    str_contains($mainService, 'new ModuleTransaction') && str_contains($settingsService, 'new ModuleTransaction'),
    'STT write services must use ModuleTransaction'
);
```

- [ ] **Step 2: Verify RED**

Run: `php Tests/check-module-transaction-adoption.php`

Expected: failure because existing services still call direct begin/commit/rollback and raw `BEGIN IMMEDIATE`.

- [ ] **Step 3: Route transactions through the helper**

Wrap `submitResult()` result/event/job/revision writes and processing-profile read/update writes in `ModuleTransaction::run()`. Preserve the assigned-job conflict response by throwing a private conflict exception inside the operation and translating it after rollback. Update `WorkerApiKeyService` module-database transaction boundaries without changing its Core API-key compensation ordering.

```php
$transaction = new ModuleTransaction();
try {
    $transaction->run($connection, function () use ($job, $worker, $leaseToken, $data): void {
        $result = $this->persistResult($job, $data);
        STTTranscriptEvent::ensureCompletedForResult($result, $job);
        if (!$job->markDoneForWorker((int)$worker->id, $leaseToken)) {
            throw new AssignedJobConflictException();
        }
        (new CallTranscriptRevisionService())->recordCompletedPart($result, $job);
    });
} catch (AssignedJobConflictException) {
    return self::assignedJobConflict($jobId, $worker, $leaseToken);
}
```

- [ ] **Step 4: Verify GREEN and API contracts**

Run: `php Tests/check-module-transaction-adoption.php && php Tests/check-worker-processing-settings-sqlite.php && php Tests/check-rest-api-contract.php`

Expected: all focused tests pass and ownership/response contracts remain unchanged.

- [ ] **Step 5: Commit**

```bash
git add Lib/LocalSpeechToTextMain.php Lib/Services/WorkerProcessingSettingsService.php Lib/Services/WorkerApiKeyService.php Tests/check-module-transaction-adoption.php Tests/check-worker-processing-settings-sqlite.php
git commit -m "fix: protect STT writes with transaction helper"
```

### Task 3: Scanner transaction cleanup and poisoned-process exit

**Files:**
- Create: `Lib/Services/ScannerTransactionRecovery.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Create: `Tests/check-scanner-transaction-recovery.php`

**Interfaces:**
- Consumes: `ModuleTransaction::recover()` from Task 1.
- Produces: `ScannerTransactionRecovery::recover(array $connections): void`.
- Produces: scanner returns a non-zero process status only when a connection cannot be recovered.

- [ ] **Step 1: Write the failing scanner recovery test**

Test duplicate connection objects are recovered once, inactive connections are ignored, every active connection is rolled back, and a failed rollback is surfaced as `TransactionRecoveryException`. Add a source contract asserting the scanner breaks out of its loop after a recovery failure instead of sleeping and continuing with a poisoned adapter.

```php
$active = new ScannerConnectionProbe(active: true);
$inactive = new ScannerConnectionProbe(active: false);
(new ScannerTransactionRecovery())->recover([$active, $active, $inactive]);
expectScannerRecovery($active->rollbackCalls === 1, 'active connection must be rolled back once');
expectScannerRecovery($inactive->rollbackCalls === 0, 'inactive connection must not be rolled back');
```

- [ ] **Step 2: Verify RED**

Run: `php Tests/check-scanner-transaction-recovery.php`

Expected: failure because `ScannerTransactionRecovery` is missing and scanner catch only logs.

- [ ] **Step 3: Implement scanner recovery**

Track the module, main, and CDR adapters used during an iteration. In the scanner catch, log the stage, recover all unique adapters, and continue only after successful cleanup. On `TransactionRecoveryException`, write a critical log, set a non-zero exit code, and leave the loop so the process ends and releases every file descriptor.

```php
try {
    $this->scanIteration();
} catch (Throwable $exception) {
    LocalSpeechToTextLogger::syslog(__CLASS__, 'Scanner stage failed: ' . $exception->getMessage(), LOG_ERR);
    try {
        (new ScannerTransactionRecovery())->recover($this->iterationConnections());
    } catch (TransactionRecoveryException $recoveryException) {
        LocalSpeechToTextLogger::syslog(__CLASS__, 'Scanner database recovery failed: ' . $recoveryException->getMessage(), LOG_CRIT);
        $exitCode = 1;
        break;
    }
}
```

- [ ] **Step 4: Verify GREEN**

Run: `php Tests/check-scanner-transaction-recovery.php && php Tests/run-all.php`

Expected: scanner recovery and all existing contracts pass.

- [ ] **Step 5: Commit**

```bash
git add Lib/Services/ScannerTransactionRecovery.php bin/WorkerLocalSpeechToTextJobs.php Tests/check-scanner-transaction-recovery.php
git commit -m "fix: recover scanner transactions after errors"
```

### Task 4: Compact lifecycle drain gate

**Files:**
- Create: `Lib/Services/ModuleLifecycleGate.php`
- Modify: `Lib/LocalSpeechToTextConf.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Modify: `Lib/RestAPI/Jobs/Processor.php`
- Modify: `Lib/RestAPI/Workers/Processor.php`
- Modify: `App/Controllers/LocalSpeechToTextBaseController.php`
- Modify: `App/Controllers/ModuleLocalSpeechToTextController.php`
- Replace: `Tests/check-no-module-lifecycle-gating.php`
- Create: `Tests/check-module-lifecycle-gate.php`

**Interfaces:**
- Produces: `ModuleLifecycleGate::acquireWriteLease(): mixed` and `releaseWriteLease(mixed $lease): void`.
- Produces: `beginDrain(int $timeoutMilliseconds): bool`, `completeDisable(): void`, and `completeEnable(): void`.
- Produces: retryable REST result with `httpCode = 503` while draining/disabled.

- [ ] **Step 1: Write failing real-file-lock tests**

Use a temporary directory and real `flock()`. Verify marker-before-lock closes the writer race, a held writer makes drain wait/timeout, releasing the writer lets drain succeed, new writers are rejected after the marker, repeated `beginDrain()` from Core's duplicate hook call is idempotent, abort clears only an owned draining marker, and scanner source sleeps rather than exits while blocked.

```php
$gate = new ModuleLifecycleGate($temporaryDirectory, bootstrap: true);
$lease = $gate->acquireWriteLease();
expectLifecycleGate(is_resource($lease), 'open gate must grant a writer lease');
expectLifecycleGate(!$gate->beginDrain(25), 'drain must time out while writer lease is held');
$gate->releaseWriteLease($lease);
expectLifecycleGate($gate->beginDrain(250), 'drain must succeed after writer completes');
expectLifecycleGate($gate->acquireWriteLease() === null, 'draining gate must reject new writers');
```

- [ ] **Step 2: Verify RED**

Run: `php Tests/check-module-lifecycle-gate.php`

Expected: failure because the compact gate is missing and current contract forbids lifecycle gating.

- [ ] **Step 3: Implement the gate and lifecycle hooks**

Use two files in `db`: `module-lifecycle.json` and `module-lifecycle.lock`. Write the marker through a temporary file and rename. Writers check state, take `LOCK_SH`, then recheck state. Drain writes `draining`, takes `LOCK_EX` with a condition-based deadline, and retains the exclusive resource until disable completes. `onBeforeModuleDisable()` is idempotent because Core invokes it twice; `onAfterModuleDisable()` writes `disabled`; `onAfterModuleEnable()` removes the marker before restarting workers.

At API boundaries, hold one lease around the complete callback. For a blocked job/worker request return a normal `PBXApiResult` with `success = false`, `httpCode = 503`, `messages['error'][] = 'ModuleLocalSpeechToText is temporarily unavailable during maintenance'`, and `data['retry_after'] = 5`. Admin mutations acquire the same lease and show a maintenance error without modifying data. Scanner checks the gate at the top of each iteration and sleeps without exiting when blocked.

- [ ] **Step 4: Verify GREEN and lifecycle compatibility**

Run: `php Tests/check-module-lifecycle-gate.php && php Tests/check-rest-api-contract.php && php Tests/run-all.php`

Expected: lock behavior, REST envelope, duplicate hook behavior, and all module contracts pass.

- [ ] **Step 5: Commit**

```bash
git add Lib/Services/ModuleLifecycleGate.php Lib/LocalSpeechToTextConf.php bin/WorkerLocalSpeechToTextJobs.php Lib/RestAPI/Jobs/Processor.php Lib/RestAPI/Workers/Processor.php App/Controllers/LocalSpeechToTextBaseController.php App/Controllers/ModuleLocalSpeechToTextController.php Tests/check-no-module-lifecycle-gating.php Tests/check-module-lifecycle-gate.php
git commit -m "fix: drain STT writers during module lifecycle"
```

### Task 5: Verified SQLite backup and setup restoration

**Files:**
- Create: `Lib/Services/ModuleDatabaseBackup.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Create: `Tests/check-module-database-backup.php`
- Create: `Tests/check-module-setup-rollback-contract.php`
- Modify: `scripts/package-module.sh`

**Interfaces:**
- Produces: `ModuleDatabaseBackup::snapshot(string $sourceDirectory, string $destinationDirectory): void`.
- Produces: `restore(string $snapshotDirectory, string $destinationDirectory): void`.
- Produces: `verify(string $databasePath): void`.
- Produces: STT `unInstallFiles(bool $keepSettings = false): bool` override for subsequent upgrades.

- [ ] **Step 1: Write failing backup tests using real SQLite WAL**

Create a temporary WAL database, insert committed rows, leave a reader open, snapshot it, and assert all committed rows restore while no sidecar files exist in the destination. Corrupt a snapshot and assert verification fails without touching the source. Assert restore uses a temporary destination and leaves the old database intact when verification fails.

```php
$backup = new ModuleDatabaseBackup();
$backup->snapshot($sourceDirectory, $snapshotDirectory);
expectDatabaseBackup(is_file($snapshotDirectory . '/module.db'), 'snapshot database missing');
expectDatabaseBackup(!is_file($snapshotDirectory . '/module.db-wal'), 'WAL sidecar must not be copied');
$backup->restore($snapshotDirectory, $restoreDirectory);
$restored = new SQLite3($restoreDirectory . '/module.db', SQLITE3_OPEN_READONLY);
expectDatabaseBackup((int)$restored->querySingle('SELECT COUNT(*) FROM sample') === 2, 'committed rows were not restored');
```

- [ ] **Step 2: Verify RED**

Run: `php Tests/check-module-database-backup.php`

Expected: failure because `ModuleDatabaseBackup` does not exist.

- [ ] **Step 3: Implement snapshot, restore, and setup rollback**

Use `SQLite3::backup()` for every `.db` file. Verify destination databases with `PRAGMA integrity_check`, normalize backup journal mode to `DELETE`, copy ordinary non-sidecar files, and publish the snapshot with rename. `restore()` builds a verified sibling directory before replacing destination database files.

Override `unInstallFiles()`: create a verified temporary snapshot before calling `parent::unInstallFiles()`, then replace `Backup/ModuleLocalSpeechToText/db` with the verified snapshot. On `installDB()`, snapshot the restored database before metadata/schema work; on exception recover transactions, close the adapter, restore the snapshot, and verify it before returning false. Remove the setup snapshot only after installation validation succeeds.

- [ ] **Step 4: Verify GREEN and package exclusions**

Run: `php Tests/check-module-database-backup.php && php Tests/check-module-setup-rollback-contract.php && ./scripts/package-module.sh /tmp/module-stt-backup-check`

Expected: backup/restore contracts pass and the archive contains no runtime snapshot, sidecar, or database file.

- [ ] **Step 5: Commit**

```bash
git add Lib/Services/ModuleDatabaseBackup.php Setup/PbxExtensionSetup.php Tests/check-module-database-backup.php Tests/check-module-setup-rollback-contract.php scripts/package-module.sh
git commit -m "fix: back up STT databases with SQLite snapshots"
```

### Task 6: Versioned idempotent schema migrations

**Files:**
- Create: `Lib/Services/ModuleSchemaMigration.php`
- Create: `Lib/Services/ModuleSchemaMigrator.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Create: `Tests/check-module-schema-migrator.php`
- Create: `Tests/fixtures/schema/legacy-current.sql`
- Modify: `Tests/Integration/check-call-transcript-orm-sqlite.php`

**Interfaces:**
- Produces: `ModuleSchemaMigration` value object with `version`, `name`, and `operation`.
- Produces: `ModuleSchemaMigrator::migrate(mixed $connection, callable $freshSchema): array`.
- Produces: ledger `m_STTSchemaMigration(version INTEGER PRIMARY KEY, name TEXT NOT NULL, checksum TEXT NOT NULL, applied_at INTEGER NOT NULL)`.

- [ ] **Step 1: Write failing migration tests**

Test a fresh database and the checked-in legacy fixture. Assert migrations apply in numeric order, ledger insertion shares the migration transaction, a thrown migration leaves no ledger row or schema fragment, checksum/name drift is rejected, rerun is a no-op, and interruption followed by rerun converges to the same schema and row counts.

```php
$migrator = new ModuleSchemaMigrator([
    new ModuleSchemaMigration(1, 'base', static fn($db) => $db->execute('CREATE TABLE sample(id INTEGER PRIMARY KEY)')),
    new ModuleSchemaMigration(2, 'value', static fn($db) => $db->execute("ALTER TABLE sample ADD COLUMN value TEXT NOT NULL DEFAULT ''")),
]);
$applied = $migrator->migrate($database, static function ($db): void {
    $db->execute('CREATE TABLE bootstrap(id INTEGER PRIMARY KEY)');
});
expectSchemaMigrator($applied === [1, 2], 'migrations must apply in order');
expectSchemaMigrator($migrator->migrate($database, static fn() => null) === [], 'rerun must be idempotent');
```

- [ ] **Step 2: Verify RED**

Run: `php Tests/check-module-schema-migrator.php`

Expected: failure because migration classes and ledger do not exist.

- [ ] **Step 3: Implement the ledger and move setup steps into migrations**

For a fresh database only, call `createSettingsTableByModelsAnnotations()` before applying the migration list. Existing databases skip generic annotation DDL entirely. Replace `parent::installDB()` with explicit migration, `registerNewModule()`, and `addToSidebar()` ordering.

Define ordered migrations for defaults/base validation, worker/job columns, durable retry state/import, result diagnostics/transcript event schema/backfill, call transcript schema/backfill, worker processing settings, and canonical indexes. Each operation reuses existing focused services or idempotent ensure methods extracted from `PbxExtensionSetup`; the ledger row is inserted before the helper commit. A failure is restored by Task 5's setup snapshot.

- [ ] **Step 4: Verify GREEN against fresh and legacy schemas**

Run: `php Tests/check-module-schema-migrator.php && php Tests/Integration/check-call-transcript-orm-sqlite.php && php Tests/run-all.php`

Expected: fresh/legacy upgrades converge, interruption is retryable, and all module contracts pass.

- [ ] **Step 5: Commit**

```bash
git add Lib/Services/ModuleSchemaMigration.php Lib/Services/ModuleSchemaMigrator.php Setup/PbxExtensionSetup.php Tests/check-module-schema-migrator.php Tests/fixtures/schema/legacy-current.sql Tests/Integration/check-call-transcript-orm-sqlite.php
git commit -m "feat: version STT database migrations"
```

### Task 7: Targeted metadata invalidation and canonical indexes

**Files:**
- Create: `Lib/Services/ModuleMetadataCache.php`
- Create: `Lib/Services/TranscriptEventIndexService.php`
- Modify: `Models/STTTranscriptEvent.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Create: `Tests/check-module-metadata-cache.php`
- Create: `Tests/check-transcript-event-indexes.php`

**Interfaces:**
- Produces: `ModuleMetadataCache::invalidate(): int` returning deleted Redis key count.
- Produces: `TranscriptEventIndexService::ensure(mixed $connection): void`.

- [ ] **Step 1: Write failing cache and index tests**

Use a Redis-client probe that records selected DB and keys, and assert only `_PH_METADATA:*Modules\\ModuleLocalSpeechToText\\Models\\*` keys are deleted and the original Redis DB selection is restored. Use real SQLite schemas representing fresh, duplicated production, and malformed index layouts. Assert the service retains one unique `event_key` coverage, one `(created_at,id)` index, one `result_id` index, is idempotent, and refuses to drop anything when required coverage cannot be verified.

```php
$service = new TranscriptEventIndexService();
$service->ensure($database);
$coverage = transcriptEventIndexCoverage($database);
expectTranscriptIndexes($coverage['event_key_unique'] === 1, 'event_key must have one unique coverage');
expectTranscriptIndexes($coverage['event_cursor'] === 1, 'cursor must have one coverage');
expectTranscriptIndexes($coverage['result_id'] === 1, 'result_id must have one coverage');
```

- [ ] **Step 2: Verify RED**

Run: `php Tests/check-module-metadata-cache.php && php Tests/check-transcript-event-indexes.php`

Expected: failure because both services are missing and duplicate annotation indexes remain.

- [ ] **Step 3: Implement targeted invalidation and semantic index cleanup**

Use Redis DB 2 `SCAN` with the module-model pattern and `DEL` matching keys only; restore DB 1 in `finally`. Reset Phalcon metadata and recreate annotations/connections after Redis cleanup. Remove `@Indexes` from `STTTranscriptEvent` so generic fresh-schema bootstrap cannot create the `i_*` set.

In the index service, inspect `PRAGMA index_list` and `PRAGMA index_info`. Prefer an existing SQLite auto-index for legacy unique coverage; otherwise create `m_STTTranscriptEvent_event_key`. Prefer/create the `m_*` cursor and result indexes, then drop equivalent redundant explicit indexes. Verify final semantic coverage before returning. Invoke metadata invalidation before migration schema inspection and after migration completion.

- [ ] **Step 4: Verify GREEN**

Run: `php Tests/check-module-metadata-cache.php && php Tests/check-transcript-event-indexes.php && php Tests/check-call-transcript-model-annotations-contract.php && php Tests/run-all.php`

Expected: targeted cache deletion, canonical coverage, annotation contract, and full suite pass.

- [ ] **Step 5: Commit**

```bash
git add Lib/Services/ModuleMetadataCache.php Lib/Services/TranscriptEventIndexService.php Models/STTTranscriptEvent.php Setup/PbxExtensionSetup.php Tests/check-module-metadata-cache.php Tests/check-transcript-event-indexes.php Tests/check-call-transcript-model-annotations-contract.php
git commit -m "fix: refresh STT metadata and remove duplicate indexes"
```

### Task 8: WAL policy after safe bridge installation

**Files:**
- Create: `Lib/Services/ModuleDatabasePragmas.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Modify: `Lib/LocalSpeechToTextConf.php`
- Create: `Tests/check-module-database-pragmas.php`
- Modify: `Tests/check-module-database-backup.php`

**Interfaces:**
- Produces: `ModuleDatabasePragmas::configureRuntime(mixed $connection): void`.
- Produces: `enableWalAfterVerifiedSetup(mixed $connection, string $databasePath): bool`.
- Produces: `checkpointAndNormalizeBackup(SQLite3 $database): void`.

- [ ] **Step 1: Write failing WAL capability tests**

Use real temporary SQLite databases and an injected filesystem-type resolver. Assert ext4/local APFS enables WAL after setup, NFS/SMB leaves DELETE, a failed journal-mode response leaves DELETE without failing setup, runtime configuration sets busy timeout and synchronous mode, and a backup normalized for downgrade is self-contained in DELETE mode.

```php
$pragmas = new ModuleDatabasePragmas(static fn(string $path): string => 'ext4');
expectDatabasePragmas(
    $pragmas->enableWalAfterVerifiedSetup($connection, $databasePath),
    'safe local filesystem must enable WAL'
);
expectDatabasePragmas(
    strtolower((string)$connection->fetchColumn('PRAGMA journal_mode')) === 'wal',
    'journal mode must be WAL after verified setup'
);
```

- [ ] **Step 2: Verify RED**

Run: `php Tests/check-module-database-pragmas.php`

Expected: failure because `ModuleDatabasePragmas` does not exist.

- [ ] **Step 3: Implement safe WAL activation**

Resolve filesystem type from `stat -f -c %T` on Linux and keep a deny-list for `nfs`, `nfs4`, `cifs`, `smb`, `fuse.sshfs`, `9p`, and unknown results. After migrations, metadata refresh, backup-path validation, and database integrity validation, set `busy_timeout`, request `journal_mode=WAL`, verify the returned value, and then set `synchronous=NORMAL`. If transition fails, request `journal_mode=DELETE`, log a warning, and continue safely. On lifecycle drain/uninstall, checkpoint and create a DELETE-mode backup destination.

- [ ] **Step 4: Verify GREEN and WAL backup compatibility**

Run: `php Tests/check-module-database-pragmas.php && php Tests/check-module-database-backup.php && php Tests/run-all.php`

Expected: filesystem gating and WAL snapshot/restore pass with no contract regression.

- [ ] **Step 5: Commit**

```bash
git add Lib/Services/ModuleDatabasePragmas.php Setup/PbxExtensionSetup.php Lib/LocalSpeechToTextConf.php Tests/check-module-database-pragmas.php Tests/check-module-database-backup.php
git commit -m "feat: enable WAL after verified STT setup"
```

### Task 9: PBX integration, reinstall rehearsal, and release package

**Files:**
- Create: `Tests/Integration/check-module-upgrade-sqlite.php`
- Modify: `Tests/run-all.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: all services from Tasks 1-8.
- Produces: a production-shaped upgrade rehearsal and release documentation for the short STT maintenance pause.

- [ ] **Step 1: Write the failing end-to-end upgrade rehearsal**

Build a legacy database fixture with jobs, results, workers, transcript events, retry state, call transcripts, duplicate indexes, and no migration ledger. Run setup migration, reinstall the same version, inject a migration failure and restore, and compare row counts, content hashes, lease fields, unique event keys, integrity check, migration ledger, and journal mode after every phase.

```php
$before = snapshotUpgradeState($databasePath);
runModuleMigration($databasePath);
$after = snapshotUpgradeState($databasePath);
expectUpgradeIntegration($after['jobs'] === $before['jobs'], 'job count changed');
expectUpgradeIntegration($after['results'] === $before['results'], 'result count changed');
expectUpgradeIntegration($after['lease_hash'] === $before['lease_hash'], 'lease ownership changed');
expectUpgradeIntegration($after['integrity'] === 'ok', 'upgraded database failed integrity check');
runModuleMigration($databasePath);
expectUpgradeIntegration(snapshotUpgradeState($databasePath) === $after, 'same-version reinstall is not idempotent');
```

- [ ] **Step 2: Verify RED before wiring the integration path**

Run: `php Tests/Integration/check-module-upgrade-sqlite.php`

Expected: failure until every migration, backup, metadata, index, and WAL component is connected through setup.

- [ ] **Step 3: Complete integration wiring and operational documentation**

Add the integration test to the appropriate CI-capable runner when Phalcon is available, otherwise keep a clear skip with a non-Phalcon SQLite contract in the main runner. Document that upgrade pauses new STT work, preserves queued/processing jobs, may return retryable 503 during drain, and automatically resumes after enable. Document the first bridge-upgrade limitation without claiming old-code rollback.

- [ ] **Step 4: Run full verification**

Run:

```bash
php Tests/run-all.php
php Tests/Integration/check-module-upgrade-sqlite.php
./scripts/package-module.sh /tmp/module-stt-db-resilience
unzip -l /tmp/module-stt-db-resilience/ModuleLocalSpeechToText.zip
```

Expected: all tests pass; package validation succeeds; archive contains no `Tests`, `docs`, database, sidecar, lifecycle runtime, setup snapshot, logs, or secrets.

- [ ] **Step 5: Rehearse against a copy of the PBX database**

Copy the PBX `module.db` to an isolated temporary directory, never modify the live database, run the upgrade migrator and integrity/logical consistency checks against the copy, then run it a second time to prove idempotency. Compare counts for jobs, results, transcript events, call transcripts, revisions, parts, workers, and settings before and after.

Expected: counts and ownership hashes are preserved, duplicate index coverage is removed, migration ledger is complete, journal mode is WAL on ext4, and the second run makes no schema/data changes.

- [ ] **Step 6: Commit**

```bash
git add Tests/Integration/check-module-upgrade-sqlite.php Tests/run-all.php README.md
git commit -m "test: rehearse smooth STT database upgrades"
```
