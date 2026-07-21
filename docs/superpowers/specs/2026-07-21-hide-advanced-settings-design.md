# Hide Advanced Settings Design

## Goal

Hide the `Advanced settings` accordion from the STT module settings page while preserving the existing processing-settings values and server-to-worker behavior.

## Design

Add the standard HTML `hidden` attribute to the accordion's outer container in `App/Views/ModuleLocalSpeechToText/index.volt`. The controls remain in the settings form and continue to submit their current values, so saving visible settings does not reset the hidden processing profile.

The existing markup, JavaScript initialization, CSS, translations, form fields, persistence, and worker APIs remain unchanged. Restoring the section requires removing the single `hidden` attribute.

The admin save path must parse the submitted values of the hidden boolean controls instead of treating field presence as `true`. This preserves stored `false` values when Semantic UI submits unchecked checkboxes as the string `false`; it does not change the normalized profile or worker payload.

## Tests

Update the advanced-settings UI contract to require the `hidden` attribute while retaining its existing checks for the preserved controls and support assets. Add regression coverage for string boolean normalization and controller value parsing. Run the focused UI contracts, the complete PHP test suite, and the module packaging check.

## Non-Goals

- Do not remove or disable processing settings on the server.
- Do not reset stored processing-settings values.
- Do not change the worker settings payload or APIs.
- Do not remove the preserved accordion markup, styling, JavaScript, or translations.
