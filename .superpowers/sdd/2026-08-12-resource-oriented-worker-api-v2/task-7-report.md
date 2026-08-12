# Task 7 report — STT administrative Jobs resource

## Outcome

Added the administrative `/jobs` list/detail/create/item and collection PATCH/item and collection DELETE matrix. Restricted, revoked, ambiguous, cached, and otherwise non-administrator bearer callers are rejected in the Processor before lifecycle or service mutation. The six v1 Job actions remain routable only as undocumented, terminal body-only 426 tombstones.

POST requires `call_id`, a canonical readable regular file contained under the monitor directory, and an exact integer priority from 1 through 10. Duplicate canonical paths return 409 and successful creation is pending. Retry preserves attempts; explicit reset clears attempts. Bulk transitions validate every target before mutation inside one retrying module transaction. Deletion accepts only pending/error jobs and rejects result or terminal-receipt history; collection deletion derives its required status subset from normalized query data.

## RED / GREEN evidence

- RED: `php Tests/check-management-jobs-v2.php` exited 1 with `STTJobManagementService implementation is missing`.
- GREEN: the same command prints `STT management Jobs v2 contract OK`.
- The plain route aggregate prints `Worker API v2 route contract OK`; its mutation mode also passes.
- Queue lifecycle, SQLite lease, lease-resource, artifact-resource, receipt SQLite, Worker CRUD, and v2 migration checks pass.
- Version mutation mode passes. The plain version aggregate retains the established pre-Task-7 exact-case literal delta: `X-MikoPBX-Worker-API-Version`; Task 7 did not modify the shared WorkerApiContract file.

## Scope and concerns

No Core, AI, client, worker, permission, version, or translation file was changed. `min_pbx_version` remains `2025.1.1`. Core proxy Range behavior remains the accepted Task 6 live-PBX deferral. The Task 7 focused check protects routing/schema/service composition; related executable SQLite and processor suites supply the existing lifecycle, transaction, receipt, and authorization regression coverage.

Commit subject: `feat: add STT management jobs resource`.
