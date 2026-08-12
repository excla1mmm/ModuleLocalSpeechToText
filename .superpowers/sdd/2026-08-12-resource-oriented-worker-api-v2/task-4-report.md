# Task 4 report — numeric Worker CRUD and restricted self-registration

## Outcome

Implemented the `/workers` v2 resource with numeric item identifiers, administrative CRUD, restricted idempotent self-registration, exact worker resource mapping, and terminal HTTP 426 legacy tombstones.

## RED / GREEN evidence

- RED: after adding `Tests/check-worker-crud-v2-contract.php`, `php Tests/check-worker-crud-v2-contract.php` exited 255 with `STTWorkerResourceService CRUD implementation is missing`.
- GREEN: `php Tests/check-worker-crud-v2-contract.php` prints `STT worker CRUD v2 behavior contract OK`.
- The first implementation run reached the final source assertion after all runtime behavior passed. That assertion incorrectly required an inline `id` literal even though production returned it through the shared formatter. The assertion was corrected to verify `STTWorkerResourceService::formatWorker()` and its numeric `id` mapping.
- Final access self-review added an administrator-bearer POST case. It produced a real RED (403 `worker_api_key_forbidden`) because the legacy registration domain treated every bearer as a restricted worker. The Processor now clears only internal worker-auth markers for CREATE callers it has already classified as non-restricted; the same test is GREEN at 200.

## Administrative / restricted access matrix

| Action | Administrator/session | Restricted configured worker key |
|---|---:|---:|
| `getList` | allowed, no worker-version header | 403 before lifecycle/service |
| `create` | allowed, no worker-version header | 200 only with API v2 header and valid own/exclusive binding |
| `getRecord` | allowed by numeric id | 403 before lifecycle/service |
| `update` | allowed by numeric id | 403 before lifecycle/service |
| `patch` | allowed by numeric id | 403 before lifecycle/service |
| `delete` | allowed by numeric id | 403 before lifecycle/service |

The test also proves restricted create returns 426 without the v2 header, rejects an API key already bound to another `worker_uid`, rejects any mismatched existing binding (including a stale/non-configured old binding), and performs no registration mutation on denial. Administrator bearer POST with a non-worker key remains allowed without a worker-version header.

## CRUD and idempotency

- `STTWorkerResourceService` supplies all required methods: `getList`, `create`, `getRecord`, `update`, `patch`, and `delete`.
- List filtering covers `status`, `worker_uid`, `api_version`, `app_version`, `limit`, and `offset`.
- Worker responses map `id`, `worker_uid`, `name`, `ip`, `status`, `model`, `api_key_id`, `api_version`, `app_version`, `compatible`, `last_seen`, `created_at`, and `updated_at`.
- Repeated POST with the same `worker_uid` returns the same numeric `id` and refreshes the existing worker.
- Registration validates `worker_uid` with `[A-Za-z0-9][A-Za-z0-9._-]{0,254}`, writes API version 2, `app_version`, and `updated_at`.
- Administrative PUT requires a full replacement of `name`, `model`, and `status`, while preserving `worker_uid` and `api_key_id`.
- PATCH accepts only `name`, `model`, and `status`; body `id`, immutable fields, timestamps, and unknown keys return 422.
- DELETE returns 409 / `worker_has_active_lease` for a processing job with a non-null lease. Successful delete removes only the worker row; historical job/result ownership rows and Core API keys are not changed.

## Routing and legacy tombstones

- Reflected Worker routes are:
  - `GET /workers getList`
  - `POST /workers create`
  - `GET /workers/{id} getRecord`
  - `PUT /workers/{id} update`
  - `PATCH /workers/{id} patch`
  - `DELETE /workers/{id} delete`
- Item `idPattern` is exactly `[0-9]+`.
- `register()` and `getProcessingSettings()` remain public custom controller stubs without `ApiOperation` or data schemas.
- Their action implementation classes were deleted.
- `Processor::callBack()` is a terminal match: both legacy arms directly return `LegacyRouteRejector::worker()` before lifecycle, database, model, registration, or settings work.

## Verification

Passing:

- `php Tests/check-worker-crud-v2-contract.php`
- `php Tests/check-rest-api-contract.php`
- `php Tests/check-worker-processing-settings-flag-contract.php`
- `php Tests/check-worker-api-v2-migration-sqlite.php`
- `php Tests/check-worker-api-v2-negotiation.php`
- `php Tests/check-worker-api-key-reconciliation.php`
- `php Tests/check-openapi-translations.php`
- `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php`
- `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php`
- Core permission rehearsal using `/Users/excla1m/Development/Core`: confirmed the Core `write` permission admits Worker collection/item reads and writes, making the Processor's second gate necessary.
- `php -l` for every changed PHP file: no syntax errors.
- `git diff --check`: clean.

Expected progressive failures outside Task 4:

- `php Tests/check-worker-api-v2-route-contract.php` exits 255 after reflecting all six correct Worker tuples because later Job Leases/Recordings/Results/Failures and Job CRUD tuples are not implemented yet; legacy Job actions still expose their prior operations.
- `php Tests/check-worker-api-v2-version-contract.php` exits 255 first on the later-task credential-bearing legacy `file_url` in `LocalSpeechToTextMain::formatJob()`.
- `php Tests/run-all.php` includes those two progressive failures plus:
  - a pre-existing upgrade-rehearsal expectation;
  - linked-worktree Core path resolution failures in OpenAPI/API-key permission tests;
  - the superseded legacy `workers:getProcessingSettings` endpoint contract.
  The Task-4 worker CRUD test is green within the aggregate run.

## Files

- Created `Lib/Services/STTWorkerResourceService.php`.
- Modified `Lib/LocalSpeechToTextMain.php`, `Models/STTWorker.php`, and Worker controller/processor/data structure.
- Deleted the two executable legacy Worker action classes.
- Created `Tests/check-worker-crud-v2-contract.php` and registered it in `Tests/run-all.php`.

## Self-review and concerns

- Access denials happen before lifecycle acquisition and service dispatch; the only pre-gate model reads are the binding checks required to authorize restricted create.
- Administrative CRUD is intentionally independent of the worker API-version header.
- Core API-key models/services are absent from `STTWorkerResourceService`; worker delete cannot rotate or revoke a Core credential.
- Compatibility-only `worker_id` and `poll_interval` parameter/example metadata remain in `DataStructure` for older OpenAPI consumers, while v2 runtime worker resource payloads use the numeric `id` and the listed resource fields.
- No later Job-resource files, Core files, translation catalogs, or unrelated changes were modified.

## Fix Round 1 — authorization and database race hardening

### Outcome

All four Important review findings were reproduced and fixed without changing Core. Worker authorization now uses positive caller provenance and live Core API-key state, `api_key_id` exclusivity is enforced at the module database boundary, worker delete and job claim mutually exclude orphaned processing ownership through atomic SQL predicates, and the exact Core `data['httpMethod']` field is treated as trusted envelope metadata rather than a public mutation field.

### Fresh RED evidence

- Real Core metadata: `check-worker-crud-v2-contract.php` failed with `Core's exact data[httpMethod] metadata must not invalidate PUT`.
- Revoked queued credential: after removing worker key 77 from the module authorization fixture, the same test failed with `Revoked queued worker credential must deny getList` because the Processor inferred administrator access.
- Core deletion/cache failure: a key still present in the module allow-list but absent from the live Core `ApiKeys` table failed the new assertion `A cached token must fail closed when its live Core API-key record has been deleted`.
- Database binding invariant: `check-worker-binding-integrity-sqlite.php` failed with `WorkerBindingIntegrityMigration implementation is missing`.
- Concurrent reservation: `check-worker-registration-concurrency-sqlite.php` failed with `WorkerRegistrationTransaction implementation is missing`.
- Delete/claim ordering: `check-worker-delete-claim-race-sqlite.php` failed with `STTWorker atomic deleteIfNoActiveLease implementation is missing`.
- Production migration registration guard: after changing the integration contract to require migration 12, it failed because the rehearsal did not yet contain the new count, ledger, history, or index assertions.

### Authorization matrix after the fix

| Caller provenance | Worker CRUD result | Worker version header |
|---|---|---|
| Admin session/internal worker envelope | full numeric CRUD | not required |
| JWT envelope with `user_name` and `session_id` | full numeric CRUD | not required |
| Live Core API key with `full_permissions=1` or explicit Workers `admin` permission | full numeric CRUD | not required |
| Current module worker key whose live Core row still has the exact minimal Worker permission map | only own idempotent `POST /workers` | v2 required |
| Revoked/removed/historical worker key, including a queued request after module authorization removal | every action 403 before lifecycle/domain calls | irrelevant |
| Cached token whose Core key row was deleted or changed away from the worker preset | every action 403 before lifecycle/domain calls | irrelevant |
| Unknown or generic `write` bearer key | every action 403 before lifecycle/domain calls | irrelevant |

Worker key IDs are retained in module-owned authorization history when created and are seeded during migration from the current/legacy settings plus every existing worker binding. Removal stops authorization but does not erase provenance. The Processor dispatches CREATE through `STTWorkerResourceService::create()`; administrator CREATE clears only the internal worker-auth markers after positive administrator classification.

### Binding exclusivity and idempotency

- Production migration 12, `exclusive worker API-key bindings`, is registered in the real setup migration list with checksum seed `stt-schema-012-exclusive-worker-api-key-bindings`.
- Upgrade databases receive partial unique index `i_m_STTWorker_api_key_id_v2_unique` only for non-null `api_key_id` rows whose `api_version = 2`; model annotations intentionally retain only the non-unique lookup index so they cannot recreate an all-version constraint.
- Existing duplicate v1 bindings are not guessed at, reassigned, nulled, or discarded. They migrate successfully with every numeric ID, UID, key binding, and v1/offline state preserved; runtime registration blocks the ambiguous key until explicit administrator reconciliation.
- Migration tests prove success, exact history seeding, idempotent rerun, duplicate rollback, and injected post-operation/framework-ledger rollback.
- Registration performs the binding preflight again inside a module transaction. The database unique index remains the final arbiter. A two-process WAL SQLite test releases two different `worker_uid` contenders for key 91 from the same post-preflight barrier and observes exactly `conflict` plus `ok`, with exactly one persisted row. The loser is mapped to HTTP 409 `worker_api_key_already_bound` and performs no mutation.
- Existing repeated POST by the same `worker_uid` remains idempotent and returns the same numeric worker ID.

### Delete/claim serialization

`STTWorker::deleteIfNoActiveLease()` is one conditional DELETE statement with a `NOT EXISTS` active-processing-lease predicate. `STTJob::claimNextForWorker()` now adds an `EXISTS` worker predicate to its existing compare-and-set claim UPDATE. SQLite serializes the two write statements:

- claim first: the active lease makes delete fail, and the resource service returns 409;
- delete first: the worker existence predicate makes the stale claim affect zero rows and retry/return no job;
- neither ordering can create a processing job that references a missing worker.

The adversarial SQLite test proves both orderings and checks for zero orphan processing rows. Historical job/result rows remain untouched by successful worker deletion.

### GREEN verification

Passing focused and related commands:

- `php Tests/check-worker-crud-v2-contract.php` — `STT worker CRUD v2 behavior contract OK`
- `php Tests/check-worker-binding-integrity-sqlite.php` — `STT worker binding integrity SQLite behavior OK`
- `php Tests/check-worker-registration-concurrency-sqlite.php` — `STT worker registration concurrency SQLite behavior OK`
- `php Tests/check-worker-delete-claim-race-sqlite.php` — `STT worker delete/claim race SQLite behavior OK`
- `php Tests/check-stt-job-lease-sqlite.php` — `STT job lease SQLite behavior OK`
- `php Tests/check-worker-api-v2-migration-sqlite.php` — migration 11 rollback/idempotency/preservation JSON emitted successfully
- `php Tests/check-worker-api-v2-negotiation.php` — `Worker API v2 negotiation contract OK`
- `php Tests/check-worker-api-key-reconciliation.php` — `Worker API-key reconciliation behavior OK`
- `php Tests/check-settings-normalization.php` — `Settings normalization contract OK`
- `php Tests/check-worker-processing-settings-flag-contract.php`, `check-worker-processing-settings-sqlite.php`, `check-worker-retry-overflow.php`, `check-worker-settings-next-job-contract.php`, `check-queue-lifecycle-contract.php`, `check-module-lifecycle-gate.php`, and `check-module-schema-migrator.php` all pass.
- `php Tests/check-rest-api-contract.php` — `REST API contract OK`
- Direct real-Core permission rehearsal against `/Users/excla1m/Development/Core` proves Core `write` still admits Worker GET/POST/DELETE, so the module Processor gate remains load-bearing.
- `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php` and `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php` both pass.
- `php Tests/check-module-upgrade-integration-contract.php` passes the production migration 12-13 registration guards. The production-shaped Phalcon rehearsal reports its established host skip because `ext-phalcon` is unavailable.

Plain aggregate status remains progressive rather than green. The Task 4 CRUD/concurrency/migration/lease tests all pass. Remaining failures are later Job-resource route/version work, the superseded legacy processing-settings endpoint contract, and two linked-worktree Core-path assumptions in OpenAPI/API-key permission tests; the latter permission behavior was exercised directly against the actual read-only Core checkout.

### Fix files and self-review

- Authorization/history: `WorkerRequestContext`, Workers `Processor`, `STTSetting`, and `WorkerApiKeyService` integration through the existing add/remove flow.
- Database integrity: `WorkerBindingIntegrityMigration`, migration 12 plus deterministic repair migration 13 setup registration, `STTWorker` annotation safety, and production upgrade registration contracts.
- Registration race: new `WorkerRegistrationTransaction` and `WorkerApiKeyBindingConflictException`; `LocalSpeechToTextMain::registerWorker()` maps conflicts to 409.
- Delete/claim race: atomic `STTWorker` delete plus the `STTJob` worker-existence claim predicate.
- Envelope compatibility: `STTWorkerResourceService` strips exact `httpMethod` while continuing to reject unknown public fields and body IDs.
- No Core source, later resource controller, translation, API-key rotation/deletion behavior, job/result history, or unrelated user change was modified.

Concern retained for release verification: run the production-shaped migration rehearsal in the project Phalcon/PBX environment. Host PHP cannot execute it, but the module-only SQLite migration, concurrency, rollback, and production registration guards are green.

## Fix Round 2 — preserve ambiguous legacy bindings

### Re-review finding and RED

Re-review found one Important defect: the first migration-12 implementation rejected any duplicate non-null `api_key_id`, although the v1 schema historically allowed one key to be stored on multiple worker UIDs and the canonical migration must preserve those rows.

The focused migration fixture was changed first to contain two offline v1 rows with distinct numeric IDs/UIDs and the same key. It now required successful migration, exact row preservation, history and v2 marker preservation, migration ledger completion, idempotency, and rejection only for duplicate v2 rows. Before the production change it failed at `api_key_id must have a partial unique v2-only database invariant`. The runtime CRUD test separately went RED when second-identity and ambiguous-key POST assertions were changed from 403 to the required clean 409.

### Resolution

- Migration 12 retains its already-recorded name and checksum seed, but its implementation no longer rejects duplicate v1 bindings.
- The obsolete unshipped `i_m_STTWorker_api_key_id_unique` index is removed transactionally. The canonical index is `i_m_STTWorker_api_key_id_v2_unique ON m_STTWorker(api_key_id) WHERE api_key_id IS NOT NULL AND api_version = 2`.
- Migration 13, `repair v2-only worker API-key binding index`, re-runs the idempotent integrity operation. A test database with the old v12 ledger checksum and obsolete index skips v12, applies v13, removes the obsolete index, and installs the v2-only definition without changing rows.
- The migration fixture proves duplicate legacy IDs, UIDs, key bindings, timestamps, empty app versions, `api_version=1`, and offline state remain byte-for-byte unchanged. History is `[41,42,77]`, the v2 marker remains `1`, ledger versions 12 and 13 are present, and rerun is a no-op.
- An attempted pair of v2 inserts with the same key fails at the database boundary. The existing two-process clean-key race remains exactly one `ok`, one `conflict`, and one persisted v2 row.
- Runtime self-registration treats any second UID for a key as HTTP 409 `worker_api_key_already_bound`. For an ambiguous legacy key, both historical UIDs and a new UID receive 409 before lifecycle/domain mutation. No worker row changes and registration is not dispatched.
- A single unambiguous legacy binding can idempotently POST its own UID and transitions the same numeric worker row to online API v2.

### Fix Round 2 verification

Passing before commit:

- `php Tests/check-worker-binding-integrity-sqlite.php`
- `php Tests/check-worker-crud-v2-contract.php`
- `php Tests/check-worker-registration-concurrency-sqlite.php`
- `php Tests/check-worker-registration-conflict-result.php`
- `php Tests/check-worker-api-v2-migration-sqlite.php`
- `php Tests/check-stt-job-lease-sqlite.php`
- `php Tests/check-worker-delete-claim-race-sqlite.php`
- `php Tests/check-worker-api-v2-negotiation.php`
- `php Tests/check-worker-api-key-reconciliation.php`
- `php Tests/check-settings-normalization.php`
- `php Tests/check-queue-lifecycle-contract.php`
- `php Tests/check-module-schema-migrator.php`
- `php Tests/check-module-upgrade-integration-contract.php`
- `php Tests/Integration/check-module-upgrade-sqlite.php` — established host skip: `ext-phalcon` unavailable
- changed-file `php -l`, `git diff --check`, and clean scoped status verification.

No Core source, credential rotation/deletion behavior, existing worker identity/binding data, later Job resource, or unrelated file was changed.
