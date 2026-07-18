# Settings Field Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the controls in each primary STT settings row independently of label wrapping.

**Architecture:** Keep Semantic UI's existing two- and three-column form rows. Mark those rows with a local class and turn each immediate field into a two-row grid: the label occupies the shared flexible top area, while its control is pinned to the bottom.

**Tech Stack:** Volt templates, CSS Grid, PHP contract checks.

## Global Constraints

- Modify only the STT settings form layout; do not alter form names, values, validation, API contracts, or translations.
- Preserve Semantic UI responsive one-column behavior below its existing breakpoint.
- Add a deterministic PHP contract check before the CSS implementation.

---

### Task 1: Align primary settings controls

**Files:**
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt:13-36`
- Modify: `public/assets/css/module-local-speech-to-text.css:after stt-advanced-settings-dropdown styles`
- Create: `Tests/check-settings-field-alignment-contract.php`

**Interfaces:**
- Consumes: Semantic UI `.two.fields` and `.three.fields` layout classes.
- Produces: `.stt-settings-fields` marker class whose direct `.field` children use CSS Grid to place controls on the shared row baseline.

- [ ] **Step 1: Write the failing test**

```php
expectFieldAlignment(
    substr_count($view, 'stt-settings-fields') === 2,
    'Primary settings rows must opt in to field-based alignment'
);
expectFieldAlignment(
    str_contains($css, '.stt-settings-fields > .field')
        && str_contains($css, 'grid-template-rows: minmax(0, 1fr) auto'),
    'Primary settings fields must pin controls below their labels'
);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php Tests/check-settings-field-alignment-contract.php`

Expected: FAIL because the marker class and alignment rule do not yet exist.

- [ ] **Step 3: Write minimal implementation**

```html
<div class="three fields stt-settings-fields">
```

```css
.module-local-speech-to-text .stt-settings-fields > .field {
    display: grid;
    grid-template-rows: minmax(0, 1fr) auto;
}
```

Apply the marker to both primary settings rows only. Keep each field's label and rendered control intact.

- [ ] **Step 4: Run the focused and full contract suite**

Run: `php Tests/check-settings-field-alignment-contract.php && php Tests/run-all.php`

Expected: focused alignment check and every module contract check pass.

- [ ] **Step 5: Commit**

```bash
git add App/Views/ModuleLocalSpeechToText/index.volt public/assets/css/module-local-speech-to-text.css Tests/check-settings-field-alignment-contract.php docs/superpowers/plans/2026-07-18-settings-field-alignment.md
git commit -m "fix: align STT settings controls by field"
```
