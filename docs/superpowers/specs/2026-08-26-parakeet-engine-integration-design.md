# Parakeet Engine Integration Design

## Goal

Add FluidAudio Parakeet TDT 0.6B v3 as a second selectable speech-to-text model while preserving the existing ModuleLocalSpeechToText user experience, Worker API v2, queue lifecycle, transcript schema, result storage, transcript events, and downstream integrations.

The Models marketplace gains one built-in Parakeet row. Selecting it changes only which local macOS transcription engine processes subsequently claimed jobs. Existing WhisperKit models and all previously stored transcripts remain valid and readable without data migration.

## Scope and Constraints

- Keep ModuleLocalSpeechToText as the global source of the selected model, language, vocabulary, and processing settings.
- Keep the existing REST API v2 and processing-settings schema v1.
- Keep `worker_uid`, `job_id`, `lease_token`, heartbeat, release, retry, reconnect, and idempotent result-submission semantics.
- Keep transcript segment timestamps as integer milliseconds with the existing `channel` and `speaker` fields.
- Keep `m_STTJob`, `m_STTResult`, transcript revisions, transcript events, and AI Supervisor integration unchanged.
- Keep all model files inside the Local STT Worker Application Support tree.
- Keep custom vocabulary functional for both WhisperKit and Parakeet.
- Do not add compatibility negotiation, capability advertising, upgrade notices, or fallback behavior for unreleased Worker versions.
- Do not redesign the module UI or add Parakeet-specific settings controls.
- The primary agent implements the work. Subagents may be used only after implementation for independent review.

## Chosen Architecture

Use a transcription-engine registry inside LocalSTTWorkerMac. `WorkerService` resolves one engine from the immutable descriptor carried by the claimed job, then uses the same engine instance for model preparation and transcription:

```text
STTJob
  model_engine / model_id / model_repo / model_artifact_type
        |
        v
TranscriptionEngineRegistry
        |-- WhisperKitTranscriptionEngine
        `-- ParakeetTranscriptionEngine
                  |-- ParakeetModelManager
                  |-- ParakeetPhraseBiasingCompiler
                  `-- ParakeetTranscriptAssembler
        |
        v
TranscriptionResultNormalizer
        |
        v
existing result submission and PBX storage
```

Do not add Parakeet branches to `WhisperKitTranscriptionEngine`, and do not create a second Worker process or application. Those alternatives either couple unrelated decoding behavior or add unnecessary routing and deployment complexity.

## Model Descriptor and Engine Resolution

Introduce a strict Worker-side model descriptor derived from `STTJob`:

```swift
enum TranscriptionEngineID: String, Sendable {
    case whisperKit
    case parakeet
}

struct RemoteModelDescriptor: Equatable, Sendable {
    let engine: TranscriptionEngineID
    let modelID: String
    let repository: String
    let artifactType: String
    let displayName: String
}
```

Only these engine/artifact combinations are accepted:

| `model_engine` | `model_artifact_type` | Engine |
| --- | --- | --- |
| `whisperKit` | `whisperkit-coreml` | `WhisperKitTranscriptionEngine` |
| `parakeet` | `parakeet-coreml` | `ParakeetTranscriptionEngine` |

Missing or unknown combinations are configuration errors. They must not silently resolve to the default WhisperKit model.

`TranscriptionEngineRegistry` owns lazy engine instances. `WorkerService` asks the registry for an engine once per claimed job and retains that local reference through `prepareModel` and `transcribe`. Processing remains sequential. When the selected engine family changes between jobs, the registry releases the previous Core ML runtime references before preparing the new engine so the Worker does not intentionally retain both heavy model families in memory.

Tests may inject a registry backed by static fake engines; the production registry creates the two real engines.

## Audio Preparation Contract

Extend `PreparedAudio` with complete prepared tracks while preserving the existing segmented representation:

```swift
struct PreparedAudioTrack: Sendable {
    let fileURL: URL
    let duration: Double
    let channel: String?
    let speaker: String?
}

struct PreparedAudio: Sendable {
    var tracks: [PreparedAudioTrack]
    var segments: [AudioSegment]
    var duration: Double?
    var channelCount: Int
    var technicalInfo: [String: JSONValue]
    // existing lazy fallback provider remains
}
```

`AudioPipeline` continues to create normalized 16 kHz mono WAV files and to split stereo recordings into `left` and `right`. Each mono WAV becomes one complete track. Existing VAD and fixed-window segmentation produces `segments` from those same files.

- WhisperKit continues to consume `segments`; its decoding behavior must not change.
- Parakeet consumes complete `tracks`; it must not decode the audio as independent 15-second Worker segments.
- Audio normalization and channel splitting apply to both engines because they happen before engine selection.
- The existing VAD, segment duration, and overlap settings remain WhisperKit decoding settings. Parakeet uses its own long-form path.

The complete track representation also covers a silent channel. Silence producing no transcript is a valid empty channel, not an engine failure.

## FluidAudio Dependency and Parakeet Profile

Add the FluidAudio Swift package to the application and test targets at an exact reviewed revision based initially on release `0.15.6`. Do not use a floating branch or minor-version range. Any later FluidAudio update requires the Worker test suite and Russian-call benchmark to be rerun.

The initial integration pins a minimal LocalSTTWorker-owned FluidAudio fork revision because FluidAudio does not yet expose phrase boosting through its public Parakeet APIs. The fork must contain only the generic TDT phrase-biasing API, its decoder implementation, and tests. Submit the change upstream; after an equivalent API is released, switch back to the upstream package and delete the fork delta. The Worker repository records the exact upstream base and fork commit.

The built-in Parakeet engine uses:

- FluidAudio `AsrModelVersion.v3`;
- the multilingual Parakeet TDT 0.6B v3 Core ML artifacts;
- 16 kHz mono Float32 samples;
- `melChunkContext = false` to avoid multilingual long-form English-prior drift;
- `dualDecodeArbitration = false`;
- `seamGapRepair = true`;
- FluidAudio's default CPU and Neural Engine configuration;
- full-track long-form transcription, one channel at a time.

These values form the fixed engine profile. They are not exposed as module settings.

An explicit supported `language_hint` is mapped to the corresponding FluidAudio language filter. The accepted Parakeet set is `bg`, `hr`, `cs`, `da`, `nl`, `en`, `et`, `fi`, `fr`, `de`, `el`, `hu`, `it`, `lv`, `lt`, `mt`, `pl`, `pt`, `ro`, `sk`, `sl`, `es`, `sv`, `ru`, and `uk`. An explicit value outside this set is a configuration error for a Parakeet job rather than a silent switch to automatic detection. `auto` or a missing hint does not install a language filter. For result reporting after an automatic run, use Apple's `NLLanguageRecognizer` on the final non-empty text and accept only a language code from the same set. Report `und` when the text is empty, too short, or not confidently recognized. Language reporting must not alter transcript text.

## Scoped Model Storage

Keep the existing WhisperKit model layout untouched. Add engine-specific Parakeet directories below the Worker's existing models root:

```text
Application Support/Local STT Worker/Models/
|-- existing WhisperKit cache layout
`-- Parakeet/
    |-- ASR/
    |   `-- parakeet-tdt-0.6b-v3-coreml/
    `-- Tokenizer/
        `-- parakeet-tdt-0.6b-v3-tokenizer.json
```

Do not use FluidAudio's global `Application Support/FluidAudio` cache.

`ParakeetModelManager` is an actor responsible for:

1. Resolving cache destinations under the Worker models root.
2. Coalescing concurrent prepare requests.
3. Downloading with a progress callback.
4. Validating required Core ML bundles and vocabulary before loading, plus the tokenizer when a non-empty Parakeet vocabulary is requested.
5. Loading staged files with `AsrModels.load`.
6. Reusing a configured `AsrManager` across sequential Parakeet jobs.
7. Dropping runtime references when the registry switches engine family.

The manager must use FluidAudio APIs that accept an explicit destination. An incomplete cache is not considered a valid model. A retry may repair or redownload missing assets, but transcription cannot start until `AsrModels.modelsExist` and the Worker's own containment checks succeed.

Extend `LocalModelStore` so the existing macOS Models screen recognizes the Parakeet ASR and tokenizer folders, reports their sizes, and deletes only descendants of the Worker models root. No model files move during upgrade.

## Custom Vocabulary

### WhisperKit

Keep the existing behavior: normalized `vocabulary_terms` are included in the contextual prompt and converted to WhisperKit prompt tokens. Existing prompt length and term count limits remain.

### Parakeet

Parakeet TDT does not use Whisper-style contextual prompts. Apply multilingual phrase boosting inside the TDT decoder, following NVIDIA NeMo's boosting-tree shallow-fusion design:

```text
vocabulary_terms
    -> normalize and deduplicate
    -> Parakeet v3 BPE tokenization variants
    -> immutable prefix/Aho-Corasick boosting tree
    -> TDT top-64 candidate rescoring at each greedy decode step
    -> ordinary Parakeet token and timing output
```

This is decoding-time context biasing, not transcript replacement. The emitted word must remain acoustically plausible and must be assembled from the same multilingual Parakeet token stream as all other text. Do not run the English-only FluidAudio CTC 110M model and do not replace similar-looking words after transcription.

The pinned FluidAudio revision exposes a public, engine-neutral `PhraseBiasingConfig` accepted by the v3 `AsrManager` transcription methods. Internally it:

1. Requests `top_k_ids` and `top_k_logits` from `JointDecisionv3` whenever phrase biasing or language filtering is active.
2. Tracks boosting-tree state alongside each TDT hypothesis, including suffix fallback after a partial phrase match.
3. Adds a bounded tree score to each top-64 candidate before selecting the token.
4. Applies language-script filtering before phrase scoring so a Russian hint cannot be bypassed by a Latin candidate.
5. Carries phrase state across FluidAudio long-form chunks and resets it only at an utterance boundary.
6. Leaves duration selection, token timestamps, blank handling, decoder LSTM updates, and no-vocabulary behavior unchanged.

`ParakeetPhraseBiasingCompiler` is Worker-owned. It downloads the official `nvidia/parakeet-tdt-0.6b-v3` `tokenizer.json` into the scoped tokenizer directory, verifies a pinned SHA-256, and verifies that its token IDs and pieces match the loaded FluidInference Core ML vocabulary before use. It compiles accepted spelling variants to the FluidAudio boosting-tree value type and caches the result in memory by SHA-256 of the normalized ordered term list plus tokenizer digest.

Rules:

- Preserve the module's maximum of 100 normalized, case-insensitively deduplicated terms.
- Preserve each submitted term's spelling as the canonical configuration form. Phrase biasing does not rewrite completed text, so the actual output spelling remains the token sequence chosen by Parakeet.
- Download and validate `tokenizer.json` only when the first Parakeet job with a non-empty vocabulary is prepared.
- Generate case-preserving lower-, title-, and upper-case variants only when they are distinct; Russian terms remain Cyrillic and use the Parakeet v3 tokenizer.
- Reject a term whose normalized text cannot be represented without `<unk>`; continue with the remaining valid terms and record a count-only warning.
- Cache the immutable compiled tree and safely reuse it for both prepared channels and later jobs with the same vocabulary digest.
- Start with NVIDIA's recommended tree shape (`context_score = 1.0`, `depth_scaling = 2.0`) and a conservative Worker-owned `boosting_tree_alpha`; the alpha is fixed in code for the first release and selected by the Russian-call benchmark rather than exposed in the UI.
- Clamp the total bias contribution and preserve blank-token competition. A phrase candidate absent from FluidAudio's top-64 cannot be forced into the hypothesis.
- Treat phrase boosting as best-effort: it improves the probability of listed terms but does not guarantee them and must not silently mutate completed text.
- Never log vocabulary terms, emitted phrase text, transcript text, audio paths, or model download credentials.
- Record only requested/compiled/rejected term counts, boosted token decision count, completed phrase count, configuration version, and non-sensitive failure codes in `technical_info`.

Failure semantics:

- If vocabulary is non-empty and the tokenizer cannot be downloaded, verified, or matched to the Core ML vocabulary, model preparation fails and the job follows the existing queue retry path. Running without the requested vocabulary would violate the job configuration.
- If all submitted terms are invalid after tokenization, fail preparation with `vocabulary_unusable`; do not silently run an unboosted job.
- A decoder phrase-state or scoring invariant failure fails the transcription job. There is no safe mid-decode fallback because emitted tokens may already have been influenced.
- An empty vocabulary bypasses tokenizer preparation and produces the unmodified FluidAudio decode path.

## Parakeet Transcript Assembly

For each complete track:

1. Load Float32 samples through the existing safe audio reader.
2. Prepare or reuse the compiled phrase-biasing configuration when terms are present.
3. Run Parakeet long-form transcription with that configuration.
4. Convert the resulting token timings to timed words without inventing timestamps.
5. Group timed words into user-facing transcript segments.
6. Attach the track's `channel` and `speaker`.
7. Merge both channel outputs and sort them on the common call timeline.

A segment boundary occurs at the earliest of:

- terminal punctuation (`.`, `?`, or `!`);
- an inter-word pause of at least 800 milliseconds;
- 15 seconds since the first timed word in the segment;
- the end of the track.

The 15-second limit controls result presentation only. It must not cause separate Parakeet decode calls.

Timestamp normalization follows the existing contract:

- convert seconds to integer milliseconds deterministically;
- clamp values to the track and call duration;
- reject non-finite or negative values;
- enforce `end_ms >= start_ms`;
- retain a single zero-based timeline for both channels;
- sort equal-start segments deterministically by channel and end time.

If Parakeet returns empty text, emit no segment for that track. If it returns non-empty text without token timings, preserve the text in one track-length segment and add `word_timings_missing` to warnings. Do not discard a valid transcript solely because detailed timing metadata is absent.

## Shared Result Normalization and Quality Policy

Both engines return the existing `TranscriptionResult`:

```swift
struct TranscriptionResult {
    let transcript: [TranscriptSegment]
    let language: String
    let duration: Double
    let modelUsed: String
    let technicalInfo: [String: JSONValue]
}
```

Add a shared result normalizer after engine transcription. It validates timestamp bounds, deterministic ordering, empty text, pathological repetition, impossible character density, and explicit-Russian script mismatch while preserving `channel` and `speaker`.

Do not move WhisperKit temperature retries, prompt behavior, or known Whisper outro/hallucination catalog into the Parakeet engine. Whisper-specific decisions remain inside `WhisperKitTranscriptionEngine`. Only engine-independent invariants are shared. The existing server-side `TranscriptQualityFilter` remains the final defensive filter and is not changed for this integration.

## Module Marketplace and Selection

Add one built-in `STTSetting::MODEL_PRESETS` entry:

```php
'parakeet-tdt-0.6b-v3' => [
    'repo' => 'FluidInference/parakeet-tdt-0.6b-v3-coreml',
    'model' => 'parakeet-tdt-0.6b-v3',
    'engine' => 'parakeet',
    'artifact_type' => 'parakeet-coreml',
    'label' => 'module_local_speech_to_text_ModelParakeetV3',
    'description' => 'module_local_speech_to_text_ModelParakeetV3Desc',
    'source_url' => 'https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml',
    'profile' => 'module_local_speech_to_text_ModelProfileParakeetV3',
    'best_for' => 'module_local_speech_to_text_ModelParakeetV3BestFor',
    'tradeoff' => 'module_local_speech_to_text_ModelParakeetV3Tradeoff',
    'language' => '25 languages',
    'license' => 'CC BY 4.0',
]
```

Add `parakeet-coreml` to the marketplace artifact label mapping as `Core ML`. Add translated label, description, profile, best-for, and trade-off strings to every module language file using the established translation workflow.

The existing Models table, radio selection, save button, selected-row styling, controller action, and `selected_model` setting remain unchanged. The only visible addition is the Parakeet model row. Keep `large-v3-turbo` as the default model. Keep the custom-model form WhisperKit-only; arbitrary Parakeet repositories and a custom engine selector are non-goals.

## Worker API v2 Payload

Do not change the Worker API version or processing-settings schema.

For WhisperKit, the existing job payload remains unchanged. For Parakeet, the existing optional fields carry:

```json
{
  "model_id": "parakeet-tdt-0.6b-v3",
  "model_repo": "FluidInference/parakeet-tdt-0.6b-v3-coreml",
  "model_engine": "parakeet",
  "model_artifact_type": "parakeet-coreml",
  "model_display_name": "FluidInference/parakeet-tdt-0.6b-v3-coreml/parakeet-tdt-0.6b-v3",
  "whisperkit_model": null
}
```

Update the OpenAPI schema so `whisperkit_model` is explicitly nullable and remains optional. Do not add a parallel `parakeet_model` field; `model_id` is the engine-neutral runtime identifier.

The selected model is snapshotted when the module prepares a successful lease response. An in-progress job completes with its received descriptor. A later job gets the current selection. A released and reclaimed job may therefore receive the newly selected model, matching current behavior.

## Processing Settings

The settings UI and processing-settings schema v1 remain unchanged.

Settings applied to both engines:

- language selection;
- custom vocabulary;
- audio normalization;
- channel splitting and speaker assignment performed by the Worker audio pipeline.

WhisperKit-only settings retained for later switches back to WhisperKit:

- temperature and temperature fallback;
- top-K;
- log-probability and first-token thresholds;
- no-speech threshold;
- VAD decoding segments, maximum segment duration, and overlap.

Parakeet ignores the WhisperKit-only values and reports its fixed non-sensitive configuration in technical diagnostics. Do not hide, disable, rename, or add controls based on the selected engine in this version.

## Result Storage and Existing Records

No database migration is required. Do not alter:

- `m_STTJob`;
- `m_STTResult`;
- call transcript parts or revisions;
- transcript events and cursors;
- transcript list/detail/export schemas;
- AI Supervisor import behavior.

Parakeet submits the same transcript array with integer `start_ms`, `end_ms`, `text`, `channel`, and `speaker`. `model_used` identifies the resolved Parakeet model. Historical WhisperKit values remain untouched and continue to render through the same views and APIs.

`technical_info` retains its common top-level families and adds engine-specific detail without raw transcript or vocabulary content:

```json
{
  "engine": {
    "kind": "Parakeet",
    "runtime": "FluidAudio",
    "runtime_version": "0.15.6",
    "runtime_revision": "<pinned fork commit>"
  },
  "model": {
    "requested": "parakeet-tdt-0.6b-v3",
    "resolved": "FluidInference/parakeet-tdt-0.6b-v3-coreml",
    "language_hint": "ru",
    "detected_language": "ru"
  },
  "audio": {},
  "timing": {},
  "transcription": {},
  "vocabulary": {
    "requested_terms": 12,
    "compiled_terms": 12,
    "rejected_terms": 0,
    "boosted_token_decisions": 7,
    "completed_phrases": 2,
    "configuration_version": 1
  },
  "warnings": []
}
```

Existing server-side diagnostics continue to merge with Worker diagnostics rather than being replaced.

## Failure Semantics

- Main ASR model download, validation, or load failure fails the job through the existing failure submission and retry lifecycle.
- A Parakeet exception on one non-silent channel fails the whole job; do not publish a partial call transcript.
- A silent channel with empty text is successful.
- Missing timings with non-empty text uses the one-segment fallback and warning described above.
- Tokenizer download, digest, vocabulary-identity, or phrase-tree preparation failure with requested vocabulary fails model preparation.
- Phrase-biasing decoder failure fails transcription; it must not restart the track without vocabulary after partially influenced output.
- Unknown engine or incompatible artifact type is a configuration failure with no engine fallback.
- Cancellation continues to release the lease using the existing cancellation path.
- Heartbeats continue during model download and transcription.
- Recording download origin restrictions, temporary-directory containment, cleanup, and secret redaction remain unchanged.

## Verification Strategy

### Module tests

Verify:

- the complete Parakeet preset and the unchanged WhisperKit presets;
- normalization and persistence of the selected Parakeet ID;
- marketplace rendering with exactly one additional built-in row;
- translated model metadata in every supported module locale;
- Parakeet job payload values and nullable `whisperkit_model`;
- unchanged WhisperKit job payload values;
- unchanged Worker API version and processing-settings schema version;
- OpenAPI definitions for the engine-neutral fields;
- unchanged result, transcript, revision, event, and export behavior for historical rows;
- package contents and generated frontend asset parity.

Run:

```bash
php Tests/run-all.php
./scripts/package-module.sh /tmp/module-build
```

### Worker tests

Verify:

- strict descriptor parsing for both engine/artifact pairs and rejection of unknown pairs;
- registry routing and same-instance use for prepare/transcribe;
- preservation of existing WhisperKit tests and output;
- complete mono tracks alongside unchanged WhisperKit segments;
- Parakeet processing for mono and split stereo input;
- word-to-segment grouping at punctuation, 800 ms gaps, 15-second limits, and track end;
- deterministic integer-millisecond rounding, clamping, ordering, channel, and speaker behavior;
- empty tracks and non-empty text without timings;
- explicit and automatic language reporting;
- scoped ASR and tokenizer cache paths, SHA-256 verification, vocabulary identity, and incomplete-model rejection;
- lazy tokenizer loading, exact BPE tokenization, case variants, `<unk>` rejection, vocabulary hash caching, and empty-vocabulary bypass;
- prefix/suffix fallback, overlapping phrases, top-64 rescoring, score clamping, blank competition, chunk-state continuity, and deterministic phrase diagnostics;
- exact token/timing fixture parity for the no-vocabulary Parakeet decoder path against the pinned upstream base;
- absence of transcript, vocabulary terms, tokens, and unsafe paths in logs and diagnostics;
- model download cancellation, job cancellation, lease heartbeat, failure submission, and cleanup;
- unchanged REST result payload encoding.

Run:

```bash
./scripts/ci/check-repository.sh
./scripts/ci/run-shell-contracts.sh
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer \
  xcodebuild -project LocalSTTWorker.xcodeproj \
  -scheme LocalSTTWorkerTests \
  -configuration Debug \
  CODE_SIGNING_ALLOWED=NO test
DEVELOPER_DIR=/Applications/Xcode.app/Contents/Developer \
  xcodebuild -project LocalSTTWorker.xcodeproj \
  -scheme LocalSTTWorker \
  -configuration Release \
  CODE_SIGNING_ALLOWED=NO build
```

### End-to-end acceptance

1. Install matching development builds of the module and Worker.
2. Confirm existing WhisperKit selection still processes and stores a two-channel call.
3. Select Parakeet through the existing Models table.
4. Confirm the next claimed job uses the Parakeet descriptor and downloads only Worker-scoped artifacts.
5. Process mono, stereo, silent-channel, short, and long Russian fixtures.
6. Repeat with an empty vocabulary and with a vocabulary containing known spoken domain terms.
7. Confirm transcript list/detail/export and downstream transcript events remain unchanged.
8. Confirm historical WhisperKit results still render and export identically.
9. Rerun the private 30-call benchmark outside both repositories and compare timing, disagreement, empty-channel, Cyrillic, and repetition metrics.
10. Independently review the final changes with the contract, STT quality, queue resilience, security, and UI review roles. Review agents must not implement or rewrite the feature.

Repository tests must use synthetic or explicitly licensed fixtures. The private PBX call recordings and transcripts must not be committed.

## Technical References

- [NVIDIA NeMo Word Boosting](https://docs.nvidia.com/nemo/speech/nightly/asr/asr_customization/word_boosting.html) defines token-level boosting-tree shallow fusion for greedy RNN-T/TDT decoding and recommends `context_score = 1.0` and `depth_scaling = 2.0` as starting values.
- [NVIDIA NeMo issue 14772](https://github.com/NVIDIA-NeMo/Speech/issues/14772) confirms phrase boosting for `parakeet-tdt-0.6b-v3` and notes that its tokenizer is case-sensitive.
- [FluidInference Parakeet v3 Core ML artifacts](https://huggingface.co/FluidInference/parakeet-tdt-0.6b-v3-coreml/tree/main) provide `JointDecisionv3` with top-K outputs and the matching Core ML vocabulary.
- [Official NVIDIA Parakeet v3 artifacts](https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3/tree/main) provide the authoritative `tokenizer.json` used to compile phrase token sequences.

The English-only FluidAudio CTC vocabulary path is explicitly excluded because it cannot satisfy Russian vocabulary support for the multilingual v3 engine.

## Implementation Order

1. Add Worker-side generic descriptors, registry, and complete prepared tracks while preserving WhisperKit behavior.
2. Add the pinned FluidAudio dependency, scoped model manager, Parakeet transcription, language reporting, and transcript assembly.
3. Add the minimal pinned FluidAudio phrase-biasing patch, Worker compiler/cache, and diagnostics.
4. Add the module preset, translations, payload refinement, OpenAPI update, and UI contract tests.
5. Run repository and PBX acceptance tests.
6. Run independent reviews only after implementation and verification are complete.

The Worker work precedes exposing the module preset so no intermediate development build advertises an unimplemented engine.

## Non-Goals

- Backward compatibility with unreleased Worker builds.
- Worker capability negotiation or a Worker API v3.
- Per-worker or per-extension model selection.
- Automatic WhisperKit fallback after a Parakeet failure.
- Parakeet-specific controls in the module UI.
- Arbitrary custom Parakeet repositories in the custom-model form.
- English-only CTC vocabulary rescoring for multilingual Parakeet.
- Post-transcription fuzzy replacement presented as acoustic vocabulary support.
- Moving or converting existing WhisperKit model files.
- Retranscribing or rewriting historical results during installation.
- Changing queue concurrency, lease duration, recording delivery, transcript retention, or AI Supervisor contracts.
