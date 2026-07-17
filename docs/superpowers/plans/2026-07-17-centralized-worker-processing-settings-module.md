# Centralized Worker Processing Settings — Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make ModuleLocalSpeechToText the authoritative store and authenticated API provider for one global twelve-field worker processing profile.

**Architecture:** A focused WorkerProcessingSettingsService owns canonical defaults, strict validation, SQLite persistence, dirty-flag fan-out, and atomic profile fetches. The existing settings form renders the profile in the Advanced settings accordion; jobs:getNext exposes each worker's boolean flag, and workers:getProcessingSettings returns the complete profile and clears only that worker's flag.

**Tech Stack:** PHP 8.3, Phalcon ORM/forms, SQLite, MikoPBX REST v3/OpenAPI attributes, Volt, Semantic UI/jQuery, module contract scripts.

## Global Constraints

- One global profile applies to all registered workers; there are no groups or per-worker overrides.
- The profile contains exactly the twelve fields and defaults from the approved design specification.
- API durations are integer milliseconds; the UI displays seconds and converts before saving.
- has_settings_changed is stored per worker and is the only synchronization marker.
- Do not add settings_revision, applied_settings_revision, ETag, ACK, or a push channel.
- jobs:getNext returns has_settings_changed even when job is null and never clears the flag.
- workers:getProcessingSettings authenticates both the API key and worker_uid binding, returns the complete schema_version=1 profile, and clears only the requesting worker's flag.
- Profile save and profile fetch use serialized SQLite transactions with an early write lock; do not add a module lifecycle guard.
- Preserve PBXApiResult, job ownership, lease-token, recording-path, transcript-millisecond, and one-time API-key contracts.
- Language selection is outside this profile.
- Implement with tests first and commit after every task.

---

### Task 1: Define the canonical processing profile

**Files:**
- Modify: Models/STTSetting.php
- Create: Lib/Services/WorkerProcessingSettingsService.php
- Modify: tests/check-settings-normalization.php

**Interfaces:**
- Produces: WorkerProcessingSettingsService::SCHEMA_VERSION
- Produces: WorkerProcessingSettingsService::fieldNames(): array
- Produces: WorkerProcessingSettingsService::defaults(): array
- Produces: WorkerProcessingSettingsService::normalize(array $input): array
- Produces: WorkerProcessingSettingsService::current(): array
- Produces: WorkerProcessingSettingsService::apiPayload(array $profile): array
- Throws: InvalidArgumentException for a missing field, invalid type, non-finite number, out-of-range value, or overlap greater than or equal to maximum duration.

- [ ] **Step 1: Add failing profile-default and validation assertions**

Add the service require and these assertions to tests/check-settings-normalization.php:

~~~php
require_once __DIR__ . '/../Lib/Services/WorkerProcessingSettingsService.php';

use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;

$defaults = WorkerProcessingSettingsService::defaults();
expectSameSettingsNormalization(0.0, $defaults['temperature'], 'Temperature default must be 0.0');
expectSameSettingsNormalization(0.2, $defaults['temperature_fallback_step'], 'Fallback step default must be 0.2');
expectSameSettingsNormalization(5, $defaults['temperature_fallback_count'], 'Fallback count default must be 5');
expectSameSettingsNormalization(5, $defaults['top_k'], 'Top K default must be 5');
expectSameSettingsNormalization(2.4, $defaults['compression_ratio_threshold'], 'Compression ratio default must be 2.4');
expectSameSettingsNormalization(-1.0, $defaults['log_probability_threshold'], 'Log probability default must be -1.0');
expectSameSettingsNormalization(-1.5, $defaults['first_token_log_probability_threshold'], 'First-token default must be -1.5');
expectSameSettingsNormalization(0.6, $defaults['no_speech_threshold'], 'No-speech default must be 0.6');
expectSameSettingsNormalization(true, $defaults['audio_normalization'], 'Normalization must default on');
expectSameSettingsNormalization(true, $defaults['voice_activity_detection'], 'VAD must default on');
expectSameSettingsNormalization(15000, $defaults['max_segment_duration_ms'], 'Segment duration default must be 15 seconds');
expectSameSettingsNormalization(300, $defaults['segment_overlap_ms'], 'Overlap default must be 300 ms');

$normalized = WorkerProcessingSettingsService::normalize(array_merge($defaults, [
    'temperature' => '0.4',
    'temperature_fallback_count' => '3',
    'audio_normalization' => '0',
]));
expectSameSettingsNormalization(0.4, $normalized['temperature'], 'Numeric strings must normalize to floats');
expectSameSettingsNormalization(3, $normalized['temperature_fallback_count'], 'Integer strings must normalize to integers');
expectSameSettingsNormalization(false, $normalized['audio_normalization'], 'Boolean form values must normalize');

foreach ([
    ['temperature' => 1.1],
    ['top_k' => 0],
    ['max_segment_duration_ms' => 999],
    ['segment_overlap_ms' => 15000],
] as $invalidPatch) {
    try {
        WorkerProcessingSettingsService::normalize(array_merge($defaults, $invalidPatch));
        throw new RuntimeException('Invalid processing profile was accepted');
    } catch (InvalidArgumentException) {
    }
}
~~~

- [ ] **Step 2: Run the focused test and confirm the new class is missing**

Run: php tests/check-settings-normalization.php

Expected: FAIL because WorkerProcessingSettingsService.php does not exist.

- [ ] **Step 3: Add stable setting keys and the strict profile service**

Add these public constants to STTSetting:

~~~php
public const string TEMPERATURE = 'temperature';
public const string TEMPERATURE_FALLBACK_STEP = 'temperature_fallback_step';
public const string TEMPERATURE_FALLBACK_COUNT = 'temperature_fallback_count';
public const string TOP_K = 'top_k';
public const string COMPRESSION_RATIO_THRESHOLD = 'compression_ratio_threshold';
public const string LOG_PROBABILITY_THRESHOLD = 'log_probability_threshold';
public const string FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD = 'first_token_log_probability_threshold';
public const string NO_SPEECH_THRESHOLD = 'no_speech_threshold';
public const string AUDIO_NORMALIZATION = 'audio_normalization';
public const string VOICE_ACTIVITY_DETECTION = 'voice_activity_detection';
public const string MAX_SEGMENT_DURATION_MS = 'max_segment_duration_ms';
public const string SEGMENT_OVERLAP_MS = 'segment_overlap_ms';
~~~

Create WorkerProcessingSettingsService as a final class. Use this exact public field order:

~~~php
public const int SCHEMA_VERSION = 1;

private const array DEFAULTS = [
    STTSetting::TEMPERATURE => 0.0,
    STTSetting::TEMPERATURE_FALLBACK_STEP => 0.2,
    STTSetting::TEMPERATURE_FALLBACK_COUNT => 5,
    STTSetting::TOP_K => 5,
    STTSetting::COMPRESSION_RATIO_THRESHOLD => 2.4,
    STTSetting::LOG_PROBABILITY_THRESHOLD => -1.0,
    STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD => -1.5,
    STTSetting::NO_SPEECH_THRESHOLD => 0.6,
    STTSetting::AUDIO_NORMALIZATION => true,
    STTSetting::VOICE_ACTIVITY_DETECTION => true,
    STTSetting::MAX_SEGMENT_DURATION_MS => 15000,
    STTSetting::SEGMENT_OVERLAP_MS => 300,
];

public static function fieldNames(): array
{
    return array_keys(self::DEFAULTS);
}
~~~

Implement normalize() by accepting only booleans or 0/1 for boolean fields, integer values or integer strings for integer fields, and finite numeric values for float fields. Enforce these exact bounds:

~~~php
private const array FLOAT_RANGES = [
    STTSetting::TEMPERATURE => [0.0, 1.0],
    STTSetting::TEMPERATURE_FALLBACK_STEP => [0.0, 1.0],
    STTSetting::COMPRESSION_RATIO_THRESHOLD => [0.0, 10.0],
    STTSetting::LOG_PROBABILITY_THRESHOLD => [-10.0, 0.0],
    STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD => [-10.0, 0.0],
    STTSetting::NO_SPEECH_THRESHOLD => [0.0, 1.0],
];

private const array INTEGER_RANGES = [
    STTSetting::TEMPERATURE_FALLBACK_COUNT => [0, 10],
    STTSetting::TOP_K => [1, 100],
    STTSetting::MAX_SEGMENT_DURATION_MS => [1000, 120000],
    STTSetting::SEGMENT_OVERLAP_MS => [0, 10000],
];
~~~

current() must read every STTSetting key with its canonical default string, then pass the full array through normalize(). apiPayload() must return:

~~~php
return [
    'schema_version' => self::SCHEMA_VERSION,
    'processing_settings' => self::normalize($profile),
];
~~~

- [ ] **Step 4: Run profile normalization tests**

Run: php tests/check-settings-normalization.php

Expected: PASS with Settings normalization contract OK.

- [ ] **Step 5: Commit the canonical profile**

~~~bash
git add Models/STTSetting.php Lib/Services/WorkerProcessingSettingsService.php tests/check-settings-normalization.php
git commit -m "feat: define worker processing profile"
~~~

### Task 2: Add the per-worker dirty flag and upgrade path

**Files:**
- Modify: Models/STTWorker.php
- Modify: Setup/PbxExtensionSetup.php
- Create: tests/check-worker-processing-settings-flag-contract.php

**Interfaces:**
- Produces: STTWorker::$has_settings_changed as integer-backed boolean state.
- Produces: STTWorker::hasProcessingSettingsChanged(): bool
- Produces: PbxExtensionSetup::ensureWorkerProcessingSettingsFlag(): void
- Existing workers are marked dirty only when the column is first introduced.
- Newly created workers start dirty; re-registering an existing worker preserves its value.

- [ ] **Step 1: Write the failing schema and registration contract**

Create tests/check-worker-processing-settings-flag-contract.php with assertions that:

~~~php
$workerModel = (string)file_get_contents(__DIR__ . '/../Models/STTWorker.php');
$setup = (string)file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');

expectWorkerSettingsFlag(
    str_contains($workerModel, 'public ?int $has_settings_changed = 1;'),
    'Workers must persist the processing-settings dirty flag'
);
expectWorkerSettingsFlag(
    str_contains($workerModel, 'public function hasProcessingSettingsChanged(): bool'),
    'Workers must expose a boolean dirty-state helper'
);
expectWorkerSettingsFlag(
    str_contains($setup, 'ensureWorkerProcessingSettingsFlag();')
        && str_contains($setup, 'ALTER TABLE m_STTWorker ADD COLUMN has_settings_changed INTEGER NOT NULL DEFAULT 1'),
    'Setup must add the dirty flag for existing installations'
);
expectWorkerSettingsFlag(
    str_contains($setup, 'UPDATE m_STTWorker SET has_settings_changed = 1'),
    'Existing workers must fetch the new profile after upgrade'
);
~~~

Also inspect the new-worker branch of registerWorker() and assert that the assignment to 1 occurs only inside if ($worker === null).

- [ ] **Step 2: Run the contract and confirm it fails**

Run: php tests/check-worker-processing-settings-flag-contract.php

Expected: FAIL because the model and setup do not contain the flag.

- [ ] **Step 3: Add the model column and idempotent setup**

Add to STTWorker:

~~~php
/**
 * @Column(type="integer", default="1", nullable=false)
 */
public ?int $has_settings_changed = 1;

public function hasProcessingSettingsChanged(): bool
{
    return (int)$this->has_settings_changed === 1;
}
~~~

Inside registerWorker(), set has_settings_changed=1 only when constructing a new STTWorker. Add ensureWorkerProcessingSettingsFlag() to setup:

~~~php
private function ensureWorkerProcessingSettingsFlag(): void
{
    $worker = new STTWorker();
    $connection = $worker->getWriteConnection();
    foreach ($connection->describeColumns($worker->getSource()) as $column) {
        if ($column->getName() === 'has_settings_changed') {
            return;
        }
    }

    $connection->execute(
        'ALTER TABLE m_STTWorker ADD COLUMN has_settings_changed INTEGER NOT NULL DEFAULT 1'
    );
    $connection->execute('UPDATE m_STTWorker SET has_settings_changed = 1');
}
~~~

Import STTWorker in setup and call the helper immediately after parent schema installation and default-setting initialization, before API permission reconciliation.

Import WorkerProcessingSettingsService and seed missing setting rows without overwriting administrator values:

~~~php
foreach (WorkerProcessingSettingsService::defaults() as $key => $value) {
    $encoded = is_bool($value)
        ? ($value ? '1' : '0')
        : (json_encode($value, JSON_PRESERVE_ZERO_FRACTION) ?: (string)$value);
    $this->setDefaultSetting($key, $encoded);
}
~~~

- [ ] **Step 4: Run the focused and setup contracts**

Run: php tests/check-worker-processing-settings-flag-contract.php

Expected: PASS with Worker processing settings flag contract OK.

Run: php tests/check-module-structure-contract.php

Expected: PASS.

- [ ] **Step 5: Commit worker dirty-state persistence**

~~~bash
git add Models/STTWorker.php Setup/PbxExtensionSetup.php tests/check-worker-processing-settings-flag-contract.php
git commit -m "feat: track worker processing settings changes"
~~~

### Task 3: Implement atomic profile save and fetch operations

**Files:**
- Modify: Lib/Services/WorkerProcessingSettingsService.php
- Create: tests/check-worker-processing-settings-sqlite.php

**Interfaces:**
- Produces: WorkerProcessingSettingsService::save(array $input): bool
- Produces: WorkerProcessingSettingsService::fetchForWorker(STTWorker $worker): array
- save() returns false without writes when the canonical profile is unchanged.
- save() writes all keys and marks all workers dirty in one BEGIN IMMEDIATE transaction.
- fetchForWorker() clears one worker and reads one complete profile in one BEGIN IMMEDIATE transaction.

- [ ] **Step 1: Write failing SQLite transaction tests**

Create an isolated SQLite database with m_STTSetting and m_STTWorker, instantiate the service with the adapter, and assert these scenarios:

~~~php
$service = new WorkerProcessingSettingsService($connection);
$defaults = WorkerProcessingSettingsService::defaults();

expectProcessingSettingsSqlite(
    $service->save($defaults) === false,
    'Saving the canonical defaults twice must be a no-op'
);

$changed = array_merge($defaults, ['temperature' => 0.4]);
expectProcessingSettingsSqlite($service->save($changed), 'A real change must be reported');
expectProcessingSettingsSqlite(
    dirtyFlags($connection) === ['worker-a' => 1, 'worker-b' => 1],
    'A real change must dirty every worker'
);

$payload = $service->fetchForWorker(workerByUid('worker-a'));
expectProcessingSettingsSqlite(
    $payload['processing_settings']['temperature'] === 0.4,
    'Fetch must return the complete new profile'
);
expectProcessingSettingsSqlite(
    dirtyFlags($connection) === ['worker-a' => 0, 'worker-b' => 1],
    'Fetch must clear only the requesting worker'
);
~~~

Use a second SQLite connection to the same temporary file to test serialized ordering: fetch-then-save must leave worker-a dirty, while save-then-fetch must return the new value and leave worker-a clean.

- [ ] **Step 2: Run the SQLite test and confirm missing methods**

Run: php tests/check-worker-processing-settings-sqlite.php

Expected: FAIL because save(), fetchForWorker(), and the injectable connection do not exist.

- [ ] **Step 3: Implement serialized transactions**

Add an optional constructor connection:

~~~php
public function __construct(private readonly mixed $connection = null)
{
}

private function database(): mixed
{
    return $this->connection ?? (new STTSetting())->getWriteConnection();
}
~~~

Use a private transaction helper that executes BEGIN IMMEDIATE, COMMIT, and ROLLBACK directly on the SQLite adapter. save() must acquire the lock before reading current rows:

~~~php
public function save(array $input): bool
{
    $next = self::normalize($input);

    return $this->withImmediateTransaction(function (mixed $database) use ($next): bool {
        $current = $this->readCurrent($database);
        if ($current === $next) {
            return false;
        }

        foreach ($next as $key => $value) {
            $database->execute(
                'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value',
                ['key' => $key, 'value' => self::storageValue($value)]
            );
        }
        $database->execute('UPDATE m_STTWorker SET has_settings_changed = 1');

        return true;
    });
}
~~~

fetchForWorker() must perform its UPDATE before its SELECT so the transaction already owns the write lock:

~~~php
public function fetchForWorker(STTWorker $worker): array
{
    return $this->withImmediateTransaction(function (mixed $database) use ($worker): array {
        $database->execute(
            'UPDATE m_STTWorker SET has_settings_changed = 0 WHERE id = :id',
            ['id' => (int)$worker->id]
        );

        return self::apiPayload($this->readCurrent($database));
    });
}
~~~

Encode booleans as 1/0, integers as decimal strings, and floats with JSON_PRESERVE_ZERO_FRACTION so 0.0 remains canonical. Roll back and rethrow every Throwable.

- [ ] **Step 4: Run normalization and SQLite tests**

Run: php tests/check-settings-normalization.php

Expected: PASS.

Run: php tests/check-worker-processing-settings-sqlite.php

Expected: PASS with Worker processing settings SQLite contract OK.

- [ ] **Step 5: Commit atomic persistence**

~~~bash
git add Lib/Services/WorkerProcessingSettingsService.php tests/check-worker-processing-settings-sqlite.php
git commit -m "feat: persist worker processing settings atomically"
~~~

### Task 4: Populate the module's Advanced settings UI

**Files:**
- Modify: App/Controllers/LocalSpeechToTextBaseController.php
- Modify: App/Forms/ModuleLocalSpeechToTextForm.php
- Modify: App/Views/ModuleLocalSpeechToText/index.volt
- Modify: public/assets/js/src/module-local-speech-to-text.js
- Modify: public/assets/js/module-local-speech-to-text.js
- Modify: public/assets/css/module-local-speech-to-text.css
- Modify: Messages/*.php
- Modify: tests/check-advanced-settings-placeholder-contract.php
- Create: tests/check-worker-processing-settings-ui-contract.php

**Interfaces:**
- Consumes: WorkerProcessingSettingsService::current() and save().
- Produces: twelve expert controls inside the existing collapsed accordion.
- UI-only duration fields are max_segment_duration_sec and segment_overlap_sec; the controller converts them to integer milliseconds.
- Boolean checkboxes post 1 when checked and normalize to false when absent.

- [ ] **Step 1: Replace the empty-placeholder test with failing populated-UI assertions**

Update check-advanced-settings-placeholder-contract.php so it still checks placement and collapsed initialization but rejects an empty content block. Create check-worker-processing-settings-ui-contract.php and assert that the Volt view contains every API field name plus max_segment_duration_sec and segment_overlap_sec, that the form defines min/max/step attributes, that source and bundled JavaScript contain identical validation keys, and that all message catalogs have identical keys.

The test must explicitly require these labels:

~~~php
$requiredKeys = [
    'module_local_speech_to_text_ProcessingSettingsHint',
    'module_local_speech_to_text_DecodingSettings',
    'module_local_speech_to_text_AudioProcessingSettings',
    'module_local_speech_to_text_Temperature',
    'module_local_speech_to_text_TemperatureFallbackStep',
    'module_local_speech_to_text_TemperatureFallbackCount',
    'module_local_speech_to_text_TopK',
    'module_local_speech_to_text_CompressionRatioThreshold',
    'module_local_speech_to_text_LogProbabilityThreshold',
    'module_local_speech_to_text_FirstTokenLogProbabilityThreshold',
    'module_local_speech_to_text_NoSpeechThreshold',
    'module_local_speech_to_text_AudioNormalization',
    'module_local_speech_to_text_VoiceActivityDetection',
    'module_local_speech_to_text_MaxSegmentDuration',
    'module_local_speech_to_text_SegmentOverlap',
];
~~~

- [ ] **Step 2: Run the two UI contracts and confirm failure**

Run: php tests/check-advanced-settings-placeholder-contract.php

Expected: FAIL because the accordion is still empty.

Run: php tests/check-worker-processing-settings-ui-contract.php

Expected: FAIL because the controls and translations are missing.

- [ ] **Step 3: Add form fields and controller conversion**

Import Numeric and Check form elements. Add Numeric fields using these exact attributes:

~~~php
[
    'temperature' => ['min' => 0, 'max' => 1, 'step' => 0.1],
    'temperature_fallback_step' => ['min' => 0, 'max' => 1, 'step' => 0.1],
    'temperature_fallback_count' => ['min' => 0, 'max' => 10, 'step' => 1],
    'top_k' => ['min' => 1, 'max' => 100, 'step' => 1],
    'compression_ratio_threshold' => ['min' => 0, 'max' => 10, 'step' => 0.1],
    'log_probability_threshold' => ['min' => -10, 'max' => 0, 'step' => 0.1],
    'first_token_log_probability_threshold' => ['min' => -10, 'max' => 0, 'step' => 0.1],
    'no_speech_threshold' => ['min' => 0, 'max' => 1, 'step' => 0.05],
    'max_segment_duration_sec' => ['min' => 1, 'max' => 120, 'step' => 1],
    'segment_overlap_sec' => ['min' => 0, 'max' => 10, 'step' => 0.1],
]
~~~

Add Check fields audio_normalization and voice_activity_detection with value=1 and checked state from the entity.

In settingsEntity(), merge WorkerProcessingSettingsService::current(), converting the two millisecond fields to seconds. In saveSettingsFromPost(), build the complete profile from request values, use false for absent checkbox names, multiply seconds by 1000 with round(), and call:

~~~php
$processingChanged = (new WorkerProcessingSettingsService())->save($processingInput);
~~~

Include changed=yes/no in the existing settings-save log without logging secrets.

- [ ] **Step 4: Render, validate, style, and translate the controls**

Inside the existing accordion content, add an expert warning followed by Decoding and Audio processing subsections. Render all twelve controls in Semantic UI fields; disable the two duration rows visually when voice_activity_detection is unchecked while preserving their values.

Add the same rules to Form.validateRules and fallback $settingsForm.form fields. Add a custom overlap rule that converts both displayed seconds to milliseconds and requires overlap < maximum duration. Keep server validation authoritative.

Apply identical JavaScript changes to source and bundled assets. Add only section/grid/help spacing to CSS.

Use the miko-module-translations skill while editing Messages/*.php. English copy is the source fallback; Russian must use native labels and explain that the settings apply to all workers. Keep identical key sets in every catalog.

- [ ] **Step 5: Run UI and translation checks**

Run: php tests/check-advanced-settings-placeholder-contract.php

Expected: PASS with Advanced settings contract OK.

Run: php tests/check-worker-processing-settings-ui-contract.php

Expected: PASS with Worker processing settings UI contract OK.

Run: find Messages -name '*.php' -print0 | xargs -0 -n1 php -l

Expected: every file reports No syntax errors detected.

- [ ] **Step 6: Commit the centralized settings UI**

~~~bash
git add App/Controllers/LocalSpeechToTextBaseController.php App/Forms/ModuleLocalSpeechToTextForm.php App/Views/ModuleLocalSpeechToText/index.volt public/assets/js/src/module-local-speech-to-text.js public/assets/js/module-local-speech-to-text.js public/assets/css/module-local-speech-to-text.css Messages tests/check-advanced-settings-placeholder-contract.php tests/check-worker-processing-settings-ui-contract.php
git commit -m "feat: expose centralized worker processing settings"
~~~

### Task 5: Add has_settings_changed to every next-job response

**Files:**
- Modify: Lib/LocalSpeechToTextMain.php
- Modify: Lib/RestAPI/Jobs/DataStructure.php
- Modify: tests/check-rest-api-contract.php
- Modify: tests/check-openapi-contract.php
- Create: tests/check-worker-settings-next-job-contract.php

**Interfaces:**
- Consumes: STTWorker::hasProcessingSettingsChanged().
- Produces: data.has_settings_changed as a JSON boolean next to data.job.
- Both empty and claimed-job success paths include the flag.

- [ ] **Step 1: Add failing response-contract assertions**

Add this property assertion to check-rest-api-contract.php:

~~~php
$jobSchema = JobsDataStructure::getDetailSchema();
expectSameRestApiContract(
    'boolean',
    $jobSchema['properties']['has_settings_changed']['type'] ?? null,
    'Next-job responses must expose has_settings_changed'
);
~~~

Create check-worker-settings-next-job-contract.php and inspect getNextJob() to require has_settings_changed in both self::success branches and to forbid settings_revision and applied_settings_revision throughout Lib/RestAPI and Lib/LocalSpeechToTextMain.php.

- [ ] **Step 2: Run the focused contracts and confirm failure**

Run: php tests/check-worker-settings-next-job-contract.php

Expected: FAIL because getNextJob() returns only job.

Run: php tests/check-rest-api-contract.php

Expected: FAIL because the schema lacks has_settings_changed.

- [ ] **Step 3: Add the boolean to runtime and schema**

Capture the flag after worker authentication and before claiming:

~~~php
$hasSettingsChanged = $worker->hasProcessingSettingsChanged();
~~~

Return it in both success paths:

~~~php
return self::success([
    'job' => $job === null ? null : self::formatJob($job, $workerUid),
    'has_settings_changed' => $hasSettingsChanged,
]);
~~~

Keep existing assignment logging only for non-null jobs. Add the OpenAPI property:

~~~php
'has_settings_changed' => [
    'type' => 'boolean',
    'description' => 'Whether this worker should fetch the complete processing profile.',
],
~~~

- [ ] **Step 4: Run job, REST, and OpenAPI contracts**

Run: php tests/check-worker-settings-next-job-contract.php

Expected: PASS.

Run: php tests/check-rest-api-contract.php

Expected: PASS.

Run: php tests/check-openapi-contract.php

Expected: PASS.

- [ ] **Step 5: Commit the additive poll signal**

~~~bash
git add Lib/LocalSpeechToTextMain.php Lib/RestAPI/Jobs/DataStructure.php tests/check-rest-api-contract.php tests/check-openapi-contract.php tests/check-worker-settings-next-job-contract.php
git commit -m "feat: signal worker processing setting changes"
~~~

### Task 6: Expose the authenticated full-profile endpoint

**Files:**
- Create: Lib/RestAPI/Workers/Actions/GetProcessingSettingsAction.php
- Create: Lib/RestAPI/Workers/ProcessingSettingsResponseDataStructure.php
- Modify: Lib/RestAPI/Workers/Controller.php
- Modify: Lib/RestAPI/Workers/Processor.php
- Modify: Lib/RestAPI/Workers/DataStructure.php
- Modify: Lib/LocalSpeechToTextMain.php
- Modify: Messages/*.php
- Modify: tests/check-rest-api-contract.php
- Modify: tests/check-openapi-contract.php
- Modify: tests/check-worker-api-key-permissions.php
- Create: tests/check-worker-processing-settings-endpoint-contract.php

**Interfaces:**
- Produces: GET /pbxcore/api/v3/module-local-speech-to-text/workers:getProcessingSettings?worker_uid=...
- Produces: LocalSpeechToTextMain::getWorkerProcessingSettings(array $data): PBXApiResult
- Consumes: WorkerProcessingSettingsService::fetchForWorker().
- Reuses: validateWorkerApiKey(), findWorker(), and validateWorkerApiKeyBinding().

- [ ] **Step 1: Write failing routing, schema, authorization, and permission assertions**

The endpoint contract must require:

~~~php
expectProcessingEndpoint(str_contains($controller, "'GET' => ['getProcessingSettings']"), 'Workers GET route is missing');
expectProcessingEndpoint(str_contains($processor, "case GET_PROCESSING_SETTINGS = 'getProcessingSettings';"), 'Worker action is missing');
expectProcessingEndpoint(str_contains($main, 'validateWorkerApiKeyBinding($worker, $data)'), 'Worker binding check is missing');
expectProcessingEndpoint(str_contains($main, 'fetchForWorker($worker)'), 'Atomic profile fetch is missing');
expectProcessingEndpoint(!str_contains($main, 'settings_revision'), 'Settings revisions are forbidden');
~~~

Extend the permission test with:

~~~php
['GET', '/pbxcore/api/v3/module-local-speech-to-text/workers:getProcessingSettings?worker_uid=worker-a'],
~~~

Extend REST/OpenAPI tests to require schema_version integer, processing_settings object, all twelve typed fields, the GET mapping, required worker_uid, and 200/401/403/404/422 responses.

- [ ] **Step 2: Run the endpoint and permission tests and confirm failure**

Run: php tests/check-worker-processing-settings-endpoint-contract.php

Expected: FAIL because the action does not exist.

Run: php tests/check-worker-api-key-permissions.php

Expected: FAIL because the new exact path is not in the scenario table.

- [ ] **Step 3: Add the worker action and controller mapping**

Add GET mapping and collection/custom method getProcessingSettings to Workers Controller. Add a method with:

~~~php
#[ApiDataSchema(
    schemaClass: ProcessingSettingsResponseDataStructure::class,
    type: 'custom',
    schemaName: 'LocalSTTWorkerProcessingSettingsResponse'
)]
#[ApiParameterRef('worker_uid', required: true)]
#[ApiResponse(200, 'rest_response_200_ok')]
#[ApiResponse(401, 'rest_response_401_unauthorized', 'PBXApiResult')]
#[ApiResponse(403, 'rest_response_403_forbidden', 'PBXApiResult')]
#[ApiResponse(404, 'rest_response_404_not_found', 'PBXApiResult')]
#[ApiResponse(422, 'rest_response_422_validation_error', 'PBXApiResult')]
public function getProcessingSettings(): void
{
}
~~~

Add GET_PROCESSING_SETTINGS to WorkerAction, dispatch it to GetProcessingSettingsAction::main(), and have that action delegate to LocalSpeechToTextMain.

- [ ] **Step 4: Implement authenticated profile retrieval**

Implement getWorkerProcessingSettings() in the same order as getNextJob():

1. Validate non-empty worker_uid.
2. Validate the worker API key.
3. Find the registered worker.
4. Validate API-key binding for that worker.
5. Touch the worker online.
6. Call WorkerProcessingSettingsService::fetchForWorker($worker).
7. Return self::success($payload).
8. Convert validation errors to 422 and unexpected errors to the established 500 envelope without leaking internals.

Do not clear the flag in getNextJob or before worker identity/binding validation.

- [ ] **Step 5: Add the exact response schema and API translations**

ProcessingSettingsResponseDataStructure::getDetailSchema() must define schema_version=integer and a processing_settings object whose required array contains all twelve fields. Use number for six float fields, integer for the four integer fields, and boolean for the two switches. Include min/max constraints and the millisecond descriptions.

Use the translation workflow to add the endpoint summary/description keys to every Messages catalog. Keep WorkerApiKeyService::WORKER_ALLOWED_PATHS unchanged because the existing workers resource write permission already admits the custom GET; only extend its executable permission test.

- [ ] **Step 6: Run endpoint, REST, OpenAPI, and permission tests**

Run: php tests/check-worker-processing-settings-endpoint-contract.php

Expected: PASS.

Run: php tests/check-worker-api-key-permissions.php

Expected: PASS.

Run: php tests/check-rest-api-contract.php

Expected: PASS.

Run: php tests/check-openapi-contract.php

Expected: PASS.

- [ ] **Step 7: Commit the profile API**

~~~bash
git add Lib/RestAPI/Workers Lib/LocalSpeechToTextMain.php Messages tests/check-rest-api-contract.php tests/check-openapi-contract.php tests/check-worker-api-key-permissions.php tests/check-worker-processing-settings-endpoint-contract.php
git commit -m "feat: serve processing settings to workers"
~~~

### Task 7: Verify the module as a deployable unit

**Files:**
- Modify only if a verification failure exposes a defect in files changed by Tasks 1–6.

**Interfaces:**
- Produces: a module package that old workers can use unchanged and new workers can query.
- Provides the server half of the cross-repository contract consumed by the worker plan.

- [ ] **Step 1: Run all module contract tests**

Run: php Tests/run-all.php

Expected: All module contract tests passed.

- [ ] **Step 2: Lint every PHP source and translation**

Run: find App Lib Models Setup Tests bin Messages -name '*.php' -print0 | xargs -0 -n1 php -l

Expected: every file reports No syntax errors detected.

- [ ] **Step 3: Build and inspect the module package**

Run: ./scripts/package-module.sh /tmp/module-local-stt-central-settings

Expected: the package script succeeds and reports the generated ModuleLocalSpeechToText ZIP.

Run: unzip -t /tmp/module-local-stt-central-settings/*.zip

Expected: No errors detected in compressed data.

- [ ] **Step 4: Inspect the final module diff**

Run: git diff HEAD~6 --check

Expected: no whitespace errors.

Run: git status --short

Expected: empty output.

- [ ] **Step 5: Record verification if a corrective commit was needed**

If Tasks 1–6 already pass unchanged, do not create an empty commit. If verification required an in-scope correction:

~~~bash
git add App Lib Models Setup Tests tests Messages public
git commit -m "fix: complete centralized processing settings module"
~~~

## Module-to-Worker Handoff

The worker implementation may start after Task 6. Its decoder must consume the exact schema_version and processing_settings payload defined here, treat absent has_settings_changed as a legacy module, and never send a revision query parameter. Complete the worker plan before Docker/PBX end-to-end verification.
