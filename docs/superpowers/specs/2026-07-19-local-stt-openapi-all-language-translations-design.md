# Local STT OpenAPI All-Language Translations Design

## Goal

Replace the English fallback currently used by the Local STT Swagger/OpenAPI documentation with native, technically accurate translations for every language catalog shipped by `ModuleLocalSpeechToText`.

The work is documentation-only. It does not change REST routes, operation IDs, request or response structures, authorization, queue behavior, worker leases, or any other runtime API contract.

## Current state

The module contains 29 message catalogs:

- authored Russian source copy in `Messages/ru.php`;
- authored English copy in `Messages/en.php`;
- 27 additional catalogs: Azerbaijani, Czech, Danish, German, Greek, Spanish, Finnish, French, Croatian, Hungarian, Italian, Japanese, Georgian, Korean, Mongolian, Dutch, Polish, Portuguese, Brazilian Portuguese, Romanian, Swedish, Thai, Turkish, Ukrainian, Vietnamese, Simplified Chinese, and Traditional Chinese.

The OpenAPI contract currently references 180 translation keys. All 180 values in each of the 27 additional catalogs are copied from English. Catalog key parity is already enforced, but the current test explicitly requires this English fallback.

## Translation scope

Only the 180 strings referenced by the STT OpenAPI controllers and data structures are translated. Existing non-OpenAPI interface text is outside this task, even where a catalog still contains English UI copy.

The translated surface includes:

- the single Local STT Swagger tag;
- API resource descriptions;
- operation summaries and detailed descriptions;
- request parameter descriptions;
- response field and schema descriptions;
- module-specific response descriptions.

PHP array keys, their order, and the set of catalog entries remain unchanged. Russian and English values remain the approved reference copy unless validation reveals an unambiguous typo.

## Source and terminology policy

Russian is the semantic source language in accordance with the module localization workflow. English is used as a technical cross-reference when the Russian phrase could be interpreted in more than one way.

Each target language receives natural technical prose rather than a literal word-for-word rendering. Human-readable infrastructure terms are localized, including the local equivalents of worker, queue, lease, heartbeat, transcript, and recognition job.

The following elements remain unchanged where they occur in the source copy:

- product and protocol abbreviations: `STT`, `PBX`, `API`, `CDR`, `DID`, `HTTP`, `RMS`, and `VAD`;
- standards and formats: `SHA-256`, Unix timestamps, JSON, and HTTP Range;
- literal field names: `worker_uid`, `lease_token`, `start_ms`, `end_ms`, `next_cursor`, and similar identifiers;
- enum and event literals: `transcript.completed`, `created`, `complete`, `legacy_unresolved`, `ANSWERED`, and similar machine-readable values;
- endpoint and operation references such as `workers:getProcessingSettings`.

When a literal appears inside explanatory prose, the literal remains intact and the surrounding explanation is translated. The distinction between one recording-level transcription and an aggregated logical-call transcript must remain explicit in every language.

## Translation quality requirements

Every translation must preserve the operational meaning of the source, including:

- lease ownership and validity requirements;
- retry and permanent-failure semantics;
- queue-empty behavior where `job` is `null`;
- immutable call-transcript revisions;
- cursor persistence and idempotent event consumption;
- server-controlled processing settings;
- integer millisecond timestamp requirements;
- recording access and HTTP Range restrictions;
- nullable fields and pagination behavior.

Operation summaries remain short and action-oriented. Descriptions use consistent terminology within each catalog. Portuguese and Brazilian Portuguese, as well as Simplified and Traditional Chinese, receive separate localized wording rather than duplicated locale variants where their normal technical usage differs.

All strings must remain valid single-quoted PHP literals. Apostrophes, backslashes, and other PHP-sensitive characters are escaped correctly. Existing placeholders and literal identifiers are preserved exactly.

## Implementation approach

The 27 catalogs are updated in place. The approved 180-key set is derived from the same controller and data-structure references used by `Tests/check-openapi-translations.php`; no manually maintained second list is introduced into production code.

The contract test changes from enforcing English fallback to enforcing native localization. It continues to assert:

- exactly 29 catalogs are present;
- every catalog is a valid PHP array;
- all catalogs have exactly the same keys and order as `ru.php`;
- every OpenAPI key referenced by module metadata exists;
- approved Russian and English wording remains unchanged.

For every non-Russian, non-English catalog, the test additionally verifies that each of the 180 OpenAPI values is present, non-empty, and no longer identical to its English fallback. If a technical-only value must legitimately equal English, it is recorded in a narrowly scoped, documented allowlist rather than weakening the catalog-wide assertion.

## Validation

The work follows a red-green verification cycle:

1. Update the translation contract to reject the current English fallback.
2. Run it and confirm that it fails for the expected untranslated locale and key.
3. Add native translations to all 27 catalogs.
4. Run the focused contract until all catalogs pass.
5. Run `php -l` for every changed catalog.
6. Run the full module suite with `php Tests/run-all.php`.
7. Run the module package check with `./scripts/package-module.sh /tmp/module-build` using a safe temporary target.
8. Run `git diff --check` and inspect the final diff to ensure unrelated user changes were not overwritten.

A live PBX deployment is not required for a translation-only change and will not be performed without explicit authorization. If a live environment is later used, representative Swagger operations should be inspected in several non-English locales, including one Latin-script, one Cyrillic-script, and one CJK locale.

## Compatibility and boundaries

This task does not edit OpenAPI controllers, data structures, Core metadata generation, REST processors, or the macOS worker. It does not attempt to translate the rest of the module UI. Existing unrelated worktree changes are preserved.

The output is complete when every available MikoPBX locale displays native STT Swagger documentation without raw keys or blanket English fallback, and all focused and full verification commands pass.
