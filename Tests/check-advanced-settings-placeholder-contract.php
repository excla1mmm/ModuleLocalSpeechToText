<?php

declare(strict_types=1);

function expectAdvancedSettings(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$view = file_get_contents(__DIR__ . '/../App/Views/ModuleLocalSpeechToText/index.volt');
$sourceJs = file_get_contents(__DIR__ . '/../public/assets/js/src/module-local-speech-to-text.js');
$bundledJs = file_get_contents(__DIR__ . '/../public/assets/js/module-local-speech-to-text.js');
$css = file_get_contents(__DIR__ . '/../public/assets/css/module-local-speech-to-text.css');

expectAdvancedSettings($view !== false, 'Cannot read settings view');
expectAdvancedSettings($sourceJs !== false && $bundledJs !== false, 'Cannot read module JavaScript assets');
expectAdvancedSettings($css !== false, 'Cannot read module stylesheet');

$hintPosition = strpos($view, 'module_local_speech_to_text_CdrHistoryDaysHint');
$advancedPosition = strpos($view, 'stt-advanced-settings-dropdown');
$vocabularyPosition = strpos($view, 'module_local_speech_to_text_VocabularyTermsTitle');
expectAdvancedSettings(
    $hintPosition !== false
        && $advancedPosition !== false
        && $vocabularyPosition !== false
        && $hintPosition < $advancedPosition
        && $advancedPosition < $vocabularyPosition,
    'Advanced settings placeholder must be between the CDR hint and recognition terms'
);

preg_match(
    '/<div class="ui fluid styled accordion stt-advanced-settings-dropdown">\s*'
        . '<div class="title">\s*<i class="dropdown icon"><\/i>'
        . "\{\{ t\._\('module_local_speech_to_text_AdvancedSettings'\) \}\}\s*<\/div>\s*"
        . '<div class="content">\s*(.+?)\s*<\/div>\s*<\/div>/s',
    $view,
    $accordionMatch
);
expectAdvancedSettings(
    ($accordionMatch[0] ?? '') !== ''
        && preg_match('/stt-advanced-settings-dropdown.*?<div class="content">\s*<\/div>/s', $view) !== 1,
    'Advanced settings accordion must remain collapsed and contain expert controls'
);
expectAdvancedSettings(
    str_contains($sourceJs, '.stt-advanced-settings-dropdown')
        && str_contains($bundledJs, '.stt-advanced-settings-dropdown'),
    'Advanced settings accordion must be initialized in both JavaScript assets'
);
expectAdvancedSettings(
    str_contains($css, '.stt-advanced-settings-dropdown'),
    'Advanced settings placeholder spacing is missing'
);

$messageFiles = glob(__DIR__ . '/../Messages/*.php') ?: [];
expectAdvancedSettings($messageFiles !== [], 'No translation catalogs found');
$sourceMessages = include __DIR__ . '/../Messages/ru.php';
$sourceKeys = array_keys($sourceMessages);
sort($sourceKeys);
expectAdvancedSettings(
    ($sourceMessages['module_local_speech_to_text_AdvancedSettings'] ?? '') === 'Расширенные настройки',
    'Russian advanced settings label is missing'
);
foreach ($messageFiles as $messageFile) {
    $messages = include $messageFile;
    expectAdvancedSettings(
        isset($messages['module_local_speech_to_text_AdvancedSettings']),
        basename($messageFile) . ' is missing the advanced settings label'
    );
    $messageKeys = array_keys($messages);
    sort($messageKeys);
    expectAdvancedSettings($messageKeys === $sourceKeys, basename($messageFile) . ' translation keys differ from ru.php');
}

echo "Advanced settings contract OK\n";
