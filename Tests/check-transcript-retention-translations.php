<?php

declare(strict_types=1);

function expectRetentionTranslation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$expectedRussian = [
    'module_local_speech_to_text_TranscriptRetentionDays' => 'Срок хранения расшифровок',
    'module_local_speech_to_text_TranscriptRetentionDaysHint' => 'Срок отсчитывается от момента завершения распознавания звонка. CDR и аудиозаписи не удаляются.',
    'module_local_speech_to_text_TranscriptRetention30Days' => '30 дней',
    'module_local_speech_to_text_TranscriptRetention90Days' => '90 дней',
    'module_local_speech_to_text_TranscriptRetention180Days' => '180 дней',
    'module_local_speech_to_text_TranscriptRetention365Days' => '1 год',
    'module_local_speech_to_text_TranscriptRetentionUnlimited' => 'Бесконечно',
    'module_local_speech_to_text_TranscriptRetentionConflict' => 'Период обработки записей не может превышать срок хранения расшифровок. Увеличьте срок хранения или уменьшите период обработки.',
];
$expectedEnglish = [
    'module_local_speech_to_text_TranscriptRetentionDays' => 'Transcript retention',
    'module_local_speech_to_text_TranscriptRetentionDaysHint' => 'Retention starts when call recognition completes. CDR records and audio recordings are not deleted.',
    'module_local_speech_to_text_TranscriptRetention30Days' => '30 days',
    'module_local_speech_to_text_TranscriptRetention90Days' => '90 days',
    'module_local_speech_to_text_TranscriptRetention180Days' => '180 days',
    'module_local_speech_to_text_TranscriptRetention365Days' => '1 year',
    'module_local_speech_to_text_TranscriptRetentionUnlimited' => 'Unlimited',
    'module_local_speech_to_text_TranscriptRetentionConflict' => 'The recording processing window cannot exceed transcript retention. Increase retention or reduce the processing window.',
];

$messageFiles = glob(__DIR__ . '/../Messages/*.php') ?: [];
expectRetentionTranslation(count($messageFiles) === 29, 'Expected the standard 29 module message catalogs');
$russian = include __DIR__ . '/../Messages/ru.php';
$english = include __DIR__ . '/../Messages/en.php';
foreach ($expectedRussian as $key => $value) {
    expectRetentionTranslation(($russian[$key] ?? null) === $value, "Russian retention translation mismatch: {$key}");
}
foreach ($expectedEnglish as $key => $value) {
    expectRetentionTranslation(($english[$key] ?? null) === $value, "English retention translation mismatch: {$key}");
}
$sourceKeys = array_keys($russian);
sort($sourceKeys);
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    $keys = array_keys($messages);
    sort($keys);
    expectRetentionTranslation($keys === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
    foreach (array_keys($expectedEnglish) as $key) {
        expectRetentionTranslation(isset($messages[$key]), basename($messageFile) . " is missing {$key}");
    }
    expectRetentionTranslation(
        !isset($messages['module_local_speech_to_text_CdrHistory730Days']),
        basename($messageFile) . ' must remove the obsolete two-year processing label'
    );
}

echo "Transcript retention translations contract OK\n";
