# Advanced Settings Placeholder Design

## Goal

Add a collapsed `Расширенные настройки` section to the STT module settings page as an empty placeholder for future controls.

## Layout and Behavior

The section appears immediately after the CDR processing-period hint and before the existing recognition-terms block. It uses the module's existing Semantic UI styled accordion pattern, is collapsed by default, and contains an empty content container.

No existing setting, recognition-term control, hint, or save button moves into the section. Form submission and stored settings remain unchanged.

## Localization

Add `module_local_speech_to_text_AdvancedSettings` to every `Messages/*.php` catalog. Russian uses `Расширенные настройки`; English and catalogs currently using English fallback use `Advanced settings`.

## Initialization and Styling

Initialize the new accordion alongside the existing custom-model accordion in both JavaScript source and bundled asset. Add only the spacing needed to separate it from the CDR hint and the following recognition-terms block.

## Tests

Add a UI contract that verifies placement, collapsed markup, empty content, translation-key parity, and accordion initialization. Run the complete module contract suite and PHP translation syntax checks.

## Non-Goals

- Do not move any current controls.
- Do not add advanced settings yet.
- Do not change settings persistence, defaults, or validation.
