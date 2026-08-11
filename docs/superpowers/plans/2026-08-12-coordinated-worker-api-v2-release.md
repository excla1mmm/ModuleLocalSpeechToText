# Coordinated Worker API v2 Breaking Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the resource-oriented worker API as one controlled breaking release across MikoPBX Core, both PBX modules, and both macOS worker applications, with real idempotent data migration, deterministic rejection of old clients, and a verified recovery/rollback procedure.

**Architecture:** Core `2026.3.23` first supplies safe backend response-header forwarding and string worker-UID authorization matching. ModuleLocalSpeechToText `1.45` and ModuleAISupervisor `1.73` then activate API v2, migrate legacy worker leases, and tombstone v1 routes. LocalSTTWorkerMac and AISupervisorWorkerMac `1.7` build `34` negotiate the v2 contract and resume with preserved identity/credentials/settings. Compatibility is intentionally one-version-at-a-time: neither side falls back.

**Tech Stack:** MikoPBX PHP/Phalcon Core, PHP extension modules with SQLite, Swift 6 macOS workers, PHPUnit/PHP contract tests/XCTest, curl-based isolated PBX verification, module and macOS release packaging.

## Global Constraints

- Release set is exact: Core `2026.3.23`, ModuleLocalSpeechToText `1.45`, ModuleAISupervisor `1.73`, LocalSTTWorkerMac `1.7` build `34`, AISupervisorWorkerMac `1.7` build `34`.
- Scope includes only those five artifacts. Windows and Python STT workers are excluded from code, migration, CI, test matrices, archives, and release notes.
- This is a breaking migration without backward compatibility: new modules reject v1 apps with 426; new apps reject old modules locally after contract 404/405; no client or server fallback exists.
- Preserve module API keys, worker UIDs, completed jobs/results/transcripts/AI evidence, failed jobs, attempts, AI partials/cache/voice analytics, settings, event cursors, and all unrelated rows.
- Preserve macOS Keychain entries, local settings, enabled intent, onboarding state, histories, language, and downloaded STT/AI/voice models.
- Requeue only active leases owned by legacy/missing workers, clear their ownership, and do not consume an attempt. Repeated module update/reconciliation must be a no-op for already migrated rows and active v2 leases.
- Do not log or put in URLs bearer keys, lease tokens, AI recording download tokens, transcripts, prompts, or raw model output.
- Preserve unrelated working-tree changes in every repository; stage only plan-task files.
- Do not tag, publish, upload, merge, or install on a production PBX until the release candidate matrix passes and the release owner explicitly authorizes publication/deployment.
- Implement each component using its dedicated plan, tests first, and task-sized commits.

---

### Task 1: Implement and validate the Core prerequisite

**Files:**
- Follow: `/Users/excla1m/Development/MikoPBX-develop/docs/superpowers/plans/2026-08-12-module-worker-api-v2-core-prerequisite.md`

**Interfaces:**
- Publishes: safe `PBXApiResult` response headers and immediate string-ID authorization matching.
- Blocks: both module releases until Core tests and integration smoke tests pass.

- [ ] **Step 1: Execute the Core plan completely**

Use its TDD tasks and commits. The response-header allowlist must remain exactly `Upgrade`, `Cache-Control`, and `Retry-After`; CR/LF-containing values are discarded.

- [ ] **Step 2: Verify Core in isolation**

```bash
cd /Users/excla1m/Development/MikoPBX-develop
vendor/bin/phpunit -c tests/Unit/phpunit.xml tests/Unit/PBXCoreREST/Http/BackendResponseHeaderFilterTest.php tests/Unit/PBXCoreREST/Lib/PBXApiResultResponseHeadersTest.php tests/Unit/PBXCoreREST/Controllers/BaseControllerResponseHeadersContractTest.php tests/Unit/PBXCoreREST/Services/ApiKeyPermissionCheckerPathAscendTest.php
git diff --check
```

- [ ] **Step 3: Record the Core gate**

Capture commit SHA, test output, resulting version `2026.3.23`, allowed/disallowed header behavior, and authorization tests in `docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md` in the STT module repository.

### Task 2: Implement both PBX module plans and validate migration rollback

**Files:**
- Follow: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/plans/2026-08-12-resource-oriented-worker-api-v2.md`
- Follow: `/Users/excla1m/Development/Extensions/ModuleAISupervisor/docs/superpowers/plans/2026-08-12-resource-oriented-worker-api-v2.md`
- Create: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md`

**Interfaces:**
- Publishes: the two version-2 resource APIs and deterministic legacy tombstones.
- Produces: transactional/idempotent migrations with preservation evidence.

- [ ] **Step 1: Execute the STT module plan completely**

Do not begin final packaging until its migration fixture passes first-run, repeated-run, forced-failure rollback, and active-v2-lease preservation cases.

- [ ] **Step 2: Execute the AI module plan completely**

Do not begin final packaging until its migration fixture proves partials, transcript cache, results, evidence, voice data, attempt counters, keys, and settings survive first-run/repeated-run/rollback cases.

- [ ] **Step 3: Run both complete module suites**

```bash
cd /Users/excla1m/Development/Extensions/ModuleLocalSpeechToText
php Tests/run-all.php
git diff --check
cd /Users/excla1m/Development/Extensions/ModuleAISupervisor
php Tests/run-all.php
cd react-app
npm test -- --run
npm run build
cd ..
git diff --check
```

- [ ] **Step 4: Record migration invariants**

Add before/after fixture counts and hashes, transaction rollback result, migration marker, repeat-run result, and active-v2 lease preservation to the release-evidence report.

### Task 3: Implement both macOS worker plans and validate in-place app updates

**Files:**
- Follow: `/Users/excla1m/Development/LocalSTTWorkerMac/docs/superpowers/plans/2026-08-12-worker-api-v2-breaking-migration.md`
- Follow: `/Users/excla1m/Development/AISupervisorWorkerMac/docs/superpowers/plans/2026-08-12-worker-api-v2-breaking-migration.md`
- Modify: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md`

**Interfaces:**
- Publishes: two v2-only macOS clients with compatible update-required states.
- Proves: no local user state or model artifact is reset during update.

- [ ] **Step 1: Execute the Local STT worker plan completely**

Require the request-capture tests, no-fallback test, reconnect pause test, local-state preservation test, full XCTest suite, shell contracts, and Release build.

- [ ] **Step 2: Execute the AI Supervisor worker plan completely**

Require the HTTP seam tests, request-capture tests, no-fallback test, reconnect pause test, settings/model preservation test, full XCTest suite, shell contracts, and Release build.

- [ ] **Step 3: Record preservation evidence**

Record seeded/restored UID, Keychain-reference presence without secret values, enabled intent, settings keys, history counts, and model sentinel hashes. Do not copy token values into the report.

### Task 4: Build immutable release candidates and create checksums

**Files:**
- Modify: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md`

**Interfaces:**
- Produces: one identified release candidate per component and SHA-256 checksums.

- [ ] **Step 1: Assert exact metadata before building**

```bash
rg -n '"version": "1\.45"|"min_pbx_version": "2026\.3\.23"' /Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/module.json
rg -n '"version": "1\.73"|"min_pbx_version": "2026\.3\.23"' /Users/excla1m/Development/Extensions/ModuleAISupervisor/module.json
rg -n 'MARKETING_VERSION = 1\.7|CURRENT_PROJECT_VERSION = 34' /Users/excla1m/Development/LocalSTTWorkerMac/LocalSTTWorker.xcodeproj/project.pbxproj
rg -n 'MARKETING_VERSION = 1\.7|CURRENT_PROJECT_VERSION = 34' /Users/excla1m/Development/AISupervisorWorkerMac/AISupervisorWorker.xcodeproj/project.pbxproj
```

Each module must have exactly one matched version/minimum pair; each Xcode project must have the expected pair in every build configuration.

- [ ] **Step 2: Build into unique temporary directories**

```bash
stt_module_rc="$(mktemp -d /tmp/stt-module-v2-rc.XXXXXX)"
ai_module_rc="$(mktemp -d /tmp/ai-module-v2-rc.XXXXXX)"
cd /Users/excla1m/Development/Extensions/ModuleLocalSpeechToText
./scripts/package-module.sh "$stt_module_rc"
cd /Users/excla1m/Development/Extensions/ModuleAISupervisor
./scripts/package-module.sh "$ai_module_rc"
```

Build both macOS Release targets using their plan commands and export/sign through the repositories' existing release workflow. Do not introduce a new signing identity or upload destination.

- [ ] **Step 3: Inspect contents and hash artifacts**

Use `unzip -l` for module archives and `codesign --verify --deep --strict` plus `spctl --assess --type execute` for exported apps. Run `shasum -a 256` over the four distributable artifacts and record filenames, sizes, checksums, source commit SHAs, and signing assessment in the report.

### Task 5: Prepare an isolated PBX with recoverable pre-update snapshots

**Files:**
- Modify: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md`

**Interfaces:**
- Produces: a disposable release-candidate PBX and verified pre-update restore points.

- [ ] **Step 1: Provision an isolated PBX**

Install the current production-equivalent Core and current module releases, register current macOS workers, and create STT/AI fixtures containing pending, active, failed, and completed work. Ensure the AI active job has saved partial/cache/evidence-related state before upgrade.

- [ ] **Step 2: Stop background claimers at a controlled boundary**

Stop both current worker apps only after the fixture jobs are in the intended states. Do not clear/retry the queues through old administrator actions.

- [ ] **Step 3: Create and verify snapshots**

Use the PBX environment's supported VM/container snapshot and module-database backup mechanism. Record snapshot identifiers, Core/module/app versions, database `PRAGMA integrity_check` results, table row counts, and SHA-256 checksums of the module database backup files. Restore a clone once and repeat `PRAGMA integrity_check` before using the snapshot as the rollback gate.

### Task 6: Install in the only supported order and verify each incompatibility boundary

**Files:**
- Modify: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md`

**Interfaces:**
- Defines: supported installation order `Core -> modules -> macOS apps`.
- Produces: proof that old clients cannot mutate new modules and new clients never call old modules.

- [ ] **Step 1: Update Core to 2026.3.23**

Verify PBX health and that the still-current modules/workers operate unchanged. Core itself does not activate API v2.

- [ ] **Step 2: Update ModuleLocalSpeechToText to 1.45**

Before updating its app, issue one real request from the installed v1 STT worker or replay its exact v1 request with its restricted key. Require HTTP 426, `worker_upgrade_required`, `Upgrade: MikoPBX-Worker-API/2`, and unchanged worker/job/result row counts except the intended install-time lease migration.

- [ ] **Step 3: Update ModuleAISupervisor to 1.73**

Repeat the v1-client 426 test. Verify the intended active AI lease is pending, attempts unchanged, ownership cleared, partial/cache/result/evidence/voice rows preserved, and migration marker complete.

- [ ] **Step 4: Install LocalSTTWorkerMac 1.7 build 34 in place**

Do not delete app support or Keychain data. Launch and verify contract negotiation, same UID registration with `api_version=2`, centralized settings, clean recording path, lease heartbeat/release, result/failure, and restored enabled intent.

- [ ] **Step 5: Install AISupervisorWorkerMac 1.7 build 34 in place**

Verify same UID and credentials, existing settings/models, lease reacquisition, partial reuse, voice analytics, valid final evidence, result/failure/release, and restored enabled intent.

- [ ] **Step 6: Prove the opposite mismatch on a restored clone**

On the verified pre-update clone, install each new app while leaving its corresponding old module. Require local `module_upgrade_required` after exactly one contract request, no legacy fallback request, and no module database mutation.

### Task 7: Run the full HTTP, migration, security, and observability matrix

**Files:**
- Modify: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md`

**Interfaces:**
- Produces: release acceptance evidence for every route class and migration invariant.

- [ ] **Step 1: Run the REST verification workflow for both modules**

Set isolated-environment variables without printing secrets:

```bash
printf 'Isolated PBX base URL (including scheme): '
read -r MIKOPBX_RC_BASE_URL
case "$MIKOPBX_RC_BASE_URL" in http://*|https://*) ;; *) exit 64 ;; esac
export MIKOPBX_RC_BASE_URL
read -r -s MIKOPBX_RC_ADMIN_TOKEN
export MIKOPBX_RC_ADMIN_TOKEN
```

Exercise the route matrices from both module plans with curl, storing redacted status/header/body summaries only.

- [ ] **Step 2: Verify worker-version failures are mutation-free**

For missing, empty, non-integer, `1`, and `3` version headers on every worker resource, require 426 and compare database snapshots before/after. `GET /worker-api-contract` remains the sole version-header exception.

- [ ] **Step 3: Verify administrator tombstones and permissions**

Every removed administrative action returns 410 with exact replacement and no mutation. Worker keys receive 403 for management CRUD and non-worker resources but can reach v1 tombstones to receive deterministic 426.

- [ ] **Step 4: Verify response/request secret handling**

Inspect proxy access logs, module logs, worker diagnostics, crash reports, and saved report data. Fail the release if bearer keys, lease/download tokens, credential query items, prompts, transcript text, or raw model output appear.

- [ ] **Step 5: Verify repeated update/restart behavior**

Re-run each module update/reconciliation, restart the PBX, relaunch both apps, and trigger network/wake events. Require no additional queue mutation, no retry storm, stable UID/key/settings/model state, and successful new leases.

### Task 8: Rehearse coordinated rollback and approve or reject the release

**Files:**
- Modify: `/Users/excla1m/Development/Extensions/ModuleLocalSpeechToText/docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md`

**Interfaces:**
- Produces: a tested all-component rollback procedure.
- Produces: an explicit release decision with no partial compatibility promise.

- [ ] **Step 1: Rehearse rollback from the upgraded isolated PBX**

Stop both 1.7 apps, restore the verified PBX/module database snapshot, restore the old Core/modules as one environment, reinstall the old macOS apps without deleting Keychain/settings/models, and start them. Verify old workers register and process fixture jobs.

- [ ] **Step 2: State the rollback rule**

Never roll back only one side and claim service continuity. A new app with an old module remains paused; an old app with a new module remains rejected. Emergency rollback is coordinated across Core/modules/apps plus the verified data snapshot.

- [ ] **Step 3: Review all release evidence**

Require green Core/module/worker suites, package checks, signing checks, all four compatibility combinations, migration preservation, repeated-run idempotency, authorization, secret scans, and rollback rehearsal. Any missing row is a release blocker.

- [ ] **Step 4: Commit the evidence report**

```bash
cd /Users/excla1m/Development/Extensions/ModuleLocalSpeechToText
git add docs/superpowers/reports/2026-08-12-worker-api-v2-release-evidence.md
git commit -m "docs: record worker API v2 release evidence"
```

- [ ] **Step 5: Hand release artifacts to the release owner**

Provide artifact filenames/checksums, exact installation order, expected interruption window between module and app updates, 426/410 operator guidance, preservation statement, and coordinated rollback steps. Publication or production installation remains a separate explicitly authorized action.
