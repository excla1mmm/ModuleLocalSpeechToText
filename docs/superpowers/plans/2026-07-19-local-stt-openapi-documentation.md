# Local STT OpenAPI Documentation Implementation Plan

> **Execution:** Follow test-driven development task by task. Do not change runtime API behavior while improving documentation metadata.

**Goal:** Present every ModuleLocalSpeechToText REST operation in one localized Swagger section with accurate RU/EN documentation, isolated component schemas, and complete call-retrieval examples.

**Architecture:** Keep the existing REST paths, operation IDs, security declarations, processors, and payload envelopes. Consolidate controller tags, explicitly namespace every STT OpenAPI component, enrich the existing `OpenApiSchemaProvider` definitions, and supply module translation keys that the MikoPBX metadata registry resolves at render time.

**Tech Stack:** PHP 8.3 attributes, MikoPBX REST v3 OpenAPI metadata, module `Messages/*.php` catalogs, reflection-based contract tests, live PBX Swagger verification.

## Global constraints

- Preserve all 13 methods, paths, operation IDs, and `PBXApiResult` envelopes.
- Use one controller tag value: `Local Speech To Text` (`rest_tag_LocalSpeechToText`).
- Prefix every STT component schema with `LocalSTT`; never register generic `Job`, `Worker`, `Transcript`, or `CallTranscript` components.
- Treat `/transcripts` as recording-level results and `/call-transcripts/{aggregate_id}` as a logical-call revision.
- Keep AI Supervisor as a consumer, not an owner, of STT transcript endpoints.
- Author native Russian and English copy; copy the English fallback into the other 27 catalogs to preserve exact key parity.
- Use fictional examples only. Do not expose tokens, recording paths, caller data, or production transcripts.
- Do not change MikoPBX Core or ModuleAISupervisor in this task.

---

### Task 1: Strengthen the OpenAPI regression contract

**Files:**
- Modify: `Tests/check-openapi-contract.php`
- Create: `Tests/check-openapi-translations.php`
- Modify: `Tests/run-all.php`

**Contracts:**
- Assert six controllers and 13 operations are loaded.
- Assert every controller tag is exactly `Local Speech To Text`.
- Assert paths/methods and operation IDs remain unchanged.
- Assert detail schema names are `LocalSTTJob`, `LocalSTTWorker`, `LocalSTTTranscript`, and `LocalSTTCallTranscript`.
- Assert every attached component begins with `LocalSTT` and none uses a generic colliding name.
- Assert call transcript detail includes `created_at`, structured `part_manifest`, and structured `transcript` metadata.
- Assert recording-level list/detail and call-level event/detail examples form coherent retrieval workflows.
- Assert approved RU/EN tag and call-operation values and exact message-key parity across all 29 catalogs.

- [ ] Write the focused checks before controller, schema, or catalog changes.
- [ ] Run the checks and confirm they fail for the expected raw tags, generic schema names, missing call fields/examples, and missing translations.
- [ ] Register the new translation check in `Tests/run-all.php` only after its standalone RED result is established.

### Task 2: Consolidate tags and isolate component schemas

**Files:**
- Modify: `Lib/RestAPI/Jobs/Controller.php`
- Modify: `Lib/RestAPI/Workers/Controller.php`
- Modify: `Lib/RestAPI/Transcripts/Controller.php`
- Modify: `Lib/RestAPI/TranscriptEvents/Controller.php`
- Modify: `Lib/RestAPI/CallTranscripts/Controller.php`
- Modify: `Lib/RestAPI/CallTranscriptEvents/Controller.php`

**Changes:**
- Replace the six current tag strings with `Local Speech To Text`.
- Give every formerly automatic detail schema an explicit name:
  - `LocalSTTJob`
  - `LocalSTTWorker`
  - `LocalSTTTranscript`
  - `LocalSTTCallTranscript`
- Replace hard-coded operation/resource text with module translation keys.
- Give worker operations explicit STT terminology so they cannot be confused with AI Supervisor jobs or workers.
- Use valid core response translation keys where they exist and module-specific keys for successful actions or the 413 recording-size response where needed.
- Preserve every operation ID and security declaration.

- [ ] Implement only the metadata required by the failing Task 1 controller assertions.
- [ ] Run `php Tests/check-openapi-contract.php` and confirm the grouping and schema-isolation subset is GREEN.
- [ ] Run PHP syntax checks for all six controllers.

### Task 3: Document worker and job lifecycle payloads

**Files:**
- Modify: `Lib/RestAPI/Jobs/DataStructure.php`
- Modify: `Lib/RestAPI/Jobs/SubmitResultResponseDataStructure.php`
- Modify: `Lib/RestAPI/Jobs/SubmitErrorResponseDataStructure.php`
- Modify: `Lib/RestAPI/Jobs/HeartbeatResponseDataStructure.php`
- Modify: `Lib/RestAPI/Jobs/ReleaseResponseDataStructure.php`
- Modify: `Lib/RestAPI/Workers/DataStructure.php`
- Modify: `Lib/RestAPI/Workers/ProcessingSettingsResponseDataStructure.php`
- Modify: `Tests/check-openapi-contract.php`

**Changes:**
- Document `worker_uid`, `job_id`, `lease_token`, ownership rules, retry lifecycle, heartbeat expiry, range download, and maximum recording-size behavior.
- Add constraints and examples for worker registration, job claim, result/error submission, heartbeat, release, and processing settings.
- Add realistic nested schemas for job data and response objects.
- Provide complete example values for the transcript array and `technical_info` where the core request-body generator cannot preserve nested `items`.
- State that registration `model` is compatibility metadata and does not override server-controlled processing settings.

- [ ] Add focused failing assertions for required descriptions, constraints, nested fields, and examples.
- [ ] Run the focused test and confirm RED for the missing metadata.
- [ ] Enrich the data structures minimally until the focused assertions pass.
- [ ] Re-run `php Tests/check-openapi-contract.php` and syntax-check the changed PHP files.

### Task 4: Document transcript and call retrieval workflows

**Files:**
- Modify: `Lib/RestAPI/Transcripts/DataStructure.php`
- Modify: `Lib/RestAPI/Transcripts/ListDataStructure.php`
- Modify: `Lib/RestAPI/TranscriptEvents/DataStructure.php`
- Modify: `Lib/RestAPI/CallTranscripts/DataStructure.php`
- Modify: `Lib/RestAPI/CallTranscriptEvents/DataStructure.php`
- Modify: `Tests/check-openapi-contract.php`

**Changes:**
- Describe `/transcripts` filters, pagination, result identity, CDR/call metadata, recording availability, model/language, normalized turns, segments, and diagnostics.
- Explain explicitly that a transcript result represents one processed recording and is not guaranteed to be unique per logical call.
- Describe cursor format `created_at:event_id`, ordering, `next_cursor`, idempotent consumption, and event types.
- Add `created_at` to the logical-call response schema to match runtime output.
- Fully describe `aggregate_id`, `call_id`, revision, content/parts hashes, completeness, `is_current`, part manifest, and aggregated transcript.
- Provide linked fictional examples for both workflows:
  1. `/transcripts` → `result_id` → `/transcripts/{result_id}`.
  2. `/call-transcripts/events` → `aggregate_id`/`revision` → `/call-transcripts/{aggregate_id}?revision=N`.

- [ ] Add focused failing assertions for the two workflows and runtime/schema parity.
- [ ] Run the assertions and confirm RED for missing descriptions, examples, and `created_at`.
- [ ] Implement schema metadata until the checks pass.
- [ ] Re-run the OpenAPI contract and PHP syntax checks.

### Task 5: Add RU/EN copy and catalog fallbacks

**Files:**
- Modify: `Messages/ru.php`
- Modify: `Messages/en.php`
- Modify: every other `Messages/*.php`
- Modify: `Tests/check-openapi-translations.php`

**Changes:**
- Add the shared tag translation.
- Add resource, operation, parameter, field, response, pagination, cursor, recording-result, logical-call, and worker-lifecycle keys used by controllers and schemas.
- Use the approved primary call-operation terminology:
  - RU: `Получить список завершённых расшифровок`, `Получить завершённую расшифровку`, `Получить события готовых расшифровок звонков`, `Получить объединённую расшифровку звонка`.
  - EN: `List completed transcriptions`, `Get completed transcription`, `List call transcript events`, `Get aggregated call transcript`.
- Add the previously missing release-job text and module-specific response descriptions.
- Put English fallback values under the same keys in the remaining 27 catalogs.

- [ ] Run `php Tests/check-openapi-translations.php` and confirm RED before editing catalogs.
- [ ] Add Russian and English values, then synchronize English fallbacks to the other catalogs.
- [ ] Run syntax checks for every catalog and confirm exact key parity.
- [ ] Re-run the complete focused OpenAPI and translation contracts.

### Task 6: Full and live verification

**Local verification:**

- [ ] Run `php Tests/run-all.php`.
- [ ] Run `git diff --check`.
- [ ] Run `./scripts/package-module.sh /tmp/module-local-stt-openapi-package`.
- [ ] Review the complete diff against the design specification and confirm no runtime processor/service behavior changed.

**Live PBX verification:**

- [ ] Identify the already configured PBX target without guessing credentials.
- [ ] Deploy/reload only if the existing local workflow permits it and no unrelated environment state would be overwritten.
- [ ] Clear the OpenAPI metadata cache through the established PBX workflow.
- [ ] Open Swagger in Russian and English and confirm one STT section with 13 operations.
- [ ] Inspect representative operations: next job, worker registration, transcript list/detail, call event stream, and aggregated call detail.
- [ ] Confirm the STT next-job response references `LocalSTTJob`, not the AI Supervisor `Job` schema.
- [ ] Send safe read-only requests to transcript list/events/detail when credentials and data are already available.
- [ ] Check relevant PBX logs for new PHP warnings or OpenAPI generation errors, with secrets redacted from the report.
