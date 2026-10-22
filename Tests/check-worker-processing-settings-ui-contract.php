<?php

declare(strict_types=1);

function expectProcessingSettingsUi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/App/Views/ModuleLocalSpeechToText/index.volt');
$form = (string)file_get_contents($root . '/App/Forms/ModuleLocalSpeechToTextForm.php');
$sourceJs = (string)file_get_contents($root . '/public/assets/js/src/module-local-speech-to-text.js');
$bundledJs = (string)file_get_contents($root . '/public/assets/js/module-local-speech-to-text.js');

$apiFields = [
    'temperature',
    'temperature_fallback_step',
    'temperature_fallback_count',
    'top_k',
    'compression_ratio_threshold',
    'log_probability_threshold',
    'first_token_log_probability_threshold',
    'no_speech_threshold',
    'audio_normalization',
    'voice_activity_detection',
    'max_segment_duration_ms',
    'segment_overlap_ms',
];

$fieldHintKeys = [
    'Temperature' => 'TemperatureHint',
    'TemperatureFallbackStep' => 'TemperatureFallbackStepHint',
    'TemperatureFallbackCount' => 'TemperatureFallbackCountHint',
    'TopK' => 'TopKHint',
    'CompressionRatioThreshold' => 'CompressionRatioThresholdHint',
    'LogProbabilityThreshold' => 'LogProbabilityThresholdHint',
    'FirstTokenLogProbabilityThreshold' => 'FirstTokenLogProbabilityThresholdHint',
    'NoSpeechThreshold' => 'NoSpeechThresholdHint',
    'AudioNormalization' => 'AudioNormalizationHint',
    'VoiceActivityDetection' => 'VoiceActivityDetectionHint',
    'MaxSegmentDuration' => 'MaxSegmentDurationHint',
    'SegmentOverlap' => 'SegmentOverlapHint',
];
foreach ($fieldHintKeys as $labelKey => $hintKey) {
    $labelTranslation = "module_local_speech_to_text_{$labelKey}";
    $hintTranslation = "module_local_speech_to_text_{$hintKey}";
    expectProcessingSettingsUi(
        preg_match(
            '/<label>\s*\{\{\s*t\._\(\'' . preg_quote($labelTranslation, '/') . '\'\)\s*\}\}\s*'
            . '<i class="small info circle icon field-info-icon" data-content="\{\{\s*t\._\(\''
            . preg_quote($hintTranslation, '/')
            . '\'\)\s*\}\}" data-variation="wide"><\/i>\s*<\/label>/s',
            $view
        ) === 1,
        "Advanced setting {$labelKey} is missing its info hint"
    );
}
foreach (array_merge($apiFields, ['max_segment_duration_sec', 'segment_overlap_sec']) as $field) {
    expectProcessingSettingsUi(
        str_contains($view, $field),
        "Advanced settings view is missing {$field}"
    );
}

$numericRules = [
    'temperature' => [0, 1, 0.1],
    'temperature_fallback_step' => [0, 1, 0.1],
    'temperature_fallback_count' => [0, 10, 1],
    'top_k' => [1, 100, 1],
    'compression_ratio_threshold' => [0, 10, 0.1],
    'log_probability_threshold' => [-10, 0, 0.1],
    'first_token_log_probability_threshold' => [-10, 0, 0.1],
    'no_speech_threshold' => [0, 1, 0.05],
    'max_segment_duration_sec' => [1, 120, 1],
    'segment_overlap_sec' => [0, 10, 0.1],
];
foreach ($numericRules as $field => [$min, $max, $step]) {
    expectProcessingSettingsUi(
        preg_match(
            "/'{$field}'\s*=>\s*\['min'\s*=>\s*{$min},\s*'max'\s*=>\s*{$max},\s*'step'\s*=>\s*{$step}\]/",
            $form
        ) === 1,
        "Form constraints are missing for {$field}"
    );
    expectProcessingSettingsUi(
        str_contains($sourceJs, $field) && str_contains($bundledJs, $field),
        "JavaScript validation is missing for {$field}"
    );
}
expectProcessingSettingsUi(
    str_contains($sourceJs, 'sttProcessingOverlap')
        && str_contains($bundledJs, 'sttProcessingOverlap'),
    'Custom overlap validation must exist in both JavaScript assets'
);
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
$requiredKeys = array_merge(
    $requiredKeys,
    array_map(
        static fn (string $key): string => 'module_local_speech_to_text_' . $key,
        array_values($fieldHintKeys)
    )
);
$messageFiles = glob($root . '/Messages/*.php') ?: [];
expectProcessingSettingsUi($messageFiles !== [], 'No translation catalogs found');
$sourceMessages = include $root . '/Messages/ru.php';
$sourceKeys = array_keys($sourceMessages);
sort($sourceKeys);
foreach ($requiredKeys as $key) {
    expectProcessingSettingsUi(
        isset($sourceMessages[$key]) && trim((string)$sourceMessages[$key]) !== '',
        "Russian catalog is missing {$key}"
    );
}
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    $messageKeys = array_keys($messages);
    sort($messageKeys);
    expectProcessingSettingsUi($messageKeys === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    foreach ($requiredKeys as $key) {
        expectProcessingSettingsUi(isset($messages[$key]), basename($messageFile) . " is missing {$key}");
    }
}

$settingsForm = (string)file_get_contents(__DIR__ . '/../App/Forms/ModuleLocalSpeechToTextForm.php');
expectProcessingSettingsUi(
    str_contains($settingsForm, 'STTSetting::SUMMARIZATION_ENABLED] as $field')
        && str_contains($settingsForm, '$attributes = [\'value\' => \'1\'];')
        && str_contains($settingsForm, '$attributes[\'checked\'] = \'1\';')
        && !str_contains($settingsForm, '$attributes[\'checked\'] = \'checked\';'),
    'Settings toggles must render checked: Phalcon 5 checks a box only when its checked attribute equals its value'
);

echo "Worker processing settings UI contract OK\n";
