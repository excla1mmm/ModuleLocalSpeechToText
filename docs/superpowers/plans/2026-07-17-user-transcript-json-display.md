# User Transcript JSON Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the transcript card's technical diagnostics JSON with a presentation-only call JSON containing user metadata, model, and every saved phrase.

**Architecture:** Add a focused `TranscriptUserJsonService` that converts already-loaded call metadata and the stored transcript into pretty JSON. The controller supplies presentation metadata, while the Volt view only renders the prepared string. Existing storage, worker payloads, REST schemas, integration output, and technical diagnostics services remain unchanged.

**Tech Stack:** PHP 8.3+, Phalcon MVC, Volt templates, Semantic UI CSS, PHP contract tests.

## Global Constraints

- Change only the JSON displayed on the administrator transcript card.
- Do not change worker-to-PBX payloads, stored `transcript`/`technical_info`, REST schemas, integration responses, transcript events, filtering, or revision behavior.
- Preserve source phrase order and include every valid non-empty saved phrase without merging.
- Render localized participant labels and pretty JSON with unescaped Unicode.
- Keep the existing technical diagnostics storage and integration code intact.

---

### Task 1: Presentation JSON formatter

**Files:**
- Create: `Tests/check-transcript-user-json-service.php`
- Create: `Lib/Services/TranscriptUserJsonService.php`

**Interfaces:**
- Consumes: `array<string, mixed> $call`, stored transcript JSON string, and the module translation service.
- Produces: `TranscriptUserJsonService::transcriptJson(array $call, string $transcript): string`.

- [ ] **Step 1: Write the failing formatter test**

Create a direct PHP test that requires the new service, supplies call metadata plus valid, empty, malformed, left, right, custom-speaker, and no-speaker transcript entries, and asserts this decoded shape:

```php
[
    'call' => [
        'id' => 'call-42',
        'started_at' => '2026-07-17 12:00:00',
        'ended_at' => '2026-07-17 12:02:02',
        'duration_seconds' => 122.088,
        'source_number' => '201',
        'destination_number' => '74950000000',
        'language' => 'ru',
        'model' => 'large-v3-v20240930_turbo',
    ],
    'phrases' => [
        ['speaker' => 'Участник 1', 'start_ms' => 100, 'end_ms' => 1200, 'text' => 'Первая фраза'],
        ['speaker' => 'Участник 2', 'start_ms' => 1300, 'end_ms' => 2200, 'text' => 'Вторая фраза'],
        ['speaker' => 'agent', 'start_ms' => null, 'end_ms' => null, 'text' => 'Без времени'],
        ['speaker' => 'Участник', 'start_ms' => 2300, 'end_ms' => 2600, 'text' => 'Без канала'],
    ],
]
```

Also assert that malformed transcript JSON yields the same normalized call object with an empty `phrases` list, and that the encoded string contains readable Cyrillic rather than Unicode escapes.

- [ ] **Step 2: Run the formatter test and verify RED**

Run:

```bash
php Tests/check-transcript-user-json-service.php
```

Expected: failure because `Lib/Services/TranscriptUserJsonService.php` does not exist.

- [ ] **Step 3: Implement the minimal formatter**

Create `TranscriptUserJsonService` with:

```php
public function transcriptJson(array $call, string $transcript): string
{
    $payload = [
        'call' => $this->normalizedCall($call),
        'phrases' => $this->phrases($transcript),
    ];

    return json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '';
}
```

Implement private normalization helpers that preserve the exact eight call keys, convert unavailable values to `null`, trim text, skip invalid/empty entries, preserve order, emit integer millisecond timestamps or `null`, and map left/right/default speakers through existing translation keys.

- [ ] **Step 4: Run the formatter test and verify GREEN**

Run:

```bash
php Tests/check-transcript-user-json-service.php
```

Expected: `Transcript user JSON service contract OK` and exit code 0.

### Task 2: Transcript-card wiring and presentation

**Files:**
- Create: `Tests/check-transcript-user-json-display.php`
- Modify: `App/Controllers/ModuleLocalSpeechToTextController.php`
- Modify: `App/Views/ModuleLocalSpeechToText/transcript.volt`
- Modify: `public/assets/css/module-local-speech-to-text.css`
- Modify: `Messages/*.php`

**Interfaces:**
- Consumes: `TranscriptUserJsonService::transcriptJson()` from Task 1 and already-loaded `$result`, `$job`, and `$cdr` values.
- Produces: view variable `transcriptJson` and a single collapsed `.stt-transcript-json` details block.

- [ ] **Step 1: Write the failing card contract test**

Create a source-level contract test asserting:

```php
str_contains($controller, 'TranscriptUserJsonService')
str_contains($controller, '$this->view->transcriptJson')
!str_contains($controller, '$this->view->technicalInfo')
str_contains($template, "transcriptJson !== ''")
str_contains($template, "module_local_speech_to_text_TranscriptJson")
!str_contains($template, "technicalInfo['groups']")
!str_contains($template, 'module_local_speech_to_text_TechnicalInformation')
```

Also parse all `Messages/*.php` files and assert every locale contains `module_local_speech_to_text_TranscriptJson`.

- [ ] **Step 2: Run the card test and verify RED**

Run:

```bash
php Tests/check-transcript-user-json-display.php
```

Expected: failure because the card still uses `technicalInfo` and the new translation key does not exist.

- [ ] **Step 3: Wire the formatter into the controller**

Replace the controller's technical-card dependency with `TranscriptUserJsonService`. Build the presentation metadata only from already-loaded records:

```php
$this->view->transcriptJson = $this->transcriptUserJsonService()->transcriptJson([
    'id' => $job instanceof STTJob ? (string)$job->call_id : null,
    'started_at' => $cdr?->start,
    'ended_at' => $cdr?->endtime,
    'duration_seconds' => $result->duration === null ? null : (float)$result->duration,
    'source_number' => $cdr?->src_num,
    'destination_number' => $cdr?->dst_num,
    'language' => $result->language,
    'model' => $result->model_used,
], (string)$result->transcript);
```

Remove only the card's `TranscriptTechnicalInfoService` import/factory usage. Do not edit `TranscriptTechnicalInfoService`, `TranscriptIntegrationService`, REST data structures, models, or result submission.

- [ ] **Step 4: Replace the Volt diagnostics block**

Render only:

```volt
{% if transcriptJson !== '' %}
    <details class="stt-transcript-json">
        <summary>{{ t._('module_local_speech_to_text_TranscriptJson') }}</summary>
        <pre>{{ transcriptJson|e }}</pre>
    </details>
{% endif %}
```

Keep it below the conversation and remove the old diagnostics heading, metric grid, and technical raw JSON from the template.

- [ ] **Step 5: Update focused CSS and translations**

Replace the card-specific technical block selectors with `.stt-transcript-json`, retaining bounded height, readable code font sizing, border, neutral background, and scrolling. Add `module_local_speech_to_text_TranscriptJson` to Russian first as `Показать JSON`, then to every locale with a concise equivalent while preserving keys and PHP syntax.

- [ ] **Step 6: Run focused tests and translation validation**

Run:

```bash
php Tests/check-transcript-user-json-service.php
php Tests/check-transcript-user-json-display.php
for file in Messages/*.php; do php -l "$file" >/dev/null || exit 1; done
```

Expected: both contract tests pass and every translation file has valid PHP syntax.

### Task 3: Full regression verification

**Files:**
- Verify only; no planned production changes.

**Interfaces:**
- Consumes: completed Tasks 1-2.
- Produces: evidence that presentation changed without contract regressions.

- [ ] **Step 1: Run all module tests**

Run:

```bash
php Tests/run-all.php
```

Expected: `All module contract tests passed` with no failed test names.

- [ ] **Step 2: Run package verification**

Run:

```bash
build_dir="$(mktemp -d)"
./scripts/package-module.sh "$build_dir"
```

Expected: package creation succeeds with exit code 0.

- [ ] **Step 3: Inspect the final diff and contract boundary**

Run:

```bash
git diff --check
git status --short
git diff -- Lib/RestAPI Lib/LocalSpeechToTextMain.php Lib/Services/TranscriptIntegrationService.php Models
```

Expected: no whitespace errors, only intended files are modified, and the final contract-boundary diff is empty.

