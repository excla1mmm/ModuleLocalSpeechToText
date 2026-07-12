# Call Transcript ORM Schema Fix Design

## Context

The call-transcript models were deployed with incomplete Phalcon annotations. `PbxExtensionSetupBase::installDB()` created the four tables from those annotations before the module's manual `CREATE TABLE IF NOT EXISTS` statements ran. The resulting schema used `VARCHAR(0) NOT NULL` columns without defaults. On the first `STTCallTranscript::save()`, Phalcon rejected five intentionally empty aggregate fields and `submitResult` rolled back with HTTP 500.

The user explicitly approved the remediation direction on 2026-07-13: align ORM metadata with intentional empty values, migrate the existing schema, add a real Phalcon+SQLite model-save test, and verify one successful result before draining the queue. This document covers the code change only; production deployment and queue mutation remain separate operations.

## Goals

- Make the four call-transcript model annotations fully describe the intended SQLite schema.
- Permit empty strings only for aggregate fields whose domain state intentionally uses `''`.
- Cause the existing MikoPBX annotation-driven updater to rebuild malformed tables while preserving rows and primary keys.
- Keep the existing exact and partial indexes under module setup control.
- Add a standalone contract test that runs in normal CI and a real Phalcon 5.9.3 + SQLite integration test that runs in a MikoPBX runtime.

## Non-goals

- No changes to the macOS worker or the `submitResult` payload.
- No production deployment, lease release, or queue reset in this change.
- No unrelated refactoring of transcript aggregation or retry behavior.
- No module version bump or release packaging metadata change.

## Considered Approaches

### 1. Correct annotations and use the Core updater — selected

Declare unbounded strings as `type="text"`, declare schema defaults explicitly, and use `allow_empty_string=true` for the five aggregate fields that are valid when empty. `parent::installDB()` will detect the current `VARCHAR(0)`/missing-default mismatch and run Core's transactional full-copy migration. A focused `CallTranscriptSchemaService`, called immediately afterward by module setup, owns fallback DDL and recreates the canonical indexes, including the partial current-part index that Phalcon `@Indexes` cannot express.

This is the smallest fix, uses the framework's existing data-preserving migration path, and avoids a second schema engine.

### 2. Add a module-specific table rebuild service

This offers full SQL control and is easy to exercise with plain PDO, but duplicates `UpdateDatabase`, must correctly preserve four related tables and cursor IDs, and creates a second migration path that can drift from annotations.

### 3. Create the manual tables before `parent::installDB()`

This can fix new installations, but it does not by itself repair already deployed malformed tables and still leaves annotations inconsistent with runtime validation. It is therefore insufficient.

## Model Metadata Design

- All unbounded string columns in the four new models use `@Column(type="text", ...)` so the Core updater emits SQLite `TEXT`, not `VARCHAR(0)`.
- `current_content_hash`, `current_parts_hash`, `pending_content_hash`, `pending_parts_hash`, and `pending_payload` use `nullable=false`, `allow_empty_string=true`, and `default=""`.
- Numeric and non-empty state defaults that already exist in manual DDL are declared in annotations: aggregate revision/status/timers, part resolution/segments/current flag.
- Required identifiers, hashes, payloads, labels, and timestamps that are assigned before save remain strict and do not gain empty-string permission.
- Manual DDL remains as a fallback and index owner; its defaults must match the annotations.

## Migration and Data Safety

On module installation/update, the base setup examines every model before the module-specific setup runs. Corrected metadata makes the Core updater see type/default differences and perform a transaction-scoped table copy. The updater copies all same-named columns, including primary keys, so aggregate/part/revision/event relationships and event cursor IDs remain stable. It then removes non-annotated indexes; `CallTranscriptSchemaService::ensure()` immediately recreates the module's canonical unique, cursor, and partial indexes.

The integration test creates the exact malformed live schema for all four tables in `:memory:` SQLite, inserts a linked sentinel graph, runs the real Core `UpdateDatabase::createUpdateDbTableByAnnotations()` followed by `CallTranscriptSchemaService::ensure()`, and verifies rows, primary keys, supersession links, event cursors, corrected types/defaults, and exact indexes. Running the production sequence a second time must leave schema and data unchanged.

## Runtime Regression Test

The integration test loads the production `STTCallTranscript` model and MikoPBX Core under Phalcon 5.9.3, but overrides only model initialization to point it at an isolated in-memory connection. It then saves a new aggregate without assigning the five empty fields, updates them through non-empty and back to empty states, and reads the stored row back. This reproduces the original ORM validation boundary without touching the installed module database.

Host CI lacks `ext-phalcon`, so the fast static contract remains part of `Tests/run-all.php`; the real integration test lives under `Tests/Integration` and is executed on a MikoPBX runtime or compatible runner.

## Success Criteria

- The new static contract fails against the current incomplete annotations and passes after the fix.
- The real integration test fails with the five original `is required` messages before the fix.
- The real integration test passes after the fix on PHP 8.4 / Phalcon 5.9.3 / SQLite.
- The malformed schema migrates to `TEXT` with required defaults, preserves the sentinel row and primary key, and is idempotent.
- `php Tests/run-all.php`, PHP syntax checks, and module packaging complete successfully.
