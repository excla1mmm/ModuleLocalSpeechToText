# Task 1 report: STT worker API v2 RED contracts

## Corrective round 1

The initial tests were strengthened after review. The route contract now derives all operations from reflected `ApiResource` and `HttpMapping` values instead of `@route` comments, compares the normalized v2 set exactly (including no extras), retains the exact legacy HTTP method map, and verifies that each legacy dispatch is a non-mutating `WorkerApiContract::upgradeRequired()` tombstone.

The version/sensitive-data contract now:

- Loads and reflects `WorkerApiContract`, requiring `CONTRACT === 'MikoPBX-Worker-API'`, integer `API_VERSION === 2`, and lower-case `VERSION_HEADER === 'x-mikopbx-worker-api-version'`.
- Requires each worker-only v2 operation's own processor to gate before dispatch and any business/write-lease work, then return its version error rather than fall back to v1.
- Extracts every multiline PHP `file_url => ...` expression from the v2 `JobLeases` resource; it requires `/job-recordings/` and rejects query strings, fragments, authorities, legacy `/jobs/`, `:download`, `worker_uid`, and `lease_token`.
- Restricts the two ownership headers to the dedicated recording processor, requires body ownership values to be discarded, and rejects ownership query consumption.

Mutation proof (all exit 0 because each deliberately malformed fixture was rejected by the strengthened assertion):

| Command | Exit code | Rejected mutation |
| --- | ---: | --- |
| `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php` | 0 | Extra GET lease route and a legacy callback without an upgrade tombstone. |
| `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php` | 0 | The actual legacy runtime `file_url` builder, ungated callback, string API version/title-case header, and internal ownership-header leakage. |

These fixtures demonstrate gaps the prior line-only URL scan, global processor substring scan, comment-derived routes, and string-symbol checks would have missed.

## Corrective round 2

The contract now validates the actual runtime `Lib/LocalSpeechToTextMain.php` claim `file_url` builder before the helper can be loaded. It permits exactly `'/job-recordings/' . (int)$job->id` and rejects API-base prefixes, extra concatenation, credentials, query strings, fragments, and legacy paths. It then scans every REST schema/OpenAPI producer carrying `file_url`, explicitly including `JobLeases/DataStructure.php`, and scans any Swift-facing examples found in the module.

Version gates now capture the variable assigned by `WorkerApiContract::requireVersion()` and require the immediate guard to return that same variable before action dispatch or business work. Legacy route verification now checks each exact enum action branch for a direct `LegacyRouteRejector::worker()` return; it no longer treats callback-wide later admin work as a tombstone failure.

Ownership verification follows the canonical Task 6 handoff: header extraction may create `_worker_uid_header` and `_lease_token_header`; only JobRecordings may consume them, must overwrite both ownership inputs from those internal values, then clear them. Body-key unsets and title-cased header literals are deliberately not required.

Route comparison now evaluates every reflected tuple before OpenAPI filtering. It allows only the exact v2 or exact legacy tuple/resource/method sets, checks v2 and legacy `ApiOperation` status separately, and rejects an extra hidden mapping or a legacy custom action mounted on the wrong resource.

## Corrective round 3

Canonical-spec resolution: the user/canonical specification wins over the conflicting component-plan snippet. The only accepted runtime expression is `'/job-recordings/' . (int)$job->id`; `API_BASE_PATH`, any query/fragment/credential suffix, and any additional concatenation are rejected. This preserves the required path-only `file_url` contract.

The ownership cleanup check now parses each `unset(...)` member list and requires one relevant statement to clear both internal header values, including the canonical combined unset. Version tests require the exact assigned version-error variable to be returned immediately, before dispatch, lifecycle acquisition, model/service work, or business calls. Legacy action arms must appear once and before setup. Route discovery now carries unknown module resources into the exact comparison (while explicitly classifying the transcript resources as non-worker) and rejects duplicate legacy tuples.

## Corrective round 4

Legacy tests now require a terminal `return match` dispatch, with one direct rejection arm for each legacy enum case and no lifecycle/model/service/business work before it. Mutation evidence includes a non-terminal `$result = match(...); (new STTJob())->save(); return $result;` callback and verifies rejection.

PHP schema checks now validate each value structurally associated with a `file_url` key: direct scalar values and nested value-local examples are both supported, and unrelated examples cannot satisfy an unsafe `file_url`. Swift checks likewise extract values bound to `file_url:`/`"file_url":` forms rather than scanning unrelated path literals. Non-recording processors now reject any internal ownership-header reference, including aliases/helpers.

## Corrective round 5

Reflected worker resources now require their declared processor to be the exact expected non-null class and require that processor file to exist; a null or unused/wrong processor mutation is rejected. The legacy parser identifies the first terminal `return match` and checks required arms only in that match body, with pre-terminal work checks and mutations for model deletion and later unrelated matches. Swift key-value extraction also supports typed assignments such as `file_url: String = "..."`; typed safe and unsafe fixtures were exercised.

## Corrective round 6 (explicit user authorization)

The user explicitly authorized this exceptional sixth corrective round after the five-round cap. Legacy and v2 version checks now require their gate/tombstone dispatch to be reached through a narrowly whitelisted top-level prelude only: legacy requires an unconditional early `return match`, and v2 requires the exact `WorkerApiContract::requireVersion($request)` assignment followed immediately by its same-variable return guard. Controlled dead-conditional and unknown-call bypasses are rejected.

Ownership isolation now examines PHP production sources under `Lib/`, `Setup/`, and `App/`. Internal ownership headers are permitted only in the planned WorkerRequestContext producer and JobRecordings processor consumer; all other source references, including Actions/helpers that forward aliases, are rejected. This remains a bounded source-contract check rather than a runtime data-flow proof.

Round-6 mutation commands (each exits 0 only because its malformed fixture is rejected):

| Command | Exit | Evidence |
| --- | ---: | --- |
| `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php` | 0 | Rejects dead conditional/unknown-call legacy tombstones, pre-tombstone work, later-match arms, duplicate/hidden tuples, and null/wrong processor declarations. |
| `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php` | 0 | Rejects dead/late/unknown-prelude version gates, unsafe runtime/PHP/Swift URLs, and Action-level ownership-header forwarding. |

## Corrective round 7 (explicit continuation authorization)

The user explicitly continued the exceptional corrective effort for round 7. Legacy validation now normalizes comments and whitespace, binds each required arm to the first terminal `return match` body, requires the arm expression to end exactly at `LegacyRouteRejector::worker()`, and requires the match close to be followed only by `;`. It rejects boolean/call arm suffixes and return-expression suffixes.

WorkerRequestContext is now checked as the sole producer with exactly two internal-header assignments: `_worker_uid_header` from `x-mikopbx-worker-uid` and `_lease_token_header` from `x-mikopbx-job-lease-token`. The bounded production source scan still permits those names only in that producer and JobRecordings, rejecting all other `Lib/`, `Setup/`, and `App/` references. This is source-structural coverage, not a proof of runtime log sinks outside this module.

Round-7 mutations are included in the existing two commands: the route mode rejects `&& mutateDatabase()` arm suffixes and `+ mutateDatabase()` return suffixes while accepting a formatted harmless prelude; the version mode accepts a canonical producer fixture and rejects body/query-equivalent, swapped-header, and extra logging/use fixtures. Both mutation commands exit 0 after observing the intended rejections.

## Files changed

- `Tests/check-worker-api-v2-route-contract.php` (new)
- `Tests/check-worker-api-v2-version-contract.php` (new)
- `Tests/run-all.php` (registers both new checks explicitly)

## Tests and checks

| Command | Exit code | Result |
| --- | ---: | --- |
| `php Tests/check-worker-api-v2-route-contract.php` | 255 | Expected RED: the reflected active operation set still contains legacy actions instead of the v2 matrix. |
| `php Tests/check-worker-api-v2-version-contract.php` | 255 | Expected RED: the existing runtime claim builder still creates credential-bearing legacy `/jobs/{id}:download` URLs. |
| `php -l Tests/check-worker-api-v2-route-contract.php` | 0 | No syntax errors. |
| `php -l Tests/check-worker-api-v2-version-contract.php` | 0 | No syntax errors. |
| `php -l Tests/run-all.php` | 0 | No syntax errors. |
| `git diff --check` | 0 | No whitespace errors. |

The two non-zero contract checks are intentional. Production v2 resources and the `WorkerApiContract` helper are deliberately out of scope for this RED-only task.

## Contract coverage

- Derives and exactly compares v2 operations from `ApiResource`/`HttpMapping` attributes, with no reliance on `@route` comments.
- Requires each legacy action's original HTTP mapping, lack of `ApiOperation`, and non-mutating 426 tombstone dispatch.
- Requires a pre-dispatch `requireVersion()`/return path for every worker-only v2 operation.
- Reflects strict WorkerApiContract constants and requires the version/ownership/upgrade code identifiers after comments are stripped.
- Parses complete multiline v2 `file_url` expressions/examples rather than matching a nearby path line.
- Restricts ownership headers to the recording processor and requires body/query credentials to be ignored.

## Self-review

- Reviewed the exact task-owned diff and confirmed no production API resources were added or altered.
- The route check uses local attribute stubs, matching the established module contract-test pattern, so it remains runnable without Phalcon or a PBX image.
- Reviewed processor path construction, normalized route comparison, action-level gate labels, multiline URL extraction, and ownership-header scope against controlled rejection fixtures.
- The runner retains the existing dynamically discovered contract checks and additionally names both v2 checks explicitly.
- No production code or unrelated user changes were modified.

## Concerns

- The worktree has no `Core` sibling at the path described in the brief; the new reflection test intentionally does not need Core files because it declares only the attribute stubs required for reflection.
- Both checks must turn green only after later tasks add the v2 resources, legacy tombstones, and contract helper.

## Corrected-plan restart / final report (2026-08-12)

This restart audits the complete existing Task 1 tests against the corrected architecture. Worker item routes now use numeric `{id}`, processing settings is the caller-bound collection singleton `GET /worker-processing-settings`, and the shared administrative `Workers` processor is no longer incorrectly required to apply an unconditional worker-version gate. The runtime `file_url` contract remains the exact path-only expression `'/job-recordings/' . (int)$job->id`. Superseded round-8 parser constraints were removed rather than extended.

### RED evidence

| Command | Exit | Output and intended reason |
| --- | ---: | --- |
| `php Tests/check-worker-api-v2-route-contract.php` | 255 | `Reflected v2 ApiResource/HttpMapping operation set changed`; actual reflected operations are the eight legacy actions, while the independently defined expected set is the 21-operation v2 matrix with numeric worker IDs and singleton processing settings. |
| `php Tests/check-worker-api-v2-version-contract.php` | 255 | `WorkerApiContract.php must exist before v2 routes can run`; the corrected failure is the missing shared v2 contract helper, before later runtime/schema guards execute. |

Both failures are intentional RED results caused by production resources/helper that later tasks will add. No production code was changed in this task.

### Mutation evidence

| Command | Exit | Output and coverage |
| --- | ---: | --- |
| `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php` | 0 | `Worker API v2 route mutation checks OK`; rejects an extra GET lease mutation, duplicate/hidden tuples, invalid legacy tombstones, `{worker_uid}` worker item routes, and an item-scoped processing-settings route. |
| `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php` | 0 | `Worker API v2 version mutation checks OK`; rejects missing/late/bypassed version gates, weak constants, ownership-header leakage, and credential-bearing or legacy PHP/Swift `file_url` values while accepting `/job-recordings/42`. |

### Syntax and diff checks

| Command | Exit | Output |
| --- | ---: | --- |
| `php -l Tests/check-worker-api-v2-route-contract.php` | 0 | `No syntax errors detected in Tests/check-worker-api-v2-route-contract.php` |
| `php -l Tests/check-worker-api-v2-version-contract.php` | 0 | `No syntax errors detected in Tests/check-worker-api-v2-version-contract.php` |
| `php -l Tests/run-all.php` | 0 | `No syntax errors detected in Tests/run-all.php` |
| `git diff --check` | 0 | No output. |

### Files changed in the corrected restart

- `Tests/check-worker-api-v2-route-contract.php`
- `Tests/check-worker-api-v2-version-contract.php`
- `.superpowers/sdd/2026-08-12-resource-oriented-worker-api-v2/task-1-report.md`

`Tests/run-all.php` already explicitly registered both checks in the prior Task 1 commit and required no correction.

### Self-review

- Re-read the corrected brief and the approved module plan; the expectations are literal and independent of production builders.
- Confirmed the route test reflects controller attributes, checks exact methods/paths, keeps legacy routes routable without `ApiOperation`, and rejects GET mappings to mutation actions.
- Confirmed version source guards apply to dedicated worker-protocol processors, not administrative CRUD; controlled mutation fixtures prove missing, late, and bypassed gates are rejected.
- Confirmed runtime/schema/Swift `file_url` checks reject module/API prefixes, `/jobs/`, `:download`, `lease_token`, and `worker_uid`, and accept only path-only `/job-recordings/` values.
- Confirmed the diff is confined to the two owned tests and this tracked report; no production or Core file was read as an implementation dependency or modified.

### Concerns

- The two focused tests are intentionally RED until later tasks add the v2 resources, legacy tombstones, and `WorkerApiContract` helper.
- Source guards are bounded structural checks; later behavioral task tests must still prove authorization, ownership, and version-error responses at runtime.
