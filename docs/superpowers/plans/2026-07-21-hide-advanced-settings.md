# Hide Advanced Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hide the STT module's Advanced settings accordion without changing or resetting its processing settings.

**Architecture:** Keep the complete accordion and its form controls in the rendered form, but add the standard HTML `hidden` attribute to its outer container. Extend the existing UI contract so the visibility decision remains intentional and easy to reverse.

**Tech Stack:** Volt templates, Semantic UI, PHP contract tests.

## Global Constraints

- Preserve all processing-settings form controls and their submitted values.
- Do not change persistence, validation, worker APIs, JavaScript, CSS, or translations.
- Restoring the section must require removing only the `hidden` attribute.

---

### Task 1: Hide and verify the Advanced settings accordion

**Files:**
- Modify: `Tests/check-advanced-settings-placeholder-contract.php`
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`

**Interfaces:**
- Consumes: the existing `.stt-advanced-settings-dropdown` form container and UI contract.
- Produces: a preserved accordion whose outer element has the HTML `hidden` attribute.

- [ ] **Step 1: Write the failing visibility contract**

Change the existing accordion-markup expression so it requires `hidden` on the outer element:

```php
'/\<div class="ui fluid styled accordion stt-advanced-settings-dropdown" hidden\>\s*'
```

- [ ] **Step 2: Run the focused contract and verify RED**

Run: `php Tests/check-advanced-settings-placeholder-contract.php`

Expected: FAIL with `Advanced settings accordion must remain collapsed and contain expert controls` because the view does not yet contain the `hidden` attribute.

- [ ] **Step 3: Add the minimal template change**

Change the accordion opening element to:

```html
<div class="ui fluid styled accordion stt-advanced-settings-dropdown" hidden>
```

- [ ] **Step 4: Run focused UI contracts and verify GREEN**

Run:

```bash
php Tests/check-advanced-settings-placeholder-contract.php
php Tests/check-transcript-retention-ui-contract.php
php Tests/check-worker-processing-settings-ui-contract.php
```

Expected: all three commands exit successfully and print their `OK` messages.

- [ ] **Step 5: Run full verification**

Run:

```bash
php Tests/run-all.php
./scripts/package-module.sh /tmp/module-build
git diff --check
```

Expected: test suite passes, package creation succeeds, and `git diff --check` exits with no output.

- [ ] **Step 6: Commit the implementation**

```bash
git add App/Views/ModuleLocalSpeechToText/index.volt Tests/check-advanced-settings-placeholder-contract.php docs/superpowers/plans/2026-07-21-hide-advanced-settings.md
git commit -m "fix: hide advanced STT settings section"
```
