# Module Lifecycle SQLite Lock Design

## Context

Disabling `ModuleLocalSpeechToText` can fail with `SQLSTATE[HY000]: General error: 5 database is locked` while transcription is active. MikoPBX starts its lifecycle validation transaction before calling the module's `onBeforeModuleDisable()` hook, propagates that transaction to the module database, and then invokes `beforeDelete()` for every module record. `STTResult` currently declares a child-to-parent `belongsTo(STTJob)` relation with `ACTION_CASCADE`; the validation probe therefore attempts to delete the parent job. If a REST result/heartbeat or the PBX scanner is already writing, SQLite must upgrade the probe's read transaction to a writer and reports an immediate lock-upgrade deadlock.

The module currently has no before-disable hook. Core stops the registered scanner only after the validation probe and state persistence, and old Core releases can retain module routes or respawn a cached module worker after disable.

## Goals

- Make lifecycle validation read-only by correcting the `STTResult` relation direction semantics.
- Stop accepting new module-database writes before Core scans module models.
- Wait for in-flight REST, web, and scanner writes to finish without polling SQLite.
- Make enable/disable hooks safe when Core invokes each before-hook twice.
- Keep disabled state visible to stale REST processes until the next successful enable.
- Keep install/update replacement windows fail-closed, including after the old module directory is removed.
- Refresh the module provider and worker supervisor after both enable and disable.

## Non-goals

- Do not terminate an external macOS transcription process; it should receive a temporary unavailable response and retry later.
- Do not wait for audio inference to complete; only the short request that persists a claim, heartbeat, error, or result must drain.
- Do not modify MikoPBX Core or rely on WAL/busy-timeout as the correctness mechanism.
- Do not change queue payloads or transcription ownership rules.

## Design

`ModuleLifecycleGuard` stores a small state marker and two advisory locks next to `db/module.db`, but never inside SQLite. A transition mutex serializes concurrent status requests on older Core versions. Runtime writers acquire a shared `flock` lease before touching module state and release it in `finally`. The lifecycle hook holds the transition mutex, atomically writes a transition marker, then acquires the exclusive writer lock with a bounded timeout. This ordering closes both races: a writer that started first is allowed to finish, while one that races with the marker rechecks state after acquiring its lease and backs out.

Lifecycle state has three meanings:

- no marker: module is enabled and writes are allowed;
- `transition`: writes are rejected while the owning lifecycle request is alive;
- `disabled`: writes remain rejected across processes until a successful enable clears the marker.

A transition records its owner PID plus a unique request token, the prior terminal state, and the Linux boot ID. A failure reported directly by a before-hook is known to be pre-commit and restores that prior state. The Core, however, persists `PbxExtensionModules.disabled` before sound-file work and the after-hook. Therefore the config destructor and dead-owner recovery query that authoritative Core flag before deciding whether an interrupted transition is open or disabled. If the flag cannot be read, recovery fails closed. Invalid JSON and schema-invalid markers also fail closed and require an explicit successful enable. Markers from another boot are ignored because no stale API or worker process can survive a reboot.

Runtime guards never create a missing runtime directory or coordination files. Only setup and lifecycle config paths may bootstrap them, and bootstrap first writes a persistent disabled marker. Setup does this before schema migration, so a cached process from the replaced module cannot reopen writes or recreate a deleted module tree.

The registered PBX scanner acquires one shared lease around each scan iteration. The jobs and workers REST processors acquire one lease around each action. Web UI actions that mutate module state use the same helper. The dashboard remains read-only: stale-worker maintenance stays in the scanner, and missing API-key records are displayed for explicit cleanup rather than being deleted during a GET.

API-key administration touches both the Core SQLite database and the module SQLite database. It follows one global order: begin and mutate the Core transaction, then acquire the module lease. Creation binds the module ID before committing the Core credential and compensates the binding if commit fails. Deletion commits credential revocation first, removes worker/module authorization under the lease, and invalidates the token cache best-effort. This prevents both the main-DB/lifecycle wait cycle and an orphaned live credential.

`onBeforeModuleDisable()` starts the transition, drains writers, and signals the scanner. `onAfterModuleDisable()` persists the disabled marker, recreates the dynamic module provider, and restarts Core workers so cached routes/worker lists are refreshed. Enable uses the same barrier to clean up stale processes; `onAfterModuleEnable()` clears the marker and refreshes providers/workers.

## Failure Semantics

- If exclusive acquisition times out, the before-hook returns `false`, records a clear message, removes only its own transition, and restores the state implied by that hook (`open` for disable, `disabled` for enable).
- If execution stops after Core commits but before the after-hook, destructor/dead-owner recovery follows the committed Core flag instead of the pre-transition marker.
- If disable succeeds, the persistent marker prevents an old API process from writing even before all Core workers have restarted.
- If a stale external worker calls an endpoint during transition/disabled state, it receives HTTP 503 with `module_unavailable`; its lease is never granted and the worker's existing reconnect policy backs off.
- Worker termination is best-effort after the write drain. Database safety comes from the marker plus lock, not from assuming `killall` is synchronous.

## Success Criteria

- The lifecycle guard proves writer-drain, two-database lock ordering, pre/post-commit recovery, corrupt-marker handling, uninstall safety, persistent disabled state, and idempotent hooks in standalone tests.
- The inverse `STTResult` relation uses `Relation::NO_ACTION`.
- Every normal runtime writer participates in the guard.
- All existing contract tests, PHP syntax checks, and package validation pass.
