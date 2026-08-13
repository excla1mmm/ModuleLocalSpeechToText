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

## Fix Round 1 — Core routing, provenance, preservation, and runtime evidence

The review's three Important production findings were reproduced. RED first failed on the absent Core action-map override: collection PATCH/DELETE would dispatch to item actions. The Jobs controller now overrides the existing Core-compatible `getActionMapping()` seam, registers POST only for collection create, and captures DELETE `status` directly from `request->getQuery()` into trusted provenance fields before the merged request reaches the Processor.

Item PATCH/DELETE apply exact `CanonicalJobId` body/route validation before lifecycle acquisition. Collection DELETE ignores merged/body `status` and accepts only the trusted query value. Deletion checks result, terminal receipt, transcript event, and call-transcript part job references in the same transaction before deleting. Lists expose Core pagination metadata. POST rejects lexical traversal and every symlink component before canonical containment/readability checks; duplicate creation is serialized through the module transaction and database unique invariant.

The new executable runtime harness loads actual Controller, Processor, management service, CanonicalJobId, and ModuleTransaction with only Core/Phalcon plumbing stubbed. Its file-backed SQLite and temporary monitor fixture covers exact action mapping/no item POST, valid and rejected paths, three-run stability, two-process duplicate creation, pagination, retry/reset and atomic bulk denial, all four history tables, query-only collection deletion, restricted/revoked/ambiguous/cached denial before lifecycle, admin access, canonical body IDs, and tombstone 426 without lifecycle dispatch.

Follow-up subject: `fix: harden STT management jobs`.

## Fix Round 2 — enforce collection routing at execution

Re-review found that Core's `resource ?? collection` fallback still made `POST /jobs/{id}` execute collection create. RED was `POST item reached fallback create dispatch`. The production controller now rejects every non-empty item POST with Core's 405 response path before sanitization or backend dispatch; collection POST remains create.

The runtime harness now implements Core's exact action fallback and merged-data behavior, executes the actual controller for all seven management route/action pairs, and proves item POST has no dispatch. It preserves raw body ID, float/bool/noncanonical priority/ID collections for downstream exact validation, and separately records query status when merged body data conflicts. Three controller-fixture mutations remove item-POST rejection, replace query provenance, or change collection PATCH routing; ordinary assertions fail in every mode.

Follow-up subject: `fix: enforce STT Jobs collection routing`.

## Fix Round 3 — preserve exact raw input types

Core's recursive sanitizer converts numeric values to integers, which could erase invalid JSON types before Task 7 validation. The controller now snapshots raw merged input and raw query before sanitization, decodes the original JSON body for body-ID provenance, and restores only the type-sensitive fields required by the management contract: item body `id`, effective POST `priority`, and effective collection PATCH `ids`/`action`. No arbitrary internal fields are copied. DELETE status provenance continues to come exclusively from raw query; Core query precedence remains intact.

The Core-shaped controller fixture now merges body then query and exposes JSON raw-body methods. Executable cases reject float, boolean, numeric-string, array, and noncanonical priority/IDs while valid integers remain accepted. Body-only DELETE status is explicitly untrusted. The query mutation now changes both presence and value provenance so that the ordinary body-only assertion catches it; POST, query, and routing mutation modes all fail.

Follow-up subject: `fix: preserve raw STT Jobs input types`.

## Fix Round 4 — bind raw validation through production

The runtime Core stub now recursively coerces numeric scalars to integers exactly where Core's `FILTER_INT` branch does. Controller dispatch payloads are no longer merely inspected: adversarial priority, bulk IDs, and item body IDs flow through the actual production Controller into the actual Processor and management service, and their 422/409 responses are asserted.

Three new modified-production controller fixtures remove raw body-ID, priority, or IDs restoration. Each ordinary end-to-end rejection assertion fails; the existing POST, query-provenance, and collection-routing mutations remain detected.

Follow-up subject: `test: bind STT Jobs raw input validation`.
