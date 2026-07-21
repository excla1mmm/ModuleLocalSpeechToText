# Hide Advanced Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hide the STT module's Advanced settings accordion without changing or resetting its processing settings.

**Architecture:** Keep the complete accordion and its form controls in the rendered form, but add the standard HTML `hidden` attribute to its outer container. Extend the existing UI contract so the visibility decision remains intentional and easy to reverse.

**Tech Stack:** Volt templates, Semantic UI, PHP contract tests.

## Global Constraints

- Preserve all processing-settings form controls and their submitted values.
- Do not change persistence, worker APIs, JavaScript, CSS, or translations.
- Restoring the section must require removing only the `hidden` attribute.

---

### Task 1: Hide and verify the Advanced settings accordion

**Files:**
- Modify: `Tests/check-advanced-settings-placeholder-contract.php`
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`

**Interfaces:**
- Consumes: the existing `.stt-advanced-settings-dropdown` form container and UI contract.
- Produces: a preserved accordion whose outer element has the HTML `hidden` attribute.

- [x] **Step 1: Write the failing visibility contract**

Change the existing accordion-markup expression so it requires `hidden` on the outer element:

```php
'/\<div class="ui fluid styled accordion stt-advanced-settings-dropdown" hidden\>\s*'
```

- [x] **Step 2: Run the focused contract and verify RED**

Run: `php Tests/check-advanced-settings-placeholder-contract.php`

Expected: FAIL with `Advanced settings accordion must remain collapsed and contain expert controls` because the view does not yet contain the `hidden` attribute.

- [x] **Step 3: Add the minimal template change**

Change the accordion opening element to:

```html
<div class="ui fluid styled accordion stt-advanced-settings-dropdown" hidden>
```

- [x] **Step 4: Run focused UI contracts and verify GREEN**

Run:

```bash
php Tests/check-advanced-settings-placeholder-contract.php
php Tests/check-transcript-retention-ui-contract.php
php Tests/check-worker-processing-settings-ui-contract.php
```

Expected: all three commands exit successfully and print their `OK` messages.

- [x] **Step 5: Run full verification**

Run:

```bash
php Tests/run-all.php
./scripts/package-module.sh /tmp/module-build
git diff --check
```

Expected: test suite passes, package creation succeeds, and `git diff --check` exits with no output.

- [x] **Step 6: Commit the implementation**

```bash
git add App/Views/ModuleLocalSpeechToText/index.volt Tests/check-advanced-settings-placeholder-contract.php docs/superpowers/plans/2026-07-21-hide-advanced-settings.md
git commit -m "fix: hide advanced STT settings section"
```

### Task 2: Preserve false checkbox values found during review

**Files:**
- Modify: `Tests/check-settings-normalization.php`
- Modify: `Tests/check-admin-settings-write-contract.php`
- Modify: `App/Controllers/LocalSpeechToTextBaseController.php`
- Modify: `Lib/Services/WorkerProcessingSettingsService.php`

**Interfaces:**
- Consumes: Semantic UI form values encoded as the strings `true` or `false`.
- Produces: strict boolean profile values passed to the existing worker-processing settings service.

- [x] **Step 1: Add failing contracts for string booleans and raw posted values**

Cover `false` and `true` normalization and reject controller logic based on `hasPost()`.

- [x] **Step 2: Verify both contracts fail for the identified reasons**

Run `php Tests/check-settings-normalization.php` and `php Tests/check-admin-settings-write-contract.php`.

- [x] **Step 3: Parse the posted checkbox values**

Pass each request value to `WorkerProcessingSettingsService::normalize()` and accept the exact string values `true` and `false` in its boolean normalizer.

- [x] **Step 4: Verify focused contracts pass**

Run the normalization, admin-save, advanced-settings, and worker-processing UI contracts.

- [x] **Step 5: Repeat full verification and commit the review fix**

Run `php Tests/run-all.php`, package the module, run `git diff --check`, and commit the reviewed fix.
