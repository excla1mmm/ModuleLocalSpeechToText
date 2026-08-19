# STT worker API v2 migration

`ModuleLocalSpeechToText` 1.45 requires worker API contract version 2. The MikoPBX Core minimum remains `2025.1.1`; this release has no Core prerequisite.

## Route migration

All paths are relative to `/pbxcore/api/v3/module-local-speech-to-text`.

| Previous v1 route | Replacement v2 route |
| --- | --- |
| `POST /workers:register` | `POST /workers` |
| `GET /workers:getProcessingSettings` | `GET /worker-processing-settings` (caller-bound singleton settings) |
| `GET /jobs:getNext` | `POST /job-leases` |
| `GET /jobs/{id}:download` | `GET /job-recordings/{job_id}` |
| `POST /jobs/{id}:submitResult` | `PUT /job-results/{job_id}` |
| `POST /jobs/{id}:submitError` | `PUT /job-failures/{job_id}` |
| `POST /jobs/{id}:heartbeat` | `PATCH /job-leases/{job_id}` |
| `POST /jobs/{id}:release` | `DELETE /job-leases/{job_id}` |

The `{id}` and `{job_id}` values are numeric module database IDs. `worker_uid` remains the stable worker identity and belongs in the relevant request body; it is not replaced by a route ID.

## Compatibility and failure behavior

A v2 worker starts with `GET /worker-api-contract`. Every subsequent worker request carries `X-MikoPBX-Worker-API-Version: 2`. The module accepts no legacy fallback.

The eight listed v1 routes are no longer registered. Requests to them are rejected by the routing or authorization layer, typically with HTTP 403, 404, or 405 depending on the request credentials and route shape. There is no module-level v1 compatibility response and no legacy processor or domain operation is invoked.

A new worker against an old module sees `404` or `405` for `/worker-api-contract`. It must classify that locally as `module_upgrade_required`, stop registration and polling, and not try any v1 route.

## Update order

1. Update `ModuleLocalSpeechToText` to version `1.45`.
2. The prior macOS worker is intentionally incompatible/offline after that update.
3. Update `LocalSTTWorkerMac` to version `1.7`, build `34`.
4. Retry contract negotiation, register through `POST /workers`, and claim via `POST /job-leases`.

Existing worker API keys and their IDs are preserved; this migration does not rotate keys. Windows and Python workers are outside this migration and are not release artifacts.

## Data preservation and rollback limits

The module migration preserves worker UID, numeric IDs, API-key bindings and history. Existing v1 worker rows are marked offline/incompatible; existing v2 worker rows are retained. Active v1 processing leases are returned to pending with ownership and lease fields cleared, without increasing attempts. Completed, skipped, terminal jobs, results, transcripts, events, and other stored results remain unchanged.

Migration runs under the module's existing setup backup/restore guard and records an idempotent v2 migration marker only after the module-owned schema/data changes succeed. A setup failure rolls back the in-transaction changes. After the module update, an old worker cannot be made functional by retrying its former routes because those routes are no longer registered. Restoring a previous module package/database backup is an operational rollback, subject to the normal module backup availability and reinstall limits; it does not preserve a v1 worker lease created before the v2 migration.
