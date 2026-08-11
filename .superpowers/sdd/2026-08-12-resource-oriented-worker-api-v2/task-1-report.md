# Task 1 report: STT worker API v2 RED contracts

## Files changed

- `Tests/check-worker-api-v2-route-contract.php` (new)
- `Tests/check-worker-api-v2-version-contract.php` (new)
- `Tests/run-all.php` (registers both new checks explicitly)

## Tests and checks

| Command | Exit code | Result |
| --- | ---: | --- |
| `php Tests/check-worker-api-v2-route-contract.php` | 255 | Expected RED: `GET /worker-api-contract` is absent from reflected controller routes. |
| `php Tests/check-worker-api-v2-version-contract.php` | 255 | Expected RED: required `WorkerApiContract::API_VERSION` source symbol is absent. |
| `php -l Tests/check-worker-api-v2-route-contract.php` | 0 | No syntax errors. |
| `php -l Tests/check-worker-api-v2-version-contract.php` | 0 | No syntax errors. |
| `php -l Tests/run-all.php` | 0 | No syntax errors. |
| `git diff --check` | 0 | No whitespace errors. |

The two non-zero contract checks are intentional. Production v2 resources and the `WorkerApiContract` helper are deliberately out of scope for this RED-only task.

## Contract coverage

- Reflects `ApiResource`, `HttpMapping`, and `ApiOperation` controller attributes and validates the complete v2 method/path matrix.
- Requires each legacy worker action route to remain routable as a tombstone while carrying no `ApiOperation`.
- Rejects GET operations that resolve to mutation-named actions.
- Requires the negotiated API version, ownership header, and upgrade-required symbols.
- Requires at least one v2 processor to gate with `WorkerApiContract::requireVersion()` before STT business actions.
- Guards v2 `/job-recordings/` source URLs against query credentials, legacy `:download`, and legacy `/jobs/` paths; scans any Swift examples in the module too.

## Self-review

- Reviewed the exact task-owned diff and confirmed no production API resources were added or altered.
- The route check uses local attribute stubs, matching the established module contract-test pattern, so it remains runnable without Phalcon or a PBX image.
- The runner retains the existing dynamically discovered contract checks and additionally names both v2 checks explicitly.
- No user changes were reverted, staged, or committed.

## Concerns

- The worktree has no `Core` sibling at the path described in the brief; the new reflection test intentionally does not need Core files because it declares only the attribute stubs required for reflection.
- Both checks must turn green only after later tasks add the v2 resources, legacy tombstones, and contract helper.
