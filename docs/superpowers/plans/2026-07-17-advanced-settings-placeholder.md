# Advanced Settings Placeholder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a collapsed, empty `Advanced settings` accordion below the CDR history hint without moving or changing existing controls.

**Architecture:** Reuse the existing Semantic UI accordion markup and initialization. Keep the placeholder inside the current settings form so future controls can be added without restructuring, while leaving its content empty now.

**Tech Stack:** Volt, Semantic UI accordion, jQuery, PHP message catalogs, standalone PHP contract tests.

## Global Constraints

- The accordion is immediately after `module_local_speech_to_text_CdrHistoryDaysHint`.
- It is collapsed by default and its content is empty.
- Recognition terms and the save button remain outside and unchanged.
- Translation key: `module_local_speech_to_text_AdvancedSettings`.

---

### Task 1: Accordion Markup and Initialization

**Files:**
- Create: `Tests/check-advanced-settings-placeholder-contract.php`
- Modify: `App/Views/ModuleLocalSpeechToText/index.volt`
- Modify: `public/assets/js/src/module-local-speech-to-text.js`
- Modify: `public/assets/js/module-local-speech-to-text.js`
- Modify: `public/assets/css/module-local-speech-to-text.css`

**Interfaces:**
- Produces: `.stt-advanced-settings-dropdown` Semantic UI accordion.
- Consumes: existing `$.fn.accordion` initialization.

- [ ] Write a failing contract that verifies placement after the CDR hint, empty content, non-active title/content, unchanged vocabulary placement, and initialization in both JavaScript assets.
- [ ] Run `php Tests/check-advanced-settings-placeholder-contract.php` and confirm the missing placeholder failure.
- [ ] Add the styled accordion markup and initialize `.stt-advanced-settings-dropdown` together with `.stt-custom-model-dropdown`.
- [ ] Add minimal vertical spacing CSS.
- [ ] Run the focused contract and confirm it passes.

### Task 2: Localization and Verification

**Files:**
- Modify: every `Messages/*.php`
- Modify: `Tests/check-advanced-settings-placeholder-contract.php`

**Interfaces:**
- Produces: synchronized `module_local_speech_to_text_AdvancedSettings` label.

- [ ] Extend the contract to require the translation key in every catalog and exact key parity with `ru.php`.
- [ ] Run the focused contract and confirm the missing translation failure.
- [ ] Add `Расширенные настройки` to `ru.php` and `Advanced settings` to the remaining catalogs following their current fallback convention.
- [ ] Run PHP syntax checks for every message catalog and the focused contract.
- [ ] Run `php Tests/run-all.php`, `git diff --check`, and verify the working tree contains only intended changes.
