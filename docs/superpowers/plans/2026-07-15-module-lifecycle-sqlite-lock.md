# Module Lifecycle SQLite Lock Implementation Plan

> **For agentic workers:** use test-driven development and verify each focused contract before proceeding.

**Goal:** Make module enable/disable deterministic while transcription activity is writing to SQLite.

**Architecture:** Correct the relation that turns Core's validation probe into a write, then coordinate all module runtime writers with a filesystem state marker and shared/exclusive advisory lock. Lifecycle hooks drain writers before Core's model scan and refresh providers after the persisted state changes.

**Tech Stack:** PHP 8.3, Phalcon/MikoPBX module hooks, SQLite, `flock`, standalone PHP contract tests.

## Global Constraints

- Keep compatibility with MikoPBX Core `>=2025.1.1`.
- Do not depend on Core's newer disabled-worker reaper.
- Treat both before-hooks as idempotent because Core calls them twice.
- When an operation spans both databases, always acquire the Core database writer before the module lifecycle lease.
- Leave changes uncommitted unless the user explicitly asks for a commit.

### Task 1: Regression tests

**Files:**
- Create: `Tests/check-module-lifecycle-guard.php`
- Create: `Tests/check-module-lifecycle-contract.php`

- Test shared-writer draining and exclusive timeout before production changes.
- Test transition, disabled, successful enable, and dead-owner recovery semantics.
- Test both sides of Core's persisted-state boundary, malformed markers, removed runtime paths, and the two-SQLite wait graph.
- Add source contracts for lifecycle hooks, guarded entrypoints, worker scan leases, and `Relation::NO_ACTION`.
- Run the focused tests and confirm RED.

### Task 2: Lifecycle guard

**Files:**
- Create: `Lib/Services/ModuleLifecycleGuard.php`

- Implement atomic state writes and robust state reads.
- Implement shared write leases with a post-lock state recheck.
- Implement bounded, idempotent exclusive transition acquisition.
- Implement explicit enable/disable completion and owned-transition abort.
- Reconcile orphaned transitions against the authoritative Core module flag and fail closed when it is unavailable.
- Separate trusted fail-closed bootstrap from normal runtime construction.
- Run the focused behavioral test and confirm GREEN.

### Task 3: Lifecycle hooks and relation correction

**Files:**
- Modify: `Lib/LocalSpeechToTextConf.php`
- Modify: `Models/STTResult.php`

- Add idempotent before-enable and before-disable hooks.
- Publish the transition, signal the registered scanner, then drain its in-flight write lease.
- Add after-disable provider refresh/restart and preserve the existing after-enable refresh.
- Abort only an incomplete transition from the config destructor.
- Bootstrap lifecycle coordination before setup schema migrations.
- Change the child-to-parent relation action to `NO_ACTION`.

### Task 4: Runtime writer integration

**Files:**
- Modify: `Lib/RestAPI/Jobs/Processor.php`
- Modify: `Lib/RestAPI/Workers/Processor.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Modify: `App/Controllers/LocalSpeechToTextBaseController.php`
- Modify: `App/Controllers/ModuleLocalSpeechToTextController.php`

- Hold one shared lease around every jobs/workers REST action.
- Hold one shared lease around each PBX scanner iteration and stop cleanly on lifecycle state.
- Wrap web UI mutations; skip opportunistic stale-worker writes during transition.
- Keep dashboard GETs read-only and serialize Core/module API-key writes with the global Core-DB-to-lease order.
- Return a stable HTTP 503/API error for rejected worker calls.

### Task 5: Verification and review

- Run both focused lifecycle tests.
- Run `php Tests/run-all.php`.
- Run PHP syntax checks over changed PHP files.
- Run `./scripts/package-module.sh` into `/tmp`.
- Review the final diff for unrelated changes and have an independent reviewer inspect concurrency/failure paths.
