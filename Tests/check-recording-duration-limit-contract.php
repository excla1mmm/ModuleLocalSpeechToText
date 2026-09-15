<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
        public static array $values = [];

        public function setSource(string $source): void
        {
        }

        public function initialize(): void
        {
        }

        public static function findFirst(array $parameters = []): ?object
        {
            $key = $parameters['bind']['key'] ?? null;
            if (!is_string($key) || !array_key_exists($key, self::$values)) {
                return null;
            }

            $setting = new static();
            $setting->key = $key;
            $setting->value = self::$values[$key];

            return $setting;
        }

        public function save(): bool
        {
            if (property_exists($this, 'key') && is_string($this->key)) {
                self::$values[$this->key] = (string)($this->value ?? '');
            }

            return true;
        }

        public function getMessages(): array
        {
            return [];
        }
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';

    function expectDurationContract(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $settings = file_get_contents(__DIR__ . '/../Models/STTSetting.php');
    $form = file_get_contents(__DIR__ . '/../App/Forms/ModuleLocalSpeechToTextForm.php');
    $controller = file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');
    $view = file_get_contents(__DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt');
    $setup = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
    $job = file_get_contents(__DIR__ . '/../Models/STTJob.php');
    $worker = file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
    // Duration evaluation and its skip-reason wiring moved out of the worker into
    // Lib/Services/CdrRecordEvaluator.php, Lib/Services/CdrDiscoveryScanner.php, and
    // Lib/Services/RecordingRetryProcessor.php (see AGENTS.md monolithic-worker split).
    $cdrRecordEvaluatorSource = file_get_contents(__DIR__ . '/../Lib/Services/CdrRecordEvaluator.php');
    $cdrDiscoveryScannerSource = file_get_contents(__DIR__ . '/../Lib/Services/CdrDiscoveryScanner.php');
    $recordingRetryProcessorSource = file_get_contents(__DIR__ . '/../Lib/Services/RecordingRetryProcessor.php');
    $durationEvaluator = ($cdrRecordEvaluatorSource !== false ? $cdrRecordEvaluatorSource : '')
        . ($cdrDiscoveryScannerSource !== false ? $cdrDiscoveryScannerSource : '')
        . ($recordingRetryProcessorSource !== false ? $recordingRetryProcessorSource : '');
    $main = file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php');
    $queueView = file_get_contents(__DIR__ . '/../Lib/Services/QueueViewService.php');

    expectDurationContract($settings !== false, 'Cannot read STTSetting.php');
    expectDurationContract($form !== false, 'Cannot read settings form');
    expectDurationContract($controller !== false, 'Cannot read settings controller');
    expectDurationContract($view !== false, 'Cannot read settings view');
    expectDurationContract($setup !== false, 'Cannot read setup');
    expectDurationContract($job !== false, 'Cannot read STTJob.php');
    expectDurationContract($worker !== false, 'Cannot read queue worker');
    expectDurationContract($main !== false, 'Cannot read worker API main class');
    expectDurationContract($queueView !== false, 'Cannot read queue view service');
    expectDurationContract(
        $cdrRecordEvaluatorSource !== false && $cdrDiscoveryScannerSource !== false && $recordingRetryProcessorSource !== false,
        'Cannot read CDR discovery/retry duration services'
    );

    // The duration limit is now a fixed internal policy: 180 minutes / 10800 seconds,
    // not a user-configurable setting.
    expectDurationContract(STTSetting::maxRecordingDurationMinutes() === 180, 'Duration hard limit must be fixed at 180 minutes');
    expectDurationContract(STTSetting::maxRecordingDurationSeconds() === 10800, 'Duration hard limit must be fixed at 10800 seconds');
    expectDurationContract(STTSetting::recordingDurationAllowed(10800), 'A duration of exactly 180 minutes must be allowed');
    expectDurationContract(!STTSetting::recordingDurationAllowed(10801), 'A duration one second over 180 minutes must be rejected');
    expectDurationContract(STTSetting::recordingDurationAllowed(null), 'An unknown/missing duration must remain allowed');

    expectDurationContract(!str_contains($form, 'max_file_size_mb'), 'Legacy size field must leave the form');
    expectDurationContract(
        !str_contains($form, "'max_recording_duration_min'"),
        'The recording duration limit must no longer be a form field'
    );
    expectDurationContract(
        !str_contains($controller, 'max_recording_duration_min')
            && !str_contains($controller, 'MAX_RECORDING_DURATION_MIN'),
        'Controller must no longer load or save a user-configurable duration limit'
    );
    expectDurationContract(
        !str_contains($controller, 'STTSetting::MAX_FILE_SIZE_MB'),
        'Controller must ignore the legacy size setting'
    );
    expectDurationContract(
        !str_contains($view, "'max_recording_duration_min'"),
        'View must no longer render a duration control'
    );
    expectDurationContract(
        !str_contains($setup, 'setDefaultSetting(STTSetting::MAX_FILE_SIZE_MB'),
        'Fresh installs must not create the legacy size setting'
    );
    expectDurationContract(
        !str_contains($setup, "STTSetting::MAX_RECORDING_DURATION_MIN")
            && !str_contains($setup, "'max_recording_duration_min'"),
        'Fresh installs must not seed a user-configurable duration limit'
    );
    expectDurationContract(
        str_contains($job, 'public ?string $recording_duration_sec = null'),
        'Job duration field is missing'
    );
    expectDurationContract(
        str_contains($setup, 'ensureJobRecordingDurationColumn'),
        'Job duration migration is missing'
    );
    expectDurationContract(
        str_contains($setup, 'ALTER TABLE m_STTJob ADD COLUMN recording_duration_sec INTEGER'),
        'Job duration column SQL is missing'
    );
    expectDurationContract(
        str_contains($durationEvaluator, "'duration_too_long'"),
        'Queue must retain an over-duration skip reason'
    );
    expectDurationContract(
        str_contains($durationEvaluator, "'duration_probe_failed'"),
        'Queue must retain an indeterminate-duration retry reason'
    );
    expectDurationContract(
        str_contains($durationEvaluator, 'STTSetting::recordingDurationAllowed'),
        'Queue must use shared duration validation'
    );
    expectDurationContract(
        str_contains($durationEvaluator, "'recording_duration_sec'"),
        'Retry payload must retain known duration'
    );
    expectDurationContract(
        !str_contains($main, "'duration_hint' =>"),
        'Claim response must not expose unused job duration'
    );
    expectDurationContract(
        str_contains($main, "JobApiResponseBuilder::error('duration_too_long'"),
        'Download must expose stable duration error'
    );
    expectDurationContract(
        str_contains($main, 'Recording duration exceeds fixed maximum'),
        'Download must terminally record the duration failure'
    );
    preg_match(
        '/public static function downloadJobFile\(array \$data\): PBXApiResult(?<body>.*?)public static function submitResult/s',
        $main,
        $downloadMatch
    );
    $downloadBody = (string)($downloadMatch['body'] ?? '');
    $ownershipPosition = strpos($downloadBody, 'validateAssignedJob');
    $durationPosition = strpos($downloadBody, 'duration_too_long');
    expectDurationContract(
        $ownershipPosition !== false && $durationPosition !== false && $ownershipPosition < $durationPosition,
        'Ownership validation must precede duration validation'
    );
    expectDurationContract(
        str_contains($queueView, "'duration_too_long' =>"),
        'Queue view must label duration skips'
    );
    expectDurationContract(
        str_contains($queueView, "'duration_probe_failed' =>"),
        'Queue view must label duration probe retries'
    );

    $requiredMessageKeys = [
        'module_local_speech_to_text_QueueSkipReasonDurationTooLong',
        'module_local_speech_to_text_QueueSkipReasonDurationProbeFailed',
    ];
    $removedMessageKeys = [
        'module_local_speech_to_text_MaxFileSize',
        'module_local_speech_to_text_MaxFileSizeHint',
        'module_local_speech_to_text_MaxRecordingDuration',
        'module_local_speech_to_text_MaxRecordingDurationHint',
        'module_local_speech_to_text_PollInterval',
        'module_local_speech_to_text_PollIntervalHint',
        'module_local_speech_to_text_CdrScanLimit',
        'module_local_speech_to_text_CdrScanLimitHint',
        'module_local_speech_to_text_ProcessingTimeout',
        'module_local_speech_to_text_ProcessingTimeoutHint',
    ];
    $messageFiles = glob(__DIR__ . '/../Messages/*.php') ?: [];
    expectDurationContract($messageFiles !== [], 'No translation catalogs found');
    $sourceKeys = array_keys(include __DIR__ . '/../Messages/ru.php');
    sort($sourceKeys);
    foreach ($messageFiles as $messageFile) {
        $messages = include $messageFile;
        expectDurationContract(is_array($messages), 'Invalid translation catalog: ' . basename($messageFile));
        foreach ($requiredMessageKeys as $requiredMessageKey) {
            expectDurationContract(
                array_key_exists($requiredMessageKey, $messages),
                basename($messageFile) . ' is missing ' . $requiredMessageKey
            );
        }
        foreach ($removedMessageKeys as $removedMessageKey) {
            expectDurationContract(
                !array_key_exists($removedMessageKey, $messages),
                basename($messageFile) . ' still exposes retired key ' . $removedMessageKey
            );
        }
        $messageKeys = array_keys($messages);
        sort($messageKeys);
        expectDurationContract($messageKeys === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    }

    $readmeEn = file_get_contents(__DIR__ . '/../README.md');
    $readmeRu = file_get_contents(__DIR__ . '/../README.ru.md');
    expectDurationContract($readmeEn !== false && $readmeRu !== false, 'Cannot read module documentation');
    expectDurationContract(
        str_contains($readmeEn, '180 minutes')
            && str_contains($readmeEn, '10800 seconds')
            && str_contains($readmeEn, '500 MiB'),
        'English README must document the fixed duration limit, its value in seconds, and the fixed file guard'
    );
    expectDurationContract(
        str_contains($readmeRu, '180 минут')
            && str_contains($readmeRu, '10800 секунд')
            && str_contains($readmeRu, '500 МБ'),
        'Russian README must document the fixed duration limit, its value in seconds, and the fixed file guard'
    );

    echo "Recording duration limit contract OK\n";
}
