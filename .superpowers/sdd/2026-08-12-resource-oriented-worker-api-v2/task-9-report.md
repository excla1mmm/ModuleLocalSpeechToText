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
