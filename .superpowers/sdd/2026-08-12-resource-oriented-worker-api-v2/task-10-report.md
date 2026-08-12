# Task 10 report — STT worker API compatibility UI

## Outcome

The Workers tab now displays each worker's escaped UID, application version, API version, exact API compatibility, online/offline state, and last-seen time. Compatibility is calculated in the controller solely by comparing the integer worker version with `WorkerApiContract::API_VERSION`; therefore an online v1 worker is visibly incompatible. The required API version is also exposed to the view as `workerApiVersion` from that same constant.

The fixed worker-update documentation URL has no query or fragment and the compatibility UI adds no credential, UID, or lease-token URL. Existing worker-key actions and layout remain unchanged.

## RED / GREEN evidence

- RED: `php Tests/check-worker-api-v2-ui-contract.php` failed with `Controller must import WorkerApiContract`, proving the compatibility UI contract was absent before production edits.
- GREEN: `php Tests/check-worker-api-v2-ui-contract.php`, `php Tests/check-openapi-translations.php`, `php Tests/check-worker-download-ui-contract.php`, and `php Tests/check-worker-processing-settings-ui-contract.php` pass.
- PHP lint passes for the changed controller, new contract test, and all 29 catalogs. `git diff --check` passes.

## Locales

All 29 `Messages/*.php` catalogs preserve the exact Russian-source key order and provide localized worker UID, compatibility, incompatibility, update-required, update-help, API-version, and application-version labels. Russian includes the prescribed literals: `Совместим`, `Требуется обновить воркер`, `Версия API`, and `Версия приложения`.

## Minor

The existing Volt source contracts validate rendered-template structure rather than booting a full PBX/Phalcon renderer; the compatibility branch and escaping are directly source-checked.
