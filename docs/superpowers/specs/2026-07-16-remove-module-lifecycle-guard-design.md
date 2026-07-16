# Remove Module Lifecycle Guard Design

## Goal

Remove the custom lifecycle coordination mechanism that can block the local STT scanner, administrative writes, and module runtime while MikoPBX considers the module enabled.

## Scope

- Delete `ModuleLifecycleGuard` and all runtime references to it.
- Remove lifecycle leases from the CDR scanner, queue retry action, and worker API-key administration.
- Remove custom before-enable, before-disable, and after-disable coordination hooks.
- Keep the original `onAfterModuleEnable()` behavior that refreshes the module provider and restarts Core workers.
- Remove lifecycle-specific tests and replace them with a contract that prevents lifecycle gating from returning.
- Remove lifecycle-specific packaging rules after adding an upgrade cleanup for legacy coordination files.
- Preserve the existing uncommitted `module.json` version change; versioning is outside this change.

## Runtime Behavior

The CDR scanner starts and scans directly, without acquiring a module lifecycle lease. Queue retry and worker API-key operations execute their existing database and transaction logic directly. Worker REST endpoints remain available as established by the preceding REST fix.

Module enable continues to refresh `PBXConfModulesProvider` and restart Core workers. Disable behavior returns to the standard MikoPBX lifecycle without custom markers, mutexes, or advisory locks.

## Legacy Cleanup

During `installDB()`, after the parent database setup succeeds, the module removes these obsolete files from its own `db` directory when they are regular files or symlinks:

- `.module-lifecycle-state.json`
- `.module-lifecycle.lock`
- `.module-lifecycle-transition.lock`

Cleanup is best-effort and idempotent. Failure to remove an obsolete file is logged as a warning but does not block module installation or scanning, because no runtime code reads these files after this change.

## Controller Transactions

Removing `withModuleWriteLease()` must not change the API-key transaction boundaries:

- Create: begin Core transaction, create the Core API key, bind its ID in the module database, commit the Core transaction, then clear the validation cache best-effort.
- Delete: begin Core transaction, delete the Core API key, commit, unbind workers and remove the module binding, then clear the validation cache best-effort.
- Existing compensation and rollback paths remain intact.

## Tests

A replacement contract test will assert that production PHP files contain none of the removed coordination symbols or user-visible state-changing error text. It will also verify that:

- the scanner invokes its normal scan loop without a lifecycle lease;
- administrative queue and API-key actions do not use `withModuleWriteLease()`;
- setup contains only the explicit legacy-file cleanup and does not bootstrap a guard;
- `LocalSpeechToTextConf` retains worker registration and the standard after-enable refresh.

The obsolete guard and hook behavior tests will be deleted. The full module contract suite, PHP 8.3 syntax check, and package build/integrity check must pass.

## Rollout and Recovery

Installing the fixed package removes the stale marker left on affected PBX systems. Since the scanner no longer reads lifecycle state, it can resume on the next WorkerSafeScriptsCore launch even if legacy cleanup encounters a filesystem warning. No server-side manual marker deletion is required after the fixed package is installed.

## Non-Goals

- No changes to CDR selection, scan cursors, backfill, retry scheduling, job leases, or worker ownership checks.
- No version bump, deployment, branch push, or PBX restart unless separately requested.
- No removal of normal MikoPBX module enable behavior.
