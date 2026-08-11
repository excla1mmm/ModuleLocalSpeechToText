# Task 1 report: STT worker API v2 RED contracts

## Corrective round 1

The initial tests were strengthened after review. The route contract now derives all operations from reflected `ApiResource` and `HttpMapping` values instead of `@route` comments, compares the normalized v2 set exactly (including no extras), retains the exact legacy HTTP method map, and verifies that each legacy dispatch is a non-mutating `WorkerApiContract::upgradeRequired()` tombstone.

The version/sensitive-data contract now:

- Loads and reflects `WorkerApiContract`, requiring `CONTRACT_NAME === 'MikoPBX-Worker-API'`, integer `API_VERSION === 2`, and lower-case `VERSION_HEADER === 'x-mikopbx-worker-api-version'`.
- Requires each worker-only v2 operation's own processor to gate before dispatch and any business/write-lease work, then return its version error rather than fall back to v1.
- Extracts every multiline PHP `file_url => ...` expression from the v2 `JobLeases` resource; it requires `/job-recordings/` and rejects query strings, fragments, authorities, legacy `/jobs/`, `:download`, `worker_uid`, and `lease_token`.
- Restricts the two ownership headers to the dedicated recording processor, requires body ownership values to be discarded, and rejects ownership query consumption.

Mutation proof (all exit 0 because each deliberately malformed fixture was rejected by the strengthened assertion):

| Command | Exit code | Rejected mutation |
| --- | ---: | --- |
| `WORKER_API_V2_ROUTE_MUTATION=1 php Tests/check-worker-api-v2-route-contract.php` | 0 | Extra GET lease route and a legacy callback without an upgrade tombstone. |
| `WORKER_API_V2_VERSION_MUTATION=1 php Tests/check-worker-api-v2-version-contract.php` | 0 | Multiline credential-bearing legacy `file_url`, ungated callback, string API version/title-case header, and ownership-header leakage. |

These fixtures demonstrate gaps the prior line-only URL scan, global processor substring scan, comment-derived routes, and string-symbol checks would have missed.

## Files changed

- `Tests/check-worker-api-v2-route-contract.php` (new)
- `Tests/check-worker-api-v2-version-contract.php` (new)
- `Tests/run-all.php` (registers both new checks explicitly)

## Tests and checks

| Command | Exit code | Result |
| --- | ---: | --- |
| `php Tests/check-worker-api-v2-route-contract.php` | 255 | Expected RED: reflected v2 `ApiResource`/`HttpMapping` operation set is empty because the v2 resources are absent. |
| `php Tests/check-worker-api-v2-version-contract.php` | 255 | Expected RED: `Lib/Services/WorkerApiContract.php` is absent. |
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
