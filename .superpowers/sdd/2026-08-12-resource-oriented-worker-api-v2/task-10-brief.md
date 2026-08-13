# Task 10 brief — STT worker API compatibility UI

Implement the module-only Workers-tab compatibility display. Keep MikoPBX Core read-only, preserve `min_pbx_version` at `2025.1.1`, and do not modify AI, macOS, Windows, Python, OpenAPI, metadata, or release documentation.

- Add a failing executable rendered-source contract before UI changes.
- Pass `workerApiVersion` from `WorkerApiContract::API_VERSION` and calculate compatibility only as `(int)$worker->api_version === WorkerApiContract::API_VERSION`.
- Preserve the Workers layout and actions while displaying escaped worker UID, application version, API version, compatibility, status, and last-seen values. A v1 worker is visibly incompatible even when its persisted status is online.
- Add only a fixed, token-free worker-update help URL. Do not put API keys, bearer credentials, worker UIDs, or lease tokens in UI links or query strings.
- Add the required UI keys to all 29 catalogs using `Messages/ru.php` as the exact key-order source, with genuine localized values.
