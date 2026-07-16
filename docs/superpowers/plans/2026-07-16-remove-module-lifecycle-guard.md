# Remove Module Lifecycle Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove custom module lifecycle coordination so the CDR scanner and administrative writes cannot be blocked by stale module-state markers.

**Architecture:** Restore the runtime to direct scanner and controller operations while retaining standard MikoPBX after-enable worker refresh. Replace the guard with a best-effort, one-time setup cleanup for three exact legacy files and enforce the absence of runtime gating with a contract test.

**Tech Stack:** PHP 8.3, Phalcon/MikoPBX module APIs, shell packaging checks.

## Global Constraints

- Preserve CDR selection, cursors, backfill, retry scheduling, job leases, and worker ownership behavior.
- Preserve API-key transaction, rollback, compensation, and cache-refresh ordering.
- Preserve the uncommitted `module.json` version change and do not include it in implementation commits.
- Do not deploy, push, restart PBX services, or change versions without a separate request.

---

### Task 1: Add the no-lifecycle-gating contract

**Files:**
- Create: `Tests/check-no-module-lifecycle-gating.php`
- Delete after RED: `Tests/check-module-lifecycle-contract.php`
- Delete after RED: `Tests/check-module-lifecycle-guard.php`
- Delete after RED: `Tests/check-module-lifecycle-hooks.php`
- Modify: `Tests/check-admin-settings-write-contract.php`

**Interfaces:**
- Consumes: production PHP source files as contract fixtures.
- Produces: a regression gate forbidding runtime lifecycle coordination while permitting exact legacy-file cleanup in setup.

- [ ] **Step 1: Write the failing contract**

Create a PHP contract that asserts `Lib/Services/ModuleLifecycleGuard.php` is absent; production runtime files contain none of `ModuleLifecycleGuard`, `acquireWriteLease`, `releaseWriteLease`, `withModuleWriteLease`, or `Local speech-to-text module is changing state`; scanner source still invokes `createJobsFromCompletedCdr()`; config retains `onAfterModuleEnable`, provider refresh, and worker restart; setup lists the three exact obsolete filenames and contains no guard bootstrap.

- [ ] **Step 2: Run the contract and verify RED**

Run: `php Tests/check-no-module-lifecycle-gating.php`

Expected: failure because `ModuleLifecycleGuard.php` and runtime references still exist.

- [ ] **Step 3: Update the administrative action contract**

Change `check-admin-settings-write-contract.php` so settings, retry, API-key creation, and API-key deletion all reject `withModuleWriteLease` and still assert their intended model/service operations.

---

### Task 2: Remove lifecycle coordination and preserve behavior

**Files:**
- Delete: `Lib/Services/ModuleLifecycleGuard.php`
- Modify: `Lib/LocalSpeechToTextConf.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Modify: `bin/WorkerLocalSpeechToTextJobs.php`
- Modify: `App/Controllers/LocalSpeechToTextBaseController.php`
- Modify: `App/Controllers/ModuleLocalSpeechToTextController.php`
- Modify: `scripts/package-module.sh`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: existing scanner loop, `PBXConfModulesProvider`, `Processes`, `WorkerApiKeyService`, `STTJob`, and setup logger.
- Produces: direct runtime operations and `cleanupLegacyLifecycleFiles(): void` in setup.

- [ ] **Step 1: Restore standard module config behavior**

Keep `getModuleWorkers()` and an `onAfterModuleEnable()` that calls:

```php
PBXConfModulesProvider::recreateModulesProvider();
Processes::restartAllWorkers(true);
```

Remove the custom before/after disable hooks, before-enable hook, guard property, reconciliation destructor, and transition helpers.

- [ ] **Step 2: Remove scanner gating**

Delete the guard import, initialization, lease acquisition, early break, and lease release. Keep one `try/catch` per scan iteration around CDR table initialization, stale-worker maintenance, retry processing, and `createJobsFromCompletedCdr()`.

- [ ] **Step 3: Inline administrative writes**

Remove `withModuleWriteLease()` from the base controller. Invoke `STTJob::requeueErrors()` directly. Inline API-key bind/delete bodies at their current positions without changing Core transaction commit/rollback or cleanup order.

- [ ] **Step 4: Add best-effort legacy cleanup**

After `parent::installDB()` succeeds, call `cleanupLegacyLifecycleFiles()`. For each exact obsolete filename, unlink only a regular file or symlink under `$this->moduleDir . '/db'`; log a warning when unlink fails and never fail installation because runtime no longer reads the files.

- [ ] **Step 5: Remove obsolete source and packaging references**

Delete the guard class and obsolete tests. Remove `.module-lifecycle` patterns from `.gitignore` and package validation while retaining all `module.db` exclusions.

- [ ] **Step 6: Verify GREEN**

Run:

```bash
php Tests/check-no-module-lifecycle-gating.php
php Tests/check-admin-settings-write-contract.php
php Tests/check-worker-api-key-reconciliation.php
php Tests/check-queue-lifecycle-contract.php
```

Expected: all checks pass.

---

### Task 3: Full verification and implementation commit

**Files:**
- Verify all changed PHP and shell/package files.
- Commit all implementation and test changes except `module.json`.

**Interfaces:**
- Consumes: completed no-gating implementation.
- Produces: tested module source and a valid installation archive.

- [ ] **Step 1: Run the full contract suite**

Run: `php Tests/run-all.php`

Expected: every discovered contract passes with no warnings or failures.

- [ ] **Step 2: Run PHP 8.3 syntax verification**

Run the full suite and `php -l` for every PHP file in the `php:8.3-cli` container with `pcntl` installed.

Expected: suite passes and every PHP file reports valid syntax.

- [ ] **Step 3: Build and verify the package**

Run: `scripts/package-module.sh <temporary-directory>` followed by `unzip -t` on the generated ZIP.

Expected: package creation succeeds, archive integrity passes, and no tests, docs, runtime databases, or lifecycle coordination files are included.

- [ ] **Step 4: Request queue resilience review**

Ask `queue-resilience-reviewer` to inspect the final diff for scanner startup, queue behavior, retry ordering, and unintended CDR changes. Apply only findings supported by source evidence and re-run affected checks.

- [ ] **Step 5: Commit the implementation**

Stage the explicit implementation/test paths, scan the staged diff for likely secrets, verify `module.json` is not staged, and commit with:

```bash
git commit -m "fix: remove module lifecycle gating"
```
