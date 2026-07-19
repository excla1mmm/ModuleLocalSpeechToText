# Local STT OpenAPI All-Language Translations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the English OpenAPI fallback with native translations for all 27 additional `ModuleLocalSpeechToText` locales while preserving all technical literals and API behavior.

**Architecture:** Keep translation data in the existing `Messages/*.php` catalogs and derive the governed 180-key OpenAPI set from the module controllers and data structures in the existing contract test. Tighten that test first so the current fallback is demonstrably rejected, then translate the catalogs in reviewable locale groups and finish with full syntax, contract, and package verification.

**Tech Stack:** PHP message catalogs, standalone PHP contract checks, MikoPBX module packaging scripts.

## Global Constraints

- Treat `Messages/ru.php` as the semantic source and `Messages/en.php` as the technical cross-reference.
- Translate only the 180 OpenAPI keys governed by `Tests/check-openapi-translations.php`; do not rewrite unrelated UI copy.
- Preserve all PHP keys, key order, placeholders, field names, enum literals, event names, endpoint references, and technical abbreviations exactly.
- Localize human-readable worker, queue, lease, heartbeat, transcript, and recognition-job terminology naturally for each target language.
- Do not change REST routes, operation IDs, payloads, schemas, authorization, processors, or Core metadata behavior.
- Preserve all pre-existing worktree changes and do not stage or commit implementation files in this shared dirty workspace.
- Keep Portuguese and Brazilian Portuguese, and Simplified and Traditional Chinese, as distinct localized variants.

---

### Task 1: Make native OpenAPI localization a tested contract

**Files:**
- Modify: `Tests/check-openapi-translations.php`
- Test: `Tests/check-openapi-translations.php`

**Interfaces:**
- Consumes: `$expectedEnglish`, `$referencedSchemaKeys`, `$messageFiles`, and each included catalog array.
- Produces: a deterministic assertion that all 180 governed OpenAPI values in every non-Russian, non-English catalog are non-empty and no longer equal to the English fallback.

- [ ] **Step 1: Replace the fallback assertion with the native-localization assertion**

Build the governed key set once and validate every value:

```php
$openApiKeys = array_values(array_unique(array_merge(
    array_keys($expectedEnglish),
    array_keys($referencedSchemaKeys)
)));

foreach ($openApiKeys as $key) {
    expectOpenApiTranslation(
        isset($messages[$key]) && trim((string)$messages[$key]) !== '',
        basename($messageFile) . " has an empty OpenAPI translation for {$key}"
    );
    expectOpenApiTranslation(
        $messages[$key] !== $english[$key],
        basename($messageFile) . " still uses the English OpenAPI fallback for {$key}"
    );
}
```

- [ ] **Step 2: Run the focused check and verify RED**

Run:

```bash
php Tests/check-openapi-translations.php
```

Expected: failure on the first non-English catalog, identifying a specific key that still equals English.

- [ ] **Step 3: Inspect the diff without committing shared work**

Run:

```bash
git diff -- Tests/check-openapi-translations.php
```

Expected: only the fallback policy has changed; Russian/English exact-copy assertions, catalog parity, and metadata-reference discovery remain intact.

### Task 2: Translate Azerbaijani and Central/Northern/Western European catalogs

**Files:**
- Modify: `Messages/az.php`
- Modify: `Messages/cs.php`
- Modify: `Messages/da.php`
- Modify: `Messages/de.php`
- Modify: `Messages/fi.php`
- Modify: `Messages/fr.php`
- Modify: `Messages/hu.php`
- Modify: `Messages/nl.php`
- Modify: `Messages/pl.php`
- Modify: `Messages/sv.php`

**Interfaces:**
- Consumes: the 180 governed Russian values and their English technical equivalents.
- Produces: ten catalogs with native OpenAPI prose and unchanged PHP structure.

- [ ] **Step 1: Replace only governed fallback values**

For each listed catalog, translate the value for every key in `$openApiKeys`. Preserve literals such as `lease_token`, `job: null`, `transcript.completed`, `created_at:event_id`, `SHA-256`, `ANSWERED`, and `workers:getProcessingSettings` byte-for-byte.

- [ ] **Step 2: Validate syntax for the locale group**

Run:

```bash
for file in Messages/{az,cs,da,de,fi,fr,hu,nl,pl,sv}.php; do php -l "$file" || exit 1; done
```

Expected: `No syntax errors detected` for all ten files.

- [ ] **Step 3: Re-run the contract and confirm progress**

Run:

```bash
php Tests/check-openapi-translations.php
```

Expected: the first reported fallback moves to a locale outside this completed group.

### Task 3: Translate Southern and Eastern European catalogs

**Files:**
- Modify: `Messages/el.php`
- Modify: `Messages/es.php`
- Modify: `Messages/hr.php`
- Modify: `Messages/it.php`
- Modify: `Messages/pt.php`
- Modify: `Messages/pt_BR.php`
- Modify: `Messages/ro.php`
- Modify: `Messages/tr.php`
- Modify: `Messages/uk.php`

**Interfaces:**
- Consumes: the same governed bilingual source set and invariant literal policy.
- Produces: nine additional catalogs with locale-specific technical prose, including distinct European and Brazilian Portuguese wording.

- [ ] **Step 1: Replace only governed fallback values**

Translate all 180 OpenAPI values in each listed catalog. Use natural locale-specific grammar while retaining the exact distinction between recording-level results and aggregated logical-call revisions.

- [ ] **Step 2: Validate syntax for the locale group**

Run:

```bash
for file in Messages/{el,es,hr,it,pt,pt_BR,ro,tr,uk}.php; do php -l "$file" || exit 1; done
```

Expected: `No syntax errors detected` for all nine files.

- [ ] **Step 3: Re-run the contract and confirm progress**

Run:

```bash
php Tests/check-openapi-translations.php
```

Expected: the first reported fallback moves to a locale in the remaining Asian/Caucasian group.

### Task 4: Translate Asian and Caucasian catalogs

**Files:**
- Modify: `Messages/ja.php`
- Modify: `Messages/ka.php`
- Modify: `Messages/ko.php`
- Modify: `Messages/mn.php`
- Modify: `Messages/th.php`
- Modify: `Messages/vi.php`
- Modify: `Messages/zh_Hans.php`
- Modify: `Messages/zh_TW.php`

**Interfaces:**
- Consumes: the governed bilingual source set and the same protected literal set.
- Produces: the final eight native catalogs, including separate Simplified and Traditional Chinese copy.

- [ ] **Step 1: Replace only governed fallback values**

Translate every governed OpenAPI value using natural technical sentence structure for the target language. Do not transliterate machine-readable fields or protocols.

- [ ] **Step 2: Validate syntax for the locale group**

Run:

```bash
for file in Messages/{ja,ka,ko,mn,th,vi,zh_Hans,zh_TW}.php; do php -l "$file" || exit 1; done
```

Expected: `No syntax errors detected` for all eight files.

- [ ] **Step 3: Verify GREEN for the focused contract**

Run:

```bash
php Tests/check-openapi-translations.php
```

Expected: `OpenAPI translations contract OK`.

### Task 5: Review translation invariants and variants

**Files:**
- Review: `Messages/*.php`
- Test: `Tests/check-openapi-translations.php`

**Interfaces:**
- Consumes: all 29 completed catalogs.
- Produces: evidence that technical literals survived translation and locale variants are not blanket duplicates.

- [ ] **Step 1: Validate exact catalog parity through PHP**

Run the focused contract, which parses every catalog and compares its ordered key set with `ru.php`:

```bash
php Tests/check-openapi-translations.php
```

Expected: `OpenAPI translations contract OK`.

- [ ] **Step 2: Verify protected technical literals**

Run a PHP comparison over the 180-key set to report source literals missing from target translations:

```bash
php -r 'require "Tests/check-openapi-translations.php"; $keys=array_values(array_unique(array_merge(array_keys($expectedEnglish),array_keys($referencedSchemaKeys)))); $literals=["STT","PBX","API","CDR","DID","HTTP","RMS","VAD","SHA-256","worker_uid","lease_token","start_ms","end_ms","next_cursor","transcript.completed","created_at:event_id","legacy_unresolved","ANSWERED","workers:getProcessingSettings"]; $errors=[]; foreach($messageFiles as $file){if(str_ends_with($file,"/ru.php")||str_ends_with($file,"/en.php")){continue;} $messages=include $file; foreach($keys as $key){foreach($literals as $literal){if(str_contains($english[$key],$literal)&&!str_contains($messages[$key],$literal)){$errors[]=basename($file).":".$key.":".$literal;}}}} if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);} echo "Protected OpenAPI literals OK".PHP_EOL;'
```

Expected: `Protected OpenAPI literals OK`. Review every reported difference and correct accidental translation of identifiers.

- [ ] **Step 3: Review representative workflows in every locale**

Inspect at minimum the localized tag, `GetNextJobDesc`, `SubmitResultDesc`, `GetCallTranscriptDesc`, `ListCallTranscriptEventsDesc`, `ApiParamLeaseToken`, `ApiFieldNextCursor`, and `ApiFieldCompletenessStatus` for each catalog.

- [ ] **Step 4: Check required locale variants**

Run:

```bash
php -r 'require "Tests/check-openapi-translations.php"; $keys=array_values(array_unique(array_merge(array_keys($expectedEnglish),array_keys($referencedSchemaKeys)))); foreach([["pt","pt_BR"],["zh_Hans","zh_TW"]] as [$left,$right]){$a=include "Messages/{$left}.php";$b=include "Messages/{$right}.php";$different=false;foreach($keys as $key){if($a[$key]!==$b[$key]){$different=true;break;}}if(!$different){fwrite(STDERR,"{$left} and {$right} OpenAPI translations are identical".PHP_EOL);exit(1);}} echo "Locale variants OK".PHP_EOL;'
```

Expected: `Locale variants OK`.

### Task 6: Run complete verification

**Files:**
- Verify: `Messages/*.php`
- Verify: `Tests/check-openapi-translations.php`
- Verify: entire module package

**Interfaces:**
- Consumes: completed catalog and contract changes.
- Produces: fresh syntax, test-suite, package, and whitespace evidence.

- [ ] **Step 1: Lint all translation catalogs**

Run:

```bash
for file in Messages/*.php; do php -l "$file" || exit 1; done
```

Expected: all 29 files pass.

- [ ] **Step 2: Run the complete module test suite**

Run:

```bash
php Tests/run-all.php
```

Expected: `All module contract tests passed`.

- [ ] **Step 3: Build the module package**

Run with an explicit safe target:

```bash
build_dir="$(mktemp -d /tmp/local-stt-openapi-i18n.XXXXXX)"
./scripts/package-module.sh "$build_dir"
```

Expected: packaging exits with status 0 and produces the module archive or package tree under the temporary directory.

- [ ] **Step 4: Check whitespace and final scope**

Run:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; changes remain limited to the already-active OpenAPI work, the 27 translated catalogs, the translation contract, and the approved design/plan documents.
