# Call Transcript ORM Schema Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Repair call-transcript ORM persistence and migrate malformed SQLite schemas without losing aggregate history.

**Architecture:** Correct the four models' Phalcon annotations so MikoPBX Core's existing annotation updater performs the data-preserving rebuild. Keep module setup responsible for canonical and partial indexes. Protect the behavior with a CI-safe annotation contract and a MikoPBX-runtime Phalcon+SQLite integration test.

**Tech Stack:** PHP 8.3+, Phalcon 5.9.3, MikoPBX `UpdateDatabase`, SQLite, standalone PHP contract tests.

## Global Constraints

- Preserve all existing call-transcript rows, primary keys, supersession links, revisions, and event cursor IDs.
- Do not change worker payloads, queue semantics, or REST ownership checks.
- Permit empty strings only where the aggregate state intentionally uses them.
- Do not deploy to production or mutate leases/queue state as part of this code change.

---

### Task 1: Regression contracts

**Files:**
- Modify: `Tests/check-call-transcript-model-annotations-contract.php`
- Create: `Tests/Integration/check-call-transcript-orm-sqlite.php`

**Interfaces:**
- Consumes: the four production model source files and MikoPBX Core runtime services.
- Produces: a fast metadata contract and an isolated real ORM/schema migration check.

- [ ] **Step 1: Extend the static contract before production edits**

Require `type="text"` for mapped text properties; require `allow_empty_string=true, default=""` on the five aggregate fields; require matching numeric/state defaults; and assert that setup still recreates every canonical index.

- [ ] **Step 2: Add the real Phalcon+SQLite integration test**

The test must create the exact malformed `VARCHAR(0)` schema, insert a sentinel row, run `UpdateDatabase::createUpdateDbTableByAnnotations()` for a test subclass of the production model, verify `TEXT`/defaults/data preservation/idempotency, and save/clear a real ORM aggregate.

- [ ] **Step 3: Verify RED**

Run:

```bash
php Tests/check-call-transcript-model-annotations-contract.php
ssh -p 5265 root@172.16.33.103 \
  'MIKOPBX_CORE_ROOT=/usr/www MIKOPBX_MODULE_ROOT=/tmp/ModuleLocalSpeechToText-orm-fix php' \
  < Tests/Integration/check-call-transcript-orm-sqlite.php
```

Expected: the static contract rejects incomplete metadata; the runtime test reports the original five required fields or missing schema defaults.

### Task 2: Correct model metadata and migration source of truth

**Files:**
- Modify: `Models/STTCallTranscript.php`
- Modify: `Models/STTCallTranscriptPart.php`
- Modify: `Models/STTCallTranscriptRevision.php`
- Modify: `Models/STTCallTranscriptEvent.php`
- Modify: `Setup/PbxExtensionSetup.php`

**Interfaces:**
- Consumes: Phalcon annotation strategy and Core `UpdateDatabase` behavior.
- Produces: correct `TEXT`, nullability, empty-string permissions, and defaults for new and existing installations.

- [ ] **Step 1: Update all mapped string annotations**

Use `type="text"` for unbounded strings. Add `allow_empty_string=true, default=""` to the five intentionally empty aggregate fields. Add explicit defaults for existing aggregate/part state fields and keep required content fields strict.

- [ ] **Step 2: Align fallback DDL**

Keep `ensureCallTranscriptTables()` definitions consistent with annotations. Retain manual index creation because the current-part partial unique index cannot be represented by Phalcon annotations.

- [ ] **Step 3: Verify GREEN for focused tests**

Run the two Task 1 commands. Expected: both pass; the integration test reports preserved sentinel data, idempotent migration, and successful ORM save/update.

### Task 3: Full verification and review

**Files:**
- Review all changed files; no additional production files expected.

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces: verified source ready for an explicit deployment decision.

- [ ] **Step 1: Run all standalone tests**

```bash
php Tests/run-all.php
```

Expected: `All module contract tests passed`.

- [ ] **Step 2: Run syntax checks**

```bash
find Models Setup Tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Expected: every file reports no syntax errors.

- [ ] **Step 3: Build the installable package**

```bash
./scripts/package-module.sh /tmp/module-local-stt-orm-fix
```

Expected: package validation succeeds and a module ZIP is produced outside the repository.

- [ ] **Step 4: Review the final diff**

Confirm there are no worker/API/queue changes, no data-destructive SQL, and no unrelated edits. Do not deploy or release leases without a separate explicit instruction.

