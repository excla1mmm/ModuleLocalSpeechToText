# Local STT OpenAPI Documentation Design

## Goal

Make every `ModuleLocalSpeechToText` REST operation discoverable and usable from the MikoPBX Swagger UI. The module must appear under one localized section with clear operation names, descriptions, parameters, response schemas, and realistic examples in Russian and English.

This work documents the existing API contract. It does not change REST paths, HTTP methods, operation IDs, authentication, worker lease rules, request payloads, or runtime response envelopes.

## Scope and API inventory

All 13 module operations use the single OpenAPI tag value `Local Speech To Text`, which the MikoPBX generator resolves through `rest_tag_LocalSpeechToText`:

| Area | Method and path | Purpose |
|---|---|---|
| Worker jobs | `GET /jobs:getNext` | Lease the next STT recognition job |
| Worker jobs | `GET /jobs/{id}:download` | Download the leased recording, including byte-range responses |
| Worker jobs | `POST /jobs/{id}:submitResult` | Submit the completed transcript and diagnostics |
| Worker jobs | `POST /jobs/{id}:submitError` | Report recognition failure |
| Worker jobs | `POST /jobs/{id}:heartbeat` | Extend the active job lease |
| Worker jobs | `POST /jobs/{id}:release` | Release the job for retry |
| Workers | `POST /workers:register` | Register or refresh an STT worker |
| Workers | `GET /workers:getProcessingSettings` | Read server-controlled recognition settings |
| Transcripts | `GET /transcripts` | List completed recording-level transcript results |
| Transcripts | `GET /transcripts/{result_id}` | Read one completed transcript with normalized turns and segments |
| Transcript events | `GET /transcripts/events` | Synchronize recording-level transcript completion events |
| Call transcripts | `GET /call-transcripts/{aggregate_id}` | Read an immutable logical-call transcript revision |
| Call transcript events | `GET /call-transcripts/events` | Synchronize completed or updated logical-call revisions |

The base path remains `/pbxcore/api/v3/module-local-speech-to-text`.

## Swagger grouping and localized presentation

Every controller uses exactly the same tag value. The tag resolves through the module message catalogs as:

- Russian: `Локальная транскрибация (STT)`
- English: `Local speech-to-text (STT)`

Swagger must not display raw values such as `rest_tag_LocalSpeechToTextWorkers` or untranslated module keys. Operation summaries are action-oriented and identify the resource precisely. The Russian and English summaries use native wording rather than literal machine translation.

The primary call-retrieval operations are named:

| Russian | English |
|---|---|
| Получить список завершённых расшифровок | List completed transcriptions |
| Получить завершённую расшифровку | Get completed transcription |
| Получить события готовых расшифровок звонков | List call transcript events |
| Получить объединённую расшифровку звонка | Get aggregated call transcript |

The remaining operations distinguish STT recognition jobs and STT workers from AI-analysis jobs and AI workers.

## Retrieving calls from STT

The documentation presents two separate, explicit workflows because the resources have different cardinality and stability guarantees.

### Recording-level results

`GET /transcripts` returns completed STT results ordered newest first. It supports `limit`, `offset`, `date_from`, and `date_to` and includes `result_id`, `call_id`, CDR identity, call date, duration, source, destination, DID, disposition, recording availability, language, and model.

`GET /transcripts/{result_id}` returns the selected result with normalized speaker turns, raw segments, readable text, and technical diagnostics.

A result represents one processed recording. A logical call may therefore have multiple results. The documentation and examples must not describe this collection as one unique row per call.

### Aggregated logical calls

`GET /call-transcripts/events?cursor=0:0&limit=100` is the discovery and synchronization interface. Each event exposes `aggregate_id`, `call_id`, `revision`, content hashes, completeness state, creation time, and a cursor. Consumers persist `next_cursor` and use it in the next poll.

`GET /call-transcripts/{aggregate_id}` returns the current revision. Supplying `?revision=N` returns that immutable historical revision. The response documents the part manifest, combined transcript, revision identity, hashes, completeness, current-state flag, and creation time.

Examples show the complete sequence:

1. Read the event stream from a known cursor.
2. Take `aggregate_id` and `revision` from an event.
3. Fetch the logical call revision.
4. Persist `next_cursor` only after the returned events have been handled successfully.

There is currently no collection-level `GET /call-transcripts` that lists unique logical calls. Adding one is outside this documentation-only change and requires a separate API design.

## Boundary with AI Supervisor

STT remains the source of truth for recordings, recognition jobs, transcript results, logical-call revisions, and transcript event streams. AI Supervisor owns imported transcript references, AI-analysis jobs, analysis workers, results, dashboards, and review workflows.

AI Supervisor consumes these read-only STT integration operations:

- `GET /transcripts/events`
- `GET /transcripts/{result_id}`
- `GET /call-transcripts/events`
- `GET /call-transcripts/{aggregate_id}?revision=N`

These operations remain documented under the STT tag. Their descriptions identify downstream synchronization and AI Supervisor as a known consumer without implying that AI Supervisor owns the endpoints.

The modules already have distinct URL namespaces and operation IDs. No routes are moved or duplicated.

## OpenAPI schema isolation

MikoPBX registers component schemas in one global OpenAPI namespace. Automatically generated STT names `Job` and `Worker` collide with the AI Supervisor schemas of the same names; registration order can consequently make an STT operation display an AI response example.

Every STT data schema receives an explicit `LocalSTT`-prefixed component name. At minimum the generic detail schemas become:

- `LocalSTTJob`
- `LocalSTTWorker`
- `LocalSTTTranscript`
- `LocalSTTCallTranscript`

Existing explicit names such as `LocalSTTSubmitResultResponse`, `LocalSTTHeartbeatResponse`, and `LocalSTTCallTranscriptEventListResponse` remain stable unless a contract check identifies an inaccurate schema. No new generic component named `Job`, `Worker`, `Transcript`, or `CallTranscript` may be registered by this module.

This is a module-local correction. AI Supervisor and the MikoPBX core schema-name generator are not changed.

## Descriptions, constraints, and examples

Each operation documents:

- intended caller and lifecycle position;
- required and optional parameters;
- validation constraints, accepted formats, enums, defaults, and limits;
- lease ownership requirements for worker job operations;
- pagination or cursor behavior;
- response fields and meaningful error responses;
- a realistic request and successful response example using internally consistent IDs and cursors.

Examples use safe fictional data. They do not contain real API keys, bearer tokens, recording paths, caller data, or transcript content from an installed PBX.

The schema must match runtime output. Known omissions such as `created_at` on a logical-call transcript are added to documentation. Complex transcript, event, processing-setting, and worker-job payloads receive nested item schemas where supported by the current generator and complete examples where the generator cannot express the full nested request structure.

Response descriptions use valid localized MikoPBX response keys. Missing module-specific cases, including release-job copy and the recording-size error, receive module translation keys instead of exposing raw keys such as `rest_response_200_ok`.

The worker registration `model` parameter is documented according to its actual server behavior; documentation must not claim that it overrides server-controlled processing settings.

## Translation policy

Russian and English receive authored, reviewed OpenAPI copy for the tag, resources, operations, parameters, fields, and module-specific responses.

Every other `Messages/*.php` catalog receives the same new key set with English fallback values. This preserves catalog parity and prevents raw translation keys from appearing when the PBX UI uses another locale. Native translations for those locales are a later localization task.

Hard-coded user-visible English currently embedded in STT OpenAPI data structures is replaced with translatable module keys where the metadata system supports translation.

## Compatibility and security

The implementation preserves:

- the `PBXApiResult` JSON envelope;
- localhost and bearer-token resource security declarations;
- worker identity, `lease_token`, and job ownership checks;
- recording download path validation and byte-range semantics;
- cursor ordering and idempotent event consumption;
- immutable logical-call revision behavior;
- stable operation IDs used by generated clients.

No transcript text, worker secret, or recording URL is newly exposed by the documentation.

## Verification

Automated OpenAPI contract checks assert:

- all six controllers use the single `LocalSpeechToText` tag;
- all 13 operations remain present with unchanged paths, methods, and operation IDs;
- every STT component name is explicit and begins with `LocalSTT`;
- `Job`, `Worker`, `Transcript`, and `CallTranscript` are not registered by STT;
- call-list, transcript-detail, call-event, and logical-call schemas expose their documented fields;
- call retrieval examples contain a coherent `result_id` or `aggregate_id`/`revision` sequence;
- required translation keys exist, Russian and English copy matches the approved terminology, and all catalogs have identical key sets;
- no raw tag or response translation key appears in generated OpenAPI metadata.

The full module contract suite and package build run after focused checks. A live PBX verification clears the OpenAPI metadata cache, opens Swagger in Russian and English, confirms one STT section, inspects representative worker and call operations, and sends safe read-only requests to the transcript list, event stream, and detail endpoints.
