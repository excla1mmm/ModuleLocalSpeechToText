# STT Worker Download Segment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a module-native macOS STT Worker download segment above worker API-key management.

**Architecture:** Keep the feature presentation-only: Volt renders a disabled Semantic UI action until a verified installer URL is published, module-scoped CSS handles spacing and mobile layout, and the existing PHP message catalogs provide all user-visible copy. No controller, JavaScript, REST, or macOS worker behavior changes.

**Tech Stack:** Volt, Semantic UI, module-scoped CSS, PHP message catalogs, standalone PHP contract tests.

## Global Constraints

- Work in the existing `develop` checkout as explicitly requested.
- Reuse ModuleLocalSpeechToText Semantic UI patterns and `#2185d0` blue button semantics.
- Do not add AI Supervisor gradients, promotional card styling, or a fabricated installer URL.
- Preserve exact key parity across all 29 `Messages/*.php` catalogs.
- Keep the unpublished download action visibly and semantically disabled.

---

### Task 1: Worker download UI contract

**Files:**
- Create: `Tests/check-worker-download-ui-contract.php`

**Interfaces:**
- Consumes: the workers-tab Volt markup, module stylesheet, and message catalogs.
- Produces: a focused executable contract included automatically by `Tests/run-all.php`.

- [ ] **Step 1: Write the failing contract**

Create assertions for the `.stt-worker-download` segment, its placement before `.stt-worker-api-key`, Semantic UI grid/header/button classes, disabled attributes, translation keys, responsive CSS hooks, exact Russian and English copy, and 29-catalog key parity.

- [ ] **Step 2: Run the contract and verify the red state**

Run: `php Tests/check-worker-download-ui-contract.php`

Expected: failure stating that the worker download segment is missing.

- [ ] **Step 3: Leave the failing contract in place for Task 2**

Do not weaken assertions after observing the expected feature-missing failure.

### Task 2: Module-native download segment

**Files:**
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`
- Modify: `public/assets/css/module-local-speech-to-text.css`

**Interfaces:**
- Consumes: Semantic UI `segment`, `stackable grid`, `header`, `blue button`, and existing `.stt-table-muted` styles.
- Produces: `.stt-worker-download`, `.stt-worker-download-action`, and `.stt-worker-download-pending` UI hooks.

- [ ] **Step 1: Add minimal Volt markup**

Insert one `ui segment stt-worker-download` immediately inside the workers tab and before `stt-worker-api-key`. Render a ten/six-column stackable grid, standard download icon, localized title and description, `macOS · Apple Silicon · <localized Source>: files.miko.ru`, and a disabled blue labeled button plus localized publication note.

- [ ] **Step 2: Add minimal module-scoped CSS**

Add only layout and spacing rules. Keep the segment's surface, border, radius, colors, typography, and button treatment owned by Semantic UI. At `max-width: 767px`, left-align the action and make its button full-width.

- [ ] **Step 3: Run the focused contract**

Run: `php Tests/check-worker-download-ui-contract.php`

Expected: translation assertion still fails because the new message keys have not been added yet; the markup and CSS assertions pass.

### Task 3: Translation catalogs

**Files:**
- Modify: `Messages/ru.php`
- Modify: `Messages/en.php`
- Modify: the other 27 `Messages/*.php` catalogs with English fallback values.

**Interfaces:**
- Consumes: four exact keys referenced by the Volt template.
- Produces: non-empty values and exact key parity across every module locale.

- [ ] **Step 1: Add source and English values**

Add `WorkerDownloadTitle`, `WorkerDownloadDescription`, `DownloadWorker`, and `WorkerDownloadPending` keys next to the existing worker UI messages. Use the approved Russian copy and concise native English copy.

- [ ] **Step 2: Synchronize the remaining catalogs**

Add the same four keys with English fallback values at the same logical location in every remaining catalog, without modifying unrelated translations.

- [ ] **Step 3: Verify the focused contract and PHP syntax**

Run: `php Tests/check-worker-download-ui-contract.php && for file in Messages/*.php; do php -l "$file" >/dev/null || exit 1; done`

Expected: `Worker download UI contract OK` and exit code 0.

### Task 4: Full verification

**Files:**
- Verify all modified files.

**Interfaces:**
- Consumes: the completed presentation feature.
- Produces: evidence that module contracts and packaging remain healthy.

- [ ] **Step 1: Run the complete module contracts**

Run: `php Tests/run-all.php`

Expected: `All module contract tests passed`.

- [ ] **Step 2: Run whitespace and diff checks**

Run: `git diff --check && git status --short`

Expected: no whitespace errors; status lists only the intended UI, CSS, translation, test, and design/plan files.
