# Task 8 report — STT worker API-key permissions and authorization matrix

## Outcome

Worker credentials now use the exact eight-path Worker API v2 permission map. `/jobs` remains solely so legacy tombstones reach module HTTP 426; management Jobs still rejects restricted credentials with 403 before lifecycle/domain work.

Every setup reconciles the union of current and historical stored key IDs that still exist in Core. It changes only `full_permissions` and `allowed_paths`, preserves ID/hash/suffix/display/description and worker bindings/history, skips missing rows without recreation, and clears token cache only after transaction commit. Failure rolls back, restores in-memory Core model fields, preserves module bindings/history, and leaves cache untouched. Plaintext is not rotated or emitted.

## RED / GREEN evidence

- RED: `php Tests/check-worker-api-v2-authorization-matrix.php` exited 255 with `Exact v2 worker permission map changed` against the old two-path map.
- The permission test exposed its linked-worktree Core path assumption; test-only discovery now reads the actual read-only `/Users/excla1m/Development/Core` without Core changes.
- GREEN: permission semantics, reconciliation/idempotency/rollback, and executable combined authorization matrix pass.
- The matrix executes Task 4 Worker CRUD authorization, Task 5 settings/lease ownership, Task 6 artifacts, and Task 7 management Jobs cases, including own/foreign/ambiguous/revoked/cache-stale and administrator provenance plus denial snapshots/tombstones.
- Permission mutation removes `job-results` from the expected map and exits 255 with `Permission mutation detected`.

## Scope and concerns

No Core, AI, client, metadata, or translation file changed. `min_pbx_version` remains `2025.1.1`. Real Core permission semantics are rehearsed read-only; module processor gates remain load-bearing for Core's write/read quirk.

Commit subject: `feat: restrict STT worker API v2 permissions`.
