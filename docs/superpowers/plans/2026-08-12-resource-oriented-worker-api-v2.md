# Local Speech to Text Resource-Oriented Worker API v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace ModuleLocalSpeechToText's action-style worker API with resource-oriented worker/job endpoints, perform an idempotent breaking database migration, and make every v1 route a non-mutating HTTP 426 tombstone.

**Architecture:** Keep proven transcription, ownership, lease, recording, and result logic in `LocalSpeechToTextMain` and expose it through focused REST resources. A shared contract/context layer validates worker API version 2 and safely extracts request metadata, while a versioned schema migration marks v1 workers incompatible and requeues only legacy leases. Management CRUD remains separate from the restricted worker protocol even where a legacy route shares the `/workers` or `/jobs` base path.

**Tech Stack:** PHP 8.3, Phalcon 5.8 models, MikoPBX REST v3 attributes and `PBXApiResult`, SQLite module database, Volt/Fomantic UI, executable PHP contract tests.

## Global Constraints

- Keep `min_pbx_version` exactly `2025.1.1`. MikoPBX Core is read-only/out of scope; do not create Core tasks, worktrees, branches, commits, tests, or release dependencies. Existing Core commit `361dd80b0` is not part of this plan.
- Release the module as `1.45`; worker API contract version is independently fixed at integer `2`.
- Preserve the `PBXApiResult` envelope, integer-millisecond transcript timestamps, stable `segment_id`, transcript event cursors, result payload shapes, and one-time API-key secrecy.
- Preserve `worker_uid`, `job_id`, `lease_token`, API-key binding, constant-time token comparison, retry limits, late heartbeat behavior, recording realpath/range/size/duration checks, and completed data.
- Every worker-only v2 request except `GET /worker-api-contract` must carry `X-MikoPBX-Worker-API-Version: 2`.
- `GET /job-recordings/{job_id}` receives `worker_uid` and `lease_token` only through `X-MikoPBX-Worker-UID` and `X-MikoPBX-Job-Lease-Token`; no token may appear in a URL, access log, error, or diagnostic message.
- Current Core already forwards incoming `X-MikoPBX-*` headers. It does not propagate arbitrary backend response headers from `PBXApiResult`: compatibility errors use only `httpCode=426`, `error_code=worker_upgrade_required`, and `required_worker_api_version=2`; do not add `Upgrade`, `Cache-Control`, `responseHeaders`, streaming/raw-response workarounds, or Core changes.
- A v1 worker route always returns 426 with `worker_upgrade_required` and never invokes old business logic. A removed administrative action route always returns 410 with `api_route_removed` and `replacement_path`.
- Worker item CRUD uses numeric `{id}`. `worker_uid` remains a stable unique field. Restricted worker keys may use only negotiation, their own idempotent `POST /workers` with `worker_uid` in the body, their caller-bound singleton processing settings, worker job-protocol resources, and legacy tombstones. Because current Core `write` permission also permits read, processors must block list/read/PUT/PATCH/DELETE and every management Jobs action before domain calls.
- Preserve existing unrelated working-tree changes; stage only files named by the active task.
- Keep implementer and reviewers sequential: complete the implementation first, then run the project-specific review gate in Task 12 one reviewer at a time and return findings to the implementer.
- Implement with tests first and commit after every task.

---

## Sequential SDD Gate (applies to every task)

- Dispatch a fresh implementer subagent for exactly one task with `fork_turns=none`; implementation subagents are always sequential, never parallel.
- The implementer follows TDD: add/run the failing task test, implement the minimum production change, run every task command to green, and create the task-scoped commit.
- After that commit, dispatch a separate task reviewer using the relevant project role. The reviewer must inspect the task diff and test evidence; it must not implement concurrently.
- Return every Critical or Important finding to the same implementer, require a scoped fix commit and fresh focused tests, then send the fix back to the reviewer. Repeat until Critical/Important findings are zero.
- Only then append `Task N COMPLETE — implementer <commit>; reviewer <role>; Critical/Important: 0` to `.superpowers/sdd/2026-08-12-resource-oriented-worker-api-v2/progress.md`.
- Do not dispatch the next task until that completion line exists. Final project and cross-project review gates below are additional to this per-task gate.

### Task 1: Lock the v2 route, version, and sensitive-header contracts in failing tests

**Files:**
- Create: `Tests/check-worker-api-v2-route-contract.php`
- Create: `Tests/check-worker-api-v2-version-contract.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces: one executable route matrix covering every new method/path and every legacy tombstone.
- Produces: source guards proving the version gate runs before business actions and recording URLs contain no credentials.

- [ ] **Step 1: Add the route matrix test**

Define the exact working route matrix:

```php
$v2 = [
    ['GET', '/worker-api-contract'],
    ['GET', '/workers'], ['POST', '/workers'], ['GET', '/workers/{id}'],
    ['PUT', '/workers/{id}'], ['PATCH', '/workers/{id}'], ['DELETE', '/workers/{id}'],
    ['GET', '/worker-processing-settings'],
    ['POST', '/job-leases'], ['PATCH', '/job-leases/{job_id}'], ['DELETE', '/job-leases/{job_id}'],
    ['GET', '/job-recordings/{job_id}'], ['PUT', '/job-results/{job_id}'], ['PUT', '/job-failures/{job_id}'],
    ['GET', '/jobs'], ['GET', '/jobs/{id}'], ['POST', '/jobs'], ['PATCH', '/jobs/{id}'],
    ['PATCH', '/jobs'], ['DELETE', '/jobs/{id}'], ['DELETE', '/jobs'],
];

$legacyWorker = [
    'workers:register', 'workers:getProcessingSettings', 'jobs:getNext',
    'jobs/{id}:download', 'jobs/{id}:submitResult', 'jobs/{id}:submitError',
    'jobs/{id}:heartbeat', 'jobs/{id}:release',
];
```

Reflect controller attributes and assert that each `$v2` operation has the exact HTTP method, every legacy route remains routable but has no `ApiOperation`, and no GET operation maps to lease creation or another mutation.

- [ ] **Step 2: Add the version/sensitive data source contract**

Require these exact symbols before implementation:

```php
$required = [
    'WorkerApiContract::API_VERSION',
    "X-MikoPBX-Worker-API-Version",
    "X-MikoPBX-Worker-UID",
    "X-MikoPBX-Job-Lease-Token",
    "worker_upgrade_required",
];
```

Scan PHP and Swift-facing OpenAPI examples and require every v2 `file_url` to begin exactly `/job-recordings/`. Fail if it contains a module/API prefix, `lease_token=`, `worker_uid=`, `:download`, or `/jobs/`.

- [ ] **Step 3: Register and run the tests**

Add both files to the explicit list in `Tests/run-all.php`, then run:

```bash
php Tests/check-worker-api-v2-route-contract.php
php Tests/check-worker-api-v2-version-contract.php
```

Expected: FAIL because the v2 resources and contract helper do not exist.

- [ ] **Step 4: Commit the red tests**

```bash
git add Tests/check-worker-api-v2-route-contract.php Tests/check-worker-api-v2-version-contract.php Tests/run-all.php
git commit -m "test: define STT worker API v2 contract"
```

### Task 2: Add the shared worker contract, request context, and rejection results

**Files:**
- Create: `Lib/Services/WorkerApiContract.php`
- Create: `Lib/RestAPI/Common/WorkerRequestContext.php`
- Create: `Lib/RestAPI/Common/LegacyRouteRejector.php`
- Create: `Lib/RestAPI/WorkerApiContract/Controller.php`
- Create: `Lib/RestAPI/WorkerApiContract/Processor.php`
- Create: `Lib/RestAPI/WorkerApiContract/DataStructure.php`
- Create: `Tests/check-worker-api-v2-negotiation.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces: `WorkerApiContract::API_VERSION = 2`, `MODULE_ID`, `VERSION_HEADER`, and `CAPABILITIES`.
- Produces: `WorkerApiContract::requireVersion(array $request): ?PBXApiResult`.
- Produces: `WorkerApiContract::contractResult(): PBXApiResult`.
- Produces: `WorkerApiContract::upgradeRequired(): PBXApiResult` with HTTP 426 and body fields `error_code=worker_upgrade_required`, `required_worker_api_version=2`.
- Produces: `WorkerRequestContext::data(array $request): array<string,mixed>` and `isRestrictedWorkerKey(array $data): bool`.
- Produces: `LegacyRouteRejector::worker(): PBXApiResult` and `removed(string $replacementPath): PBXApiResult`.

- [ ] **Step 1: Write failing negotiation tests**

Test the success shape and all version failures:

```php
$contract = WorkerApiContract::contractResult();
expectV2($contract->success === true, 'contract request must succeed');
expectV2($contract->data['api_version'] === 2, 'api_version must be integer 2');
expectV2($contract->data['module'] === 'ModuleLocalSpeechToText', 'module id changed');

foreach ([[], ['x-mikopbx-worker-api-version' => ''], ['x-mikopbx-worker-api-version' => 'abc'], ['x-mikopbx-worker-api-version' => '1']] as $headers) {
    $error = WorkerApiContract::requireVersion(['httpHeaders' => $headers]);
    expectV2($error?->httpCode === 426, 'invalid version must return 426');
    expectV2(($error?->data['error_code'] ?? '') === 'worker_upgrade_required', 'wrong error code');
    expectV2(($error?->data['required_worker_api_version'] ?? null) === 2, 'required version missing');
}
```

- [ ] **Step 2: Implement the immutable contract helper**

Use the exact constants:

```php
final class WorkerApiContract
{
    public const string CONTRACT = 'MikoPBX-Worker-API';
    public const int API_VERSION = 2;
    public const string MODULE_ID = 'ModuleLocalSpeechToText';
    public const string VERSION_HEADER = 'x-mikopbx-worker-api-version';
    public const array CAPABILITIES = [
        'worker-crud', 'worker-processing-settings', 'job-leases',
        'job-recordings', 'job-results', 'job-failures',
    ];
}
```

Read `module.json` from `dirname(__DIR__, 2) . '/module.json'`, require the current packaged `version` to be scalar, and return it dynamically. Task 11 owns the `module.json` release bump; only after Task 11 must the dynamically reported module version equal `1.45`. `requireVersion()` must inspect only the lower-case filtered header key and must run before a lifecycle write lease or model/service call.

Build errors with:

```php
$result->success = false;
$result->httpCode = 426;
$result->data = [
    'error_code' => 'worker_upgrade_required',
    'module' => self::MODULE_ID,
    'module_version' => self::moduleVersion(),
    'required_worker_api_version' => self::API_VERSION,
];
$result->messages['error'][] = 'Update the worker application to a release that supports worker API version 2';
```

- [ ] **Step 3: Implement request context without leaking headers**

`WorkerRequestContext::data()` must copy request `data`, set `_remote_addr`, `_auth_type`, `_api_key_id`, `_http_method`, and map only these headers:

```php
$data['_worker_uid_header'] = (string)($headers['x-mikopbx-worker-uid'] ?? '');
$data['_lease_token_header'] = (string)($headers['x-mikopbx-job-lease-token'] ?? '');
```

Before those assignments, discard non-scalar filtered-header values so arrays or objects cannot be stringified, warn, throw, or leak their contents. Likewise, `isRestrictedWorkerKey()` must normalize a non-scalar `_api_key_id` to integer `0` before consulting `STTSetting`.

`isRestrictedWorkerKey()` returns true only when `_auth_type === 'bearer_token'` and `STTSetting::isWorkerApiKeyIdAllowed((int)_api_key_id)` is true. Never include `_lease_token_header` in logs or error messages.

- [ ] **Step 4: Add the singleton REST resource and legacy result factory**

Map `GET /worker-api-contract` to `getList` as a collection-level singleton operation with tag `Local STT Workers`, authenticated security, and response schema containing `contract`, `api_version`, `module`, `module_version`, and `capabilities`. The `contract` value is exactly `WorkerApiContract::CONTRACT`.

Do not set `responseHeaders`. `LegacyRouteRejector::removed()` returns status 410, `error_code=api_route_removed`, and the exact replacement path in the body.

- [ ] **Step 5: Run and commit**

```bash
php Tests/check-worker-api-v2-negotiation.php
WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php
php Tests/check-rest-api-contract.php
php -l Lib/Services/WorkerApiContract.php
php -l Lib/RestAPI/Common/WorkerRequestContext.php
php -l Lib/RestAPI/Common/LegacyRouteRejector.php
php -l Lib/RestAPI/WorkerApiContract/Controller.php
php -l Lib/RestAPI/WorkerApiContract/Processor.php
php -l Lib/RestAPI/WorkerApiContract/DataStructure.php
php -l Tests/check-worker-api-v2-negotiation.php
php -l Tests/run-all.php
git diff --check
git add Lib/Services/WorkerApiContract.php Lib/RestAPI/Common Lib/RestAPI/WorkerApiContract Tests/check-worker-api-v2-negotiation.php Tests/run-all.php
git commit -m "feat: add STT worker API v2 negotiation"
```

Expected: the Task 2 negotiation test, version-source mutation mode, REST contract, syntax checks, and diff check pass. The plain version and route aggregates are progressive: after Task 2 they must reach the next unmet later-task contract (initially the legacy credential-bearing `file_url` and missing resource routes), and become fully green in their owning later tasks and the final suite. Do not expand Task 2 into `LocalSpeechToTextMain` or future resource processors merely to advance those aggregate failures.

### Task 3: Perform the real idempotent v1-to-v2 database migration

**Files:**
- Modify: `Models/STTWorker.php`
- Modify: `Models/STTSetting.php`
- Create: `Lib/Services/WorkerApiV2Migration.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Modify: `Tests/fixtures/schema/legacy-current.sql`
- Create: `Tests/check-worker-api-v2-migration-sqlite.php`
- Modify: `Tests/Integration/check-module-upgrade-sqlite.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Adds: `STTWorker::$api_version`, `$app_version`, `$updated_at`.
- Adds: `STTSetting::WORKER_API_CONTRACT_MIGRATION_V2 = 'worker_api_contract_migration_v2'`.
- Produces: schema migration version `11`, checksum seed `stt-schema-011-worker-api-v2`.
- Produces: `WorkerApiV2Migration::migrate(mixed $connection): void`; after marker `worker_api_contract_migration_v2=1`, data migration is a no-op. Permission rebuilding remains a separate idempotent module operation through the existing Core API.

- [ ] **Step 1: Build a failing legacy SQLite fixture test**

Create an isolated database containing:

```sql
INSERT INTO m_STTWorker(id, worker_uid, name, status, api_key_id, has_settings_changed, last_seen, created_at)
VALUES (1, 'legacy-worker', 'Legacy Mac', 'online', 41, 0, 1700000000, 1690000000);
INSERT INTO m_STTJob(id, call_id, file_path, status, worker_id, attempts, created_at, updated_at, started_at, lease_token, lease_expires_at, error_msg)
VALUES
  (10, 'active', '/tmp/active.wav', 'processing', 1, 1, 1690000000, 1700000000, 1700000000, 'secret-lease', 1800000000, ''),
  (11, 'done', '/tmp/done.wav', 'done', 1, 2, 1690000000, 1700000000, 1700000000, NULL, NULL, '');
```

Also seed an already-v2 worker with an active processing lease. After migration assert columns exist, worker 1 has `api_version=1`, `app_version=''`, `status='offline'`, `has_settings_changed=1`, job 10 is pending with cleared ownership/lease and unchanged attempts `1`, job 11 is byte-for-byte unchanged, the active v2 lease is byte-for-byte unchanged, key binding remains 41, and marker equals `1`.

- [ ] **Step 2: Run the migration test and verify failure**

```bash
php Tests/check-worker-api-v2-migration-sqlite.php
```

Expected: FAIL because migration 11 and the new columns do not exist.

- [ ] **Step 3: Add model fields and transactional migration 11**

Define fields with these annotations/defaults:

```php
/** @Column(type="integer", default="1", nullable=false) */
public ?int $api_version = 1;

/** @Column(type="string", nullable=false) */
public ?string $app_version = '';

/** @Column(type="integer", nullable=false) */
public ?string $updated_at = '0';
```

Migration SQL must execute in one explicit transaction through the existing `ModuleSchemaMigrator`. Check the marker first and return without mutating data when it is already `1`; otherwise execute:

```sql
UPDATE m_STTWorker
   SET api_version = 1, status = 'offline', has_settings_changed = 1,
       updated_at = CASE WHEN updated_at = 0 THEN CAST(strftime('%s','now') AS INTEGER) ELSE updated_at END
 WHERE api_version < 2;

UPDATE m_STTJob
   SET status = 'pending', worker_id = NULL, started_at = NULL,
       lease_token = NULL, lease_expires_at = NULL,
       updated_at = CAST(strftime('%s','now') AS INTEGER),
       error_msg = 'worker_api_migration'
 WHERE status = 'processing'
   AND (worker_id IS NULL OR worker_id IN (SELECT id FROM m_STTWorker WHERE api_version < 2));
```

Do not modify `attempts`, terminal jobs, results, transcripts, transcript events, or cursors. Insert/update the marker only after every schema/data statement succeeds, immediately before commit.

- [ ] **Step 4: Rebuild permissions without post-marker data reconciliation**

After the transaction commits, rebuild worker-key permissions idempotently using only module code and the existing Core API. Do not scan/requeue jobs after the marker. The migration WHERE clause must leave an active lease owned by an `api_version=2` worker untouched.

- [ ] **Step 5: Prove idempotency and rollback**

Inject an exception immediately before marker creation and assert the transaction rolls back schema and data, including worker status and lease ownership. Then migrate successfully, snapshot all module tables, run setup again, and assert the second state hash is identical. API-key ID/binding/key material stays unchanged while permissions are rebuilt.

Update `Integration/check-module-upgrade-sqlite.php` expected migration ledger to `[1,2,3,4,5,6,7,8,9,10,11]` and include new columns in worker hashes.

- [ ] **Step 6: Run and commit**

```bash
php Tests/check-worker-api-v2-migration-sqlite.php
php Tests/Integration/check-module-upgrade-sqlite.php
php Tests/check-module-setup-rollback-contract.php
git add Models/STTWorker.php Models/STTSetting.php Lib/Services/WorkerApiV2Migration.php Setup/PbxExtensionSetup.php Tests/fixtures/schema/legacy-current.sql Tests/check-worker-api-v2-migration-sqlite.php Tests/Integration/check-module-upgrade-sqlite.php Tests/run-all.php
git commit -m "feat: migrate STT workers to API v2"
```

Expected: all tests pass.

### Task 4: Implement numeric Worker CRUD and restricted idempotent POST registration

**Files:**
- Create: `Lib/Services/STTWorkerResourceService.php`
- Modify: `Lib/LocalSpeechToTextMain.php`
- Modify: `Models/STTWorker.php`
- Modify: `Lib/RestAPI/Workers/Controller.php`
- Modify: `Lib/RestAPI/Workers/Processor.php`
- Modify: `Lib/RestAPI/Workers/DataStructure.php`
- Delete: `Lib/RestAPI/Workers/Actions/RegisterAction.php`
- Delete: `Lib/RestAPI/Workers/Actions/GetProcessingSettingsAction.php`
- Create: `Tests/check-worker-crud-v2-contract.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces service methods: `getList(array): PBXApiResult`, `create(array): PBXApiResult`, `getRecord(int): PBXApiResult`, `update(int,array): PBXApiResult`, `patch(int,array): PBXApiResult`, `delete(int): PBXApiResult`.
- Preserves: `LocalSpeechToTextMain::registerWorker(array): PBXApiResult` as the idempotent POST upsert implementation.
- Worker response fields: `id`, `worker_uid`, `name`, `ip`, `status`, `model`, `api_key_id`, `api_version`, `app_version`, `compatible`, `last_seen`, `created_at`, `updated_at`.

- [ ] **Step 1: Write failing CRUD and access tests**

Cover list filters `status`, `worker_uid`, `api_version`, `app_version`, `limit`, `offset`; idempotent POST create/update by body `worker_uid`; same request returns the same numeric `id`; administrative detail/PUT/PATCH/DELETE use numeric IDs; detail 404; PATCH allow-list; active-lease DELETE 409; successful DELETE preserves job/result rows and clears no API key record.

Add a processor test table:

```php
$restricted = [
    'getList' => 403, 'create' => 200, 'getRecord' => 403, 'update' => 403,
    'patch' => 403, 'delete' => 403,
];
```

and prove restricted POST succeeds only when body `worker_uid` matches its existing/exclusive API-key binding. This denial must occur inside `Workers\Processor` before `STTWorkerResourceService`, because Core `write` permission includes read.

- [ ] **Step 2: Run the test and verify failure**

```bash
php Tests/check-worker-crud-v2-contract.php
```

Expected: FAIL because CRUD methods are missing.

- [ ] **Step 3: Implement the service and model mapping**

Use numeric `id` for every item lookup; expose `worker_uid` as a stable unique response/filter field. PATCH may change only `name`, `model`, and `status`; reject `id`, `worker_uid`, `api_key_id`, `api_version`, timestamps, and unknown keys with 422. DELETE checks:

```php
$active = STTJob::count([
    'conditions' => 'worker_id = :worker_id: AND status = :status: AND lease_token IS NOT NULL',
    'bind' => ['worker_id' => (int)$worker->id, 'status' => STTJob::STATUS_PROCESSING],
]);
```

Return `worker_has_active_lease` on conflict. On success delete only the worker row and leave Core `ApiKeys` untouched.

- [ ] **Step 4: Convert registration to idempotent POST**

Map:

```php
'GET' => ['getList', 'getRecord'],
'POST' => ['create'],
'PUT' => ['update'],
'PATCH' => ['patch'],
'DELETE' => ['delete'],
```

with numeric item pattern `[0-9]+`. For `POST`, call `WorkerApiContract::requireVersion()` before processor/domain mutation when the caller is a restricted worker key, validate body `worker_uid` with `[A-Za-z0-9][A-Za-z0-9._-]{0,254}`, enforce key binding, and call existing registration logic. Return numeric `id`; the macOS worker uses it only for runtime diagnostics. Extend registration/model writes with:

```php
$worker->api_version = WorkerApiContract::API_VERSION;
$worker->app_version = self::stringField($data, 'app_version');
$worker->updated_at = (string)time();
```

Administrative `PUT /workers/{id}` is a full replacement of allowed fields, not self-registration. Administrative management requests do not require the worker version header.

- [ ] **Step 5: Retain legacy methods only as undocumented tombstones**

Keep `register()` and `getProcessingSettings()` public stubs in the controller's custom mapping without `ApiOperation` or schemas. In the processor dispatch both directly to `LegacyRouteRejector::worker()` before lifecycle acquisition, model lookup, or registration/settings services.

- [ ] **Step 6: Run and commit**

```bash
php Tests/check-worker-crud-v2-contract.php
php Tests/check-worker-api-v2-route-contract.php
git add Lib/Services/STTWorkerResourceService.php Lib/LocalSpeechToTextMain.php Models/STTWorker.php Lib/RestAPI/Workers Tests/check-worker-crud-v2-contract.php Tests/run-all.php
git commit -m "feat: add STT worker resource CRUD"
```

Expected: PASS.

### Task 5: Split processing settings and the worker lease lifecycle into resources

**Files:**
- Create: `Lib/RestAPI/WorkerProcessingSettings/Controller.php`
- Create: `Lib/RestAPI/WorkerProcessingSettings/Processor.php`
- Move: `Lib/RestAPI/Workers/ProcessingSettingsResponseDataStructure.php` to `Lib/RestAPI/WorkerProcessingSettings/DataStructure.php`
- Create: `Lib/RestAPI/JobLeases/Controller.php`
- Create: `Lib/RestAPI/JobLeases/Processor.php`
- Create: `Lib/RestAPI/JobLeases/DataStructure.php`
- Modify: `Lib/LocalSpeechToTextMain.php`
- Modify: `Lib/RestAPI/Jobs/DataStructure.php`
- Create: `Tests/check-worker-job-leases-v2.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces: caller-bound singleton `GET /worker-processing-settings`.
- Produces: `POST /job-leases`, `PATCH /job-leases/{job_id}`, `DELETE /job-leases/{job_id}`.
- Consumes existing methods: `getWorkerProcessingSettings`, `getNextJob`, `heartbeatJob`, `releaseJob`.

- [ ] **Step 1: Write failing lifecycle tests**

Assert every operation rejects missing/wrong version with 426 before model changes. Prove POST claim moves exactly one pending job to processing; empty queue returns `success=true`, `job=null`; PATCH heartbeat preserves current idempotency; DELETE returns pending without incrementing attempts; wrong worker/key/lease remains 403/409.

- [ ] **Step 2: Run and confirm failure**

```bash
php Tests/check-worker-job-leases-v2.php
```

Expected: FAIL because the resource controllers do not exist.

- [ ] **Step 3: Implement the settings resource**

Require contract v2, read `X-MikoPBX-Worker-UID` from `WorkerRequestContext`, resolve the worker from that UID plus the restricted API-key binding, then call `getWorkerProcessingSettings()`. There is no item path. Preserve the current atomic clearing of `has_settings_changed`; do not read another worker's profile.

- [ ] **Step 4: Implement the lease resource**

Map actions exactly:

```php
enum JobLeaseAction: string
{
    case CREATE = 'create';
    case PATCH = 'patch';
    case DELETE = 'delete';
}
```

For create, pass body `worker_uid` to `getNextJob`. For patch/delete, inject route `id` into data and call heartbeat/release. Acquire the existing lifecycle write lease with `allowDuringDrain: false` only for create and `true` for patch/delete.

Change every claimed job's `file_url` to:

```php
'file_url' => '/job-recordings/' . (int)$job->id,
```

with no module/API prefix and no query string. The worker resolves this relative path against its configured PBX origin.

- [ ] **Step 5: Run queue regression tests and commit**

```bash
php Tests/check-worker-job-leases-v2.php
php Tests/check-stt-job-lease-sqlite.php
php Tests/check-queue-lifecycle-contract.php
php Tests/check-worker-settings-next-job-contract.php
git add Lib/RestAPI/WorkerProcessingSettings Lib/RestAPI/JobLeases Lib/RestAPI/Workers/ProcessingSettingsResponseDataStructure.php Lib/LocalSpeechToTextMain.php Lib/RestAPI/Jobs/DataStructure.php Tests/check-worker-job-leases-v2.php Tests/run-all.php
git commit -m "feat: expose STT worker lease resources"
```

Expected: PASS.

### Task 6: Split recording, result, and failure resources without weakening ownership

**Files:**
- Create: `Lib/RestAPI/JobRecordings/Controller.php`
- Create: `Lib/RestAPI/JobRecordings/Processor.php`
- Create: `Lib/RestAPI/JobResults/Controller.php`
- Create: `Lib/RestAPI/JobResults/Processor.php`
- Move: `Lib/RestAPI/Jobs/SubmitResultResponseDataStructure.php` to `Lib/RestAPI/JobResults/DataStructure.php`
- Create: `Lib/RestAPI/JobFailures/Controller.php`
- Create: `Lib/RestAPI/JobFailures/Processor.php`
- Move: `Lib/RestAPI/Jobs/SubmitErrorResponseDataStructure.php` to `Lib/RestAPI/JobFailures/DataStructure.php`
- Modify: `Lib/LocalSpeechToTextMain.php`
- Delete: `Lib/RestAPI/Jobs/Actions/DownloadAction.php`
- Delete: `Lib/RestAPI/Jobs/Actions/GetNextAction.php`
- Delete: `Lib/RestAPI/Jobs/Actions/SubmitResultAction.php`
- Delete: `Lib/RestAPI/Jobs/Actions/SubmitErrorAction.php`
- Create: `Tests/check-worker-job-artifacts-v2.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces: `GET /job-recordings/{job_id}`, `PUT /job-results/{job_id}`, `PUT /job-failures/{job_id}`.
- Consumes: exact v1 business methods `downloadJobFile`, `submitResult`, `submitError` after request normalization.

- [ ] **Step 1: Add failing artifact tests**

For recording, prove version/worker/lease headers are mandatory, query `lease_token` is ignored, wrong ownership returns the same existing errors, Range 206 works, and paths outside monitor root fail. For final result/failure, prove duplicate identical PUT is accepted idempotently, mismatched/stale lease fails, and transcript timestamps/technical info are unchanged.

- [ ] **Step 2: Run and confirm failure**

```bash
php Tests/check-worker-job-artifacts-v2.php
```

Expected: FAIL because the resources do not exist.

- [ ] **Step 3: Implement header-only recording access**

The processor must construct business data exactly as:

```php
$data = WorkerRequestContext::data($request);
$data['id'] = (int)($data['id'] ?? 0);
$data['worker_uid'] = (string)($data['_worker_uid_header'] ?? '');
$data['lease_token'] = (string)($data['_lease_token_header'] ?? '');
unset($data['_worker_uid_header'], $data['_lease_token_header']);
```

Require v2 before this mapping. Keep all existing file validation and stream response fields.

- [ ] **Step 4: Implement idempotent PUT processors**

Both processors require v2, inject numeric route ID, and delegate unchanged payloads to the current methods. Keep `worker_uid` and `lease_token` in JSON bodies. Do not translate PUT into POST internally or alter result schemas.

- [ ] **Step 5: Run security/quality regressions and commit**

```bash
php Tests/check-worker-job-artifacts-v2.php
php Tests/check-recording-duration-limit-contract.php
php Tests/check-transcript-time-normalizer.php
php Tests/check-technical-info-service.php
git add Lib/RestAPI/JobRecordings Lib/RestAPI/JobResults Lib/RestAPI/JobFailures Lib/RestAPI/Jobs/Actions Lib/LocalSpeechToTextMain.php Tests/check-worker-job-artifacts-v2.php Tests/run-all.php
git commit -m "feat: split STT job artifact resources"
```

Expected: PASS.

### Task 7: Turn `/jobs` into an administrative queue resource

**Files:**
- Create: `Lib/Services/STTJobManagementService.php`
- Modify: `Lib/RestAPI/Jobs/Controller.php`
- Modify: `Lib/RestAPI/Jobs/Processor.php`
- Modify: `Lib/RestAPI/Jobs/DataStructure.php`
- Delete: `Lib/RestAPI/Jobs/HeartbeatResponseDataStructure.php`
- Delete: `Lib/RestAPI/Jobs/ReleaseResponseDataStructure.php`
- Create: `Tests/check-management-jobs-v2.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces service methods: `getList`, `getRecord`, `create`, `patch`, `patchCollection`, `delete`, `deleteCollection`.
- POST input: required `call_id`, required validated `file_path`, optional integer `priority` 1..10.
- Collection PATCH input: non-empty integer `ids`, action `retry` or `reset`.
- Collection DELETE query: required non-empty `status` subset of `pending,error`; no DELETE body.

- [ ] **Step 1: Write failing management tests**

Cover pagination/filtering, safe recording path validation, item retry, bulk retry/reset, item delete, filtered collection delete, empty-filter rejection, terminal-result deletion rejection, and restricted worker-key denial for every management operation.

- [ ] **Step 2: Run and confirm failure**

```bash
php Tests/check-management-jobs-v2.php
```

Expected: FAIL because management CRUD is not implemented.

- [ ] **Step 3: Implement the management service**

Create only a pending job for a file under `Directories::AST_MONITOR_DIR` after `realpath` validation. Return 409 for duplicate `file_path`. PATCH may move `error` to `pending` while clearing lease/worker/finished/error and setting attempts to `0` only for explicit admin reset. DELETE must reject processing jobs and jobs with `STTResult`; it may remove pending/error rows only.

For collection DELETE, read `status` from query data and reject `''`, `all`, `*`, or unsupported states with 422.

- [ ] **Step 4: Repurpose the Jobs controller and keep v1 tombstones**

Use standard mappings:

```php
'GET' => ['getList', 'getRecord'], 'POST' => ['create'],
'PATCH' => ['patch', 'patchCollection'], 'DELETE' => ['delete', 'deleteCollection'],
```

Keep old custom names (`getNext`, `download`, `submitResult`, `submitError`, `heartbeat`, `release`) only in routing and dispatch them immediately to `LegacyRouteRejector::worker()`. These methods have no OpenAPI operation attributes.

- [ ] **Step 5: Run and commit**

```bash
php Tests/check-management-jobs-v2.php
php Tests/check-worker-api-v2-route-contract.php
php Tests/check-queue-lifecycle-contract.php
git add Lib/Services/STTJobManagementService.php Lib/RestAPI/Jobs Tests/check-management-jobs-v2.php Tests/run-all.php
git commit -m "feat: add STT management jobs resource"
```

Expected: PASS.

### Task 8: Rebuild API-key permissions and prove the authorization matrix

**Files:**
- Modify: `Lib/Services/WorkerApiKeyService.php`
- Modify: `Setup/PbxExtensionSetup.php`
- Modify: `Tests/check-worker-api-key-permissions.php`
- Modify: `Tests/check-worker-api-key-reconciliation.php`
- Create: `Tests/check-worker-api-v2-authorization-matrix.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces the exact `WORKER_ALLOWED_PATHS` map below.
- Preserves API-key IDs, hashes, displays, and worker bindings; changes only `full_permissions` and `allowed_paths`.

- [ ] **Step 1: Change tests to the exact v2 permission map**

```php
public const array WORKER_ALLOWED_PATHS = [
    '/api/v3/module-local-speech-to-text/worker-api-contract' => 'read',
    '/api/v3/module-local-speech-to-text/workers' => 'write',
    '/api/v3/module-local-speech-to-text/worker-processing-settings' => 'read',
    '/api/v3/module-local-speech-to-text/job-leases' => 'write',
    '/api/v3/module-local-speech-to-text/job-recordings' => 'read',
    '/api/v3/module-local-speech-to-text/job-results' => 'write',
    '/api/v3/module-local-speech-to-text/job-failures' => 'write',
    '/api/v3/module-local-speech-to-text/jobs' => 'write',
];
```

`/jobs` remains only so authenticated v1 clients reach 426 tombstones; the Jobs processor must return 403 to restricted keys for all standard CRUD actions.

- [ ] **Step 2: Run and verify old permission tests fail**

```bash
php Tests/check-worker-api-key-permissions.php
php Tests/check-worker-api-v2-authorization-matrix.php
```

Expected: FAIL against the v1 map.

- [ ] **Step 3: Update creation and reconciliation**

On every setup, for all stored worker API key IDs set `full_permissions='0'` and exact JSON `allowed_paths`, saving under the existing Core DB compensation/rollback behavior. Clear `TokenValidationService` cache only after successful reconciliation.

- [ ] **Step 4: Prove the complete matrix**

Test Core permission plus processor authorization for own/foreign UID, worker CRUD, management jobs, transcript reads, every protocol resource, and every tombstone. Also assert plaintext key material never appears after the original creation response.

- [ ] **Step 5: Run and commit**

```bash
php Tests/check-worker-api-key-permissions.php
php Tests/check-worker-api-key-reconciliation.php
php Tests/check-worker-api-v2-authorization-matrix.php
git add Lib/Services/WorkerApiKeyService.php Setup/PbxExtensionSetup.php Tests/check-worker-api-key-permissions.php Tests/check-worker-api-key-reconciliation.php Tests/check-worker-api-v2-authorization-matrix.php Tests/run-all.php
git commit -m "feat: restrict STT worker API v2 permissions"
```

Expected: PASS.

### Task 9: Separate OpenAPI tags and hide tombstones

**Files:**
- Modify: every `Lib/RestAPI/*/Controller.php`
- Modify: `Tests/check-openapi-contract.php`
- Modify: `Tests/check-openapi-translations.php`
- Modify: `Messages/en.php`
- Modify: `Messages/ru.php`
- Modify: all other `Messages/*.php`

**Interfaces:**
- Produces exact tags: `Local STT Workers`, `Local STT Jobs`, `Local STT Recordings`, `Local STT Transcripts`, `Local STT Call Transcripts`.
- Produces translated keys: `rest_tag_LocalSTTWorkers`, `rest_tag_LocalSTTJobs`, `rest_tag_LocalSTTRecordings`, `rest_tag_LocalSTTTranscripts`, `rest_tag_LocalSTTCallTranscripts`.

- [ ] **Step 1: Replace the single-tag test with an exact controller map**

```php
$expectedTags = [
    WorkersController::class => ['Local STT Workers'],
    WorkerApiContractController::class => ['Local STT Workers'],
    WorkerProcessingSettingsController::class => ['Local STT Workers'],
    JobsController::class => ['Local STT Jobs'],
    JobLeasesController::class => ['Local STT Jobs'],
    JobResultsController::class => ['Local STT Jobs'],
    JobFailuresController::class => ['Local STT Jobs'],
    JobRecordingsController::class => ['Local STT Recordings'],
    TranscriptsController::class => ['Local STT Transcripts'],
    TranscriptEventsController::class => ['Local STT Transcripts'],
    CallTranscriptsController::class => ['Local STT Call Transcripts'],
    CallTranscriptEventsController::class => ['Local STT Call Transcripts'],
];
```

Also assert no generated OpenAPI path contains `:register`, `:getNext`, `:download`, `:submit`, `:heartbeat`, or `:release`.

- [ ] **Step 2: Run and verify the shared-tag assertion fails**

```bash
php Tests/check-openapi-contract.php
php Tests/check-openapi-translations.php
```

Expected: FAIL until tags and translations change.

- [ ] **Step 3: Apply tags and translations**

Use English labels `STT Workers`, `STT Jobs`, `STT Recordings`, `STT Transcripts`, `STT Call Transcripts`; Russian labels `STT-воркеры`, `Задания STT`, `Записи STT`, `Транскрипции STT`, `Транскрипции звонков STT`. Use the module translation workflow to add semantically equivalent values to every other locale; do not copy English into non-English catalogs.

- [ ] **Step 4: Run and commit**

```bash
php Tests/check-openapi-contract.php
php Tests/check-openapi-translations.php
git add Lib/RestAPI Tests/check-openapi-contract.php Tests/check-openapi-translations.php Messages
git commit -m "docs(api): split STT OpenAPI resource tags"
```

Expected: PASS and no raw `rest_tag_*` in generated Swagger.

### Task 10: Show worker compatibility in the module UI

**Files:**
- Modify: `App/Controllers/ModuleLocalSpeechToTextController.php`
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`
- Modify: `Messages/en.php`
- Modify: `Messages/ru.php`
- Modify: all other `Messages/*.php`
- Create: `Tests/check-worker-api-v2-ui-contract.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Produces UI fields: UID, app version, API version, compatible/incompatible, online/offline, last seen.
- Compatibility is true only when `(int)$worker->api_version === WorkerApiContract::API_VERSION`.

- [ ] **Step 1: Add a failing rendered-source contract**

Assert the worker table contains `worker.worker_uid`, `worker.app_version`, `worker.api_version`, a compatibility label, and no API key/token query parameters.

- [ ] **Step 2: Run and confirm failure**

```bash
php Tests/check-worker-api-v2-ui-contract.php
```

Expected: FAIL because version columns are not rendered.

- [ ] **Step 3: Render compatibility without secrets**

Pass `workerApiVersion=2` to Volt. Display a red `incompatible` label for v1 rows even if their stale `status` was online; migration should already mark them offline. Add an update-help link from a fixed module documentation setting/path with no token, UID, or lease query parameters.

- [ ] **Step 4: Translate, test, and commit**

Use English/Russian copy `Compatible`/`Совместим`, `Worker update required`/`Требуется обновить воркер`, `API version`/`Версия API`, `App version`/`Версия приложения`. Translate all catalogs through the module translation workflow.

```bash
php Tests/check-worker-api-v2-ui-contract.php
php Tests/check-openapi-translations.php
git add App/Controllers/ModuleLocalSpeechToTextController.php App/Views/ModuleLocalSpeechToText/index.volt Messages Tests/check-worker-api-v2-ui-contract.php Tests/run-all.php
git commit -m "feat: show STT worker API compatibility"
```

Expected: PASS.

- [ ] **Step 5: Run the required Volt/admin UI review**

After the Task 10 commit, dispatch `admin-ui-reviewer` as the separate task reviewer required by the Sequential SDD Gate. Return every Critical/Important finding to the same implementer, rerun the UI/translation checks after fixes, and do not write the Task 10 completion ledger line until the reviewer is clean.

### Task 11: Bump compatibility/version metadata and document the breaking upgrade

**Files:**
- Modify: `module.json`
- Modify: `README.md`
- Create: `docs/worker-api-v2-migration.md`
- Modify: `Tests/check-module-structure-contract.php`
- Create: `Tests/check-no-working-legacy-worker-routes.php`
- Modify: `Tests/run-all.php`

**Interfaces:**
- Publishes module version `1.45` while retaining Core floor `2025.1.1`.
- Documents exact old/new route table, update order, 426/404 behavior, preserved data, and rollback limits.

- [ ] **Step 1: Add failing metadata and legacy reachability checks**

Assert `module.json` contains:

```json
{
  "version": "1.45",
  "min_pbx_version": "2025.1.1"
}
```

The legacy check must enumerate all eight v1 routes and prove each processor dispatch resolves only to `LegacyRouteRejector::worker()`; no routing reference may point to deleted v1 action classes.

- [ ] **Step 2: Update metadata and migration documentation**

Document the required user order: update module, observe old macOS worker as incompatible/offline, update `LocalSTTWorkerMac` to 1.7 (34), then verify contract negotiation and claim. State that Windows and Python workers are outside this migration and are not release artifacts.

- [ ] **Step 3: Run focused checks and commit**

```bash
php Tests/check-module-structure-contract.php
php Tests/check-no-working-legacy-worker-routes.php
php Tests/check-worker-api-v2-route-contract.php
git add module.json README.md docs/worker-api-v2-migration.md Tests/check-module-structure-contract.php Tests/check-no-working-legacy-worker-routes.php Tests/run-all.php
git commit -m "docs: publish STT worker API v2 upgrade"
```

Expected: PASS.

### Task 12: Complete verification, package, and live REST matrix

**Files:**
- No new production files expected.
- Update tests or documentation only when a verification failure demonstrates a real contract defect.

**Interfaces:**
- Produces a release-candidate module ZIP and recorded compatibility evidence for the coordinated release plan.

- [ ] **Step 0: Run sequential project reviews after implementation**

After the implementer has completed Tasks 1-11 and every per-task ledger line is clean, schedule whole-project reviewers one at a time and return every finding to the same implementer before invoking the next reviewer: `module-contract-guardian`, `queue-resilience-reviewer`, `worker-stt-quality-reviewer`, `module-security-reviewer`, then `module-code-reviewer` as the final whole-module review. `admin-ui-reviewer` must already have reviewed the Task 10 Volt changes. Re-run focused checks after every fix. After all four component plans are implemented and their project reviews are clear, schedule one final cross-project `module-contract-guardian` with `model=gpt-5.6-sol`; it must inspect both modules and both macOS workers and explicitly confirm there are no MikoPBX Core, Windows STT Worker, or Python STT Worker changes. Do not run implementer and reviewer concurrently.

- [ ] **Step 1: Run the complete repository suite**

```bash
php Tests/run-all.php
```

Expected: all tests pass, including migration, route, OpenAPI, permission, queue, recording, transcript, and legacy rejection checks.

- [ ] **Step 2: Build the package**

```bash
module_build_dir="$(mktemp -d /tmp/module-local-stt-worker-api-v2.XXXXXX)"
./scripts/package-module.sh "$module_build_dir"
unzip -l "$module_build_dir"/*.zip
```

Expected: package succeeds and contains no test DB, local secret, `.git`, build cache, or untracked log artifacts.

- [ ] **Step 3: Run live REST checks on an isolated PBX**

Use the `miko-rest-api-check` workflow with `MIKOPBX_TEST_BASE_URL` and `MIKOPBX_TEST_BEARER` environment variables. Verify the v2 happy path, wrong/missing version `426` with body `error_code=worker_upgrade_required` and `required_worker_api_version=2`, all eight legacy routes 426 without DB mutations, numeric-ID admin CRUD, restricted-key self-registration/ownership and management denial, Range recording, and a before/after SQLite hash for terminal jobs/results/transcripts. Do not assert response headers.

- [ ] **Step 4: Inspect final state**

```bash
git diff --check
git status --short
git log --oneline --max-count=15
```

Expected: no whitespace errors; unrelated pre-existing logging/UI edits remain untouched and unstaged by these commits.
