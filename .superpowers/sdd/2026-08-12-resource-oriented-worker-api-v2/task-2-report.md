# Task 2 report: STT worker API v2 negotiation

## Status

DONE_WITH_CONCERNS. The Task 2 negotiation behavior is implemented and its focused behavioral test is green. The Task 1 version and route aggregate guards still stop on production resources assigned to later tasks; no out-of-scope production files or Core files were changed.

## RED evidence

The regression test was created before any Task 2 production file. Its syntax was checked, then it was run against the pre-implementation tree:

```text
$ php -l Tests/check-worker-api-v2-negotiation.php
No syntax errors detected in Tests/check-worker-api-v2-negotiation.php

$ php Tests/check-worker-api-v2-negotiation.php
Fatal error: Uncaught RuntimeException: Missing Task 2 production file: /Lib/Services/WorkerApiContract.php ...
exit_code=255
```

This was the intended failure: the test loaded correctly and rejected the missing contract implementation.

## Implementation

- Added the final `WorkerApiContract` service with the exact typed constants, packaged module-version loading, contract response, lower-case-only version gate, and body-only HTTP 426 upgrade response.
- Added `WorkerRequestContext` to overwrite spoofable internal fields from trusted request-envelope values and map only the two canonical lower-case ownership headers.
- Added `LegacyRouteRejector` with direct worker-upgrade delegation and the administrative HTTP 410 result factory.
- Added the authenticated, collection-level `GET /worker-api-contract` singleton controller, processor, and OpenAPI data structure.
- Added the focused negotiation test to `Tests/run-all.php`.
- Left `module.json`, Core, and all later resource processors unchanged.

## Files

- `Lib/Services/WorkerApiContract.php`
- `Lib/RestAPI/Common/WorkerRequestContext.php`
- `Lib/RestAPI/Common/LegacyRouteRejector.php`
- `Lib/RestAPI/WorkerApiContract/Controller.php`
- `Lib/RestAPI/WorkerApiContract/Processor.php`
- `Lib/RestAPI/WorkerApiContract/DataStructure.php`
- `Tests/check-worker-api-v2-negotiation.php`
- `Tests/run-all.php`
- `.superpowers/sdd/2026-08-12-resource-oriented-worker-api-v2/task-2-report.md`

## GREEN and verification evidence

Focused negotiation behavior:

```text
$ php Tests/check-worker-api-v2-negotiation.php
Worker API v2 negotiation contract OK
exit_code=0
```

Task 1 mutation/source-guard modes:

```text
$ WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php
Worker API v2 version mutation checks OK

$ WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php
Worker API v2 route mutation checks OK
exit_code=0
```

All created/edited PHP files passed `php -l`:

```text
No syntax errors detected in Lib/Services/WorkerApiContract.php
No syntax errors detected in Lib/RestAPI/Common/WorkerRequestContext.php
No syntax errors detected in Lib/RestAPI/Common/LegacyRouteRejector.php
No syntax errors detected in Lib/RestAPI/WorkerApiContract/Controller.php
No syntax errors detected in Lib/RestAPI/WorkerApiContract/Processor.php
No syntax errors detected in Lib/RestAPI/WorkerApiContract/DataStructure.php
No syntax errors detected in Tests/check-worker-api-v2-negotiation.php
No syntax errors detected in Tests/run-all.php
exit_code=0
```

Cheap existing compatibility subset:

```text
$ php Tests/check-rest-api-contract.php
REST API contract OK
exit_code=0
```

Whitespace verification:

```text
$ git diff --cached --check
exit_code=0
```

## Expected staged aggregate failures

`php Tests/check-worker-api-v2-version-contract.php` successfully loads and validates the new contract constants and direct legacy worker tombstone, then stops at the existing `Lib/LocalSpeechToTextMain.php` legacy claim URL:

```text
Runtime file_url must be exactly /job-recordings/{job_id} with no prefix, suffix, query, fragment, or extra concatenation
Actual: self::API_BASE_PATH.'/jobs/'.$job->id.':download?worker_uid='...lease_token...
exit_code=255
```

Changing that runtime claim builder is outside Task 2 ownership and belongs with the later job-leases/job-recordings implementation. After that point, the same aggregate guard also intentionally requires worker-only processors assigned to Tasks 4-7.

`php Tests/check-worker-api-v2-route-contract.php` includes `GET /worker-api-contract getList` in its reflected actual set, proving the new singleton route is discovered, and remains red because the remaining resource routes are assigned to Tasks 4-7.

The broader `php Tests/check-openapi-contract.php` could not start because its existing relative Core lookup resolves to the absent path `Extensions/ModuleLocalSpeechToText/Core/...` from this worktree. No Core path, symlink, or test infrastructure was changed to work around that unrelated environment issue.

## Self-review

- Confirmed exact contract constants and capability order against the corrected brief.
- Confirmed `requireVersion()` reads only `httpHeaders['x-mikopbx-worker-api-version']` and accepts only the exact supported string value.
- Confirmed neither success nor upgrade results set `responseHeaders`.
- Confirmed the worker tombstone method body directly returns `WorkerApiContract::upgradeRequired()` with no setup, logging, legacy dispatch, or business call.
- Confirmed request body values cannot spoof the trusted remote address, auth type, API key ID, HTTP method, worker UID header, or lease token header.
- Confirmed the lease token and other header values are not included in messages or logs.
- Confirmed restricted-key detection requires both exact `bearer_token` auth and an allowed positive API-key ID through `STTSetting`.
- Confirmed the singleton has only a collection-level `GET getList` mapping, exact `Local STT Workers` tag, authenticated security, and the five required schema properties.
- Confirmed staged changes are limited to the eight planned Task 2 source/test files; the report is intentionally outside the source commit if ignored by repository policy.

## Concerns

- The packaged module currently reports `1.44`; `WorkerApiContract` intentionally reads that value dynamically. The planned later release-metadata task will change it to `1.45` while retaining `min_pbx_version = 2025.1.1`.
- The two plain Task 1 aggregate guards cannot be fully green until later resource tasks land. Their mutation modes and the Task 2 focused behavioral test are green now.
- The existing OpenAPI contract test has a worktree-relative Core path issue unrelated to this task.

## Fix Round 1

### Scope

- Hardened Task 2 request-context normalization for non-scalar ownership headers and API-key IDs.
- Removed the invented `responseHeaders` member from the negotiation test's local `PBXApiResult` stub and stopped dereferencing it.
- Added an executable body-only production-source guard for all six Task 2 production files, including mutation fixtures for `responseHeaders`, `Upgrade`, and `Cache-Control` dependencies.
- Corrected only the Task 2 acceptance text in `docs/superpowers/plans/2026-08-12-resource-oriented-worker-api-v2.md`; Core and later-task production files remain untouched.

### RED reproduction

The negotiation test installed a temporary error handler that converts every PHP warning into `ErrorException`, passed array/object ownership-header and API-key values containing secret sentinel strings, and captured output and exceptions. Before the production fix:

```text
$ php -l Tests/check-worker-api-v2-negotiation.php
No syntax errors detected in Tests/check-worker-api-v2-negotiation.php

$ php Tests/check-worker-api-v2-negotiation.php
Fatal error: Uncaught RuntimeException: malformed ownership headers must not warn or throw
Actual: 'Array to string conversion'
exit_code=255
```

This failure demonstrated the root cause at the trust boundary: direct string casting of a filtered-header value accepted an array and emitted a warning. The same direct-cast pattern in `isRestrictedWorkerKey()` could warn for objects or convert malformed containers to a nonzero integer.

### Production fix and secret-safety coverage

`WorkerRequestContext::data()` now filters the incoming header bag to scalar values before the two exact canonical lower-case assignments. Arrays and objects therefore normalize to empty strings without stringification. `isRestrictedWorkerKey()` now checks `_api_key_id` with `is_scalar()` and passes integer `0` to `STTSetting` for every non-scalar value.

The executable cases cover arrays and objects for:

- `x-mikopbx-worker-uid`;
- `x-mikopbx-job-lease-token`;
- `_api_key_id` passed to restricted-key detection.

For each case the test proves no warning, `TypeError`, exception, or output; header values become `''`; API-key ID becomes `0`; restricted-key detection is false; and sentinel strings do not appear in captured output or exception messages.

### Body-only 426 source guard

The local `PBXApiResult` test double now mirrors only members used by this contract and does not invent `responseHeaders`. `expectBodyOnlyWorkerApiV2Source()` executes against every Task 2 production file and rejects:

- any `responseHeaders` dependency;
- an `Upgrade` header literal;
- a `Cache-Control` header literal.

Three mutation fixtures exercise each forbidden form and are rejected during the normal negotiation test. Production files remain body-only and do not require a Core response-header feature.

### Component-plan correction

Task 2 now requires `WorkerApiContract` to read and validate the current scalar packaged module version dynamically. Task 11 retains sole ownership of changing `module.json` to `1.45`; only after that task must the contract report `1.45`.

Task 2 green acceptance is now explicitly:

- `php Tests/check-worker-api-v2-negotiation.php`;
- `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php`;
- `php Tests/check-rest-api-contract.php`;
- syntax checks for all Task 2 PHP files;
- `git diff --check`.

The plan now describes the plain version and route tests as progressive aggregate guards. Task 2 must advance them to the next later-task failure, not modify `LocalSpeechToTextMain` or future processors to make the aggregates prematurely green.

### GREEN evidence

```text
$ php Tests/check-worker-api-v2-negotiation.php
Worker API v2 negotiation contract OK

$ WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php
Worker API v2 version mutation checks OK

$ php Tests/check-rest-api-contract.php
REST API contract OK
```

All eight Task 2 PHP files passed `php -l`, and `git diff --check` exited `0`.

### Progressive aggregate evidence

The fresh plain version aggregate still advances through the Task 2 contract and stops at its next unmet later-task contract:

```text
$ php Tests/check-worker-api-v2-version-contract.php
Runtime file_url must be exactly /job-recordings/{job_id} with no prefix, suffix, query, fragment, or extra concatenation
Actual: self::API_BASE_PATH.'/jobs/'.$job->id.':download?worker_uid='...lease_token...
exit_code=255
```

This is the expected post-Task-2 boundary. `Lib/LocalSpeechToTextMain.php`, future resource processors, module metadata, and Core were not changed.
