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

expectDurationContract($settings !== false, 'Cannot read STTSetting.php');
expectDurationContract($form !== false, 'Cannot read settings form');
expectDurationContract($controller !== false, 'Cannot read settings controller');
expectDurationContract($view !== false, 'Cannot read settings view');
expectDurationContract($setup !== false, 'Cannot read setup');

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

echo "Recording duration limit contract OK\n";
