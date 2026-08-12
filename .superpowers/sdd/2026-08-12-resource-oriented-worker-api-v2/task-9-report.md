# Task 9 report — split STT OpenAPI resource tags

## Outcome

OpenAPI resources are grouped into five exact STT tags: workers, jobs, recordings, transcripts, and call transcripts. The tags are localized through five new `rest_tag_LocalSTT*` keys in all 29 module catalogs; the non-English catalogs use localized labels, not English fallbacks. The retired shared tag key was removed from each catalog.

The focused OpenAPI contract reflects all 12 resource controllers and verifies that the legacy runtime tombstone methods have no `ApiOperation` attribute, so they remain routable at runtime without entering the generated document.

## RED / GREEN evidence

- RED: `php Tests/check-openapi-contract.php` failed with `Workers must use the Local STT Workers OpenAPI tag`; `php Tests/check-openapi-translations.php` failed because Russian lacked `rest_tag_LocalSTTWorkers`.
- GREEN: both focused checks print their `OK` confirmation after the controller and catalog changes.
- `php -l Messages/*.php` completed successfully; `git diff --check` completed successfully.
- Adjacent aggregate checks remain outside Task 9 scope and currently fail on pre-existing later-task conditions: the route check requires a different legacy terminal shape, and the version check reports missing `X-MikoPBX-Worker-API-Version`. No route/version production files were changed.

## Scope

No Core, AI, macOS, Windows, Python, UI, or version metadata files changed. `min_pbx_version` remains `2025.1.1`.

Minor parked: localized tag wording was supplied directly for all catalogs; native-speaker review of lower-traffic locales can refine wording without changing the tag-key contract.

## Fix round 1 — restore current v2 OpenAPI coverage

The focused contract again verifies current resource operations rather than the retired v1 action endpoints: Worker API contract and processing settings, lease create/heartbeat/release, recording download, and result/failure update resources. It verifies operation-ID uniqueness, response status envelopes, response schemas, required request parameters, and resource security.

Transcript, event, and call-transcript contracts again protect list wrappers, pagination and cursor constraints, revision constraints, schema structures, and detail/event example linkage. Tombstone coverage now derives generated paths from `ApiResource` plus `HttpMapping` metadata and checks all eight custom suffixes, including `Workers::getProcessingSettings`.

RED: `LOCAL_STT_OPENAPI_MUTATION=drop-transcript-pagination php Tests/check-openapi-contract.php` exited 255 with `Transcript tag mutation must be detected by the OpenAPI contract`. GREEN: both focused OpenAPI checks and all changed PHP lint checks pass; `git diff --check` is clean. The translation check dynamically collects annotation keys across current REST controllers/data structures and validates them in every catalog; the recording binary-response description is an explicitly documented renderer-owned literal.
