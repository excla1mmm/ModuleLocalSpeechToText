<?php

declare(strict_types=1);

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
    str_contains($settings, "MAX_RECORDING_DURATION_MIN = 'max_recording_duration_min'"),
    'Duration setting key is missing'
);
expectDurationContract(!str_contains($form, 'max_file_size_mb'), 'Legacy size field must leave the form');
expectDurationContract(
    str_contains($form, "new Numeric('max_recording_duration_min'"),
    'Duration field is missing'
);
expectDurationContract(
    str_contains($form, "'min' => 1") && str_contains($form, "'max' => 1440"),
    'Duration field bounds are wrong'
);
expectDurationContract(
    str_contains($controller, 'STTSetting::MAX_RECORDING_DURATION_MIN'),
    'Controller must load and save duration'
);
expectDurationContract(
    !str_contains($controller, 'STTSetting::MAX_FILE_SIZE_MB'),
    'Controller must ignore the legacy size setting'
);
expectDurationContract(
    str_contains($view, "form.render('max_recording_duration_min')"),
    'View must render the duration control'
);
expectDurationContract(
    !str_contains($setup, 'setDefaultSetting(STTSetting::MAX_FILE_SIZE_MB'),
    'Fresh installs must not create the legacy setting'
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
    str_contains($worker, "'duration_too_long'"),
    'Queue must retain an over-duration skip reason'
);
expectDurationContract(
    str_contains($worker, 'STTSetting::recordingDurationAllowed'),
    'Queue must use shared duration validation'
);
expectDurationContract(
    str_contains($worker, "'recording_duration_sec'"),
    'Retry payload must retain known duration'
);
expectDurationContract(
    str_contains($main, "'duration_hint' => self::jobDurationSeconds(\$job)"),
    'Claim response must expose job duration'
);
expectDurationContract(
    str_contains($main, "self::error('duration_too_long'"),
    'Download must expose stable duration error'
);
expectDurationContract(
    str_contains($main, 'Recording duration exceeds configured maximum'),
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

echo "Recording duration limit contract OK\n";
