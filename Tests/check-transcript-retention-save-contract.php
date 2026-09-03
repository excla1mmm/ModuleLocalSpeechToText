<?php

declare(strict_types=1);

function expectRetentionSave(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$controller = (string)file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');
$saveStart = strpos($controller, 'protected function saveSettingsFromPost(): void');
$saveEnd = strpos($controller, 'protected function countJobsByStatus', $saveStart ?: 0);
expectRetentionSave($saveStart !== false && $saveEnd !== false, 'Cannot isolate settings save method');
$save = substr($controller, $saveStart, $saveEnd - $saveStart);

$processingReadPosition = strpos($save, 'normalizeCdrHistoryDays');
$retentionReadPosition = strpos($save, 'normalizeTranscriptRetentionDays');
$compatibilityPosition = strpos($save, 'retentionAllowsProcessing');
$firstWritePosition = strpos($save, 'new AdministrativeSettingsWriter()');
expectRetentionSave(
    $processingReadPosition !== false
        && $retentionReadPosition !== false
        && $compatibilityPosition !== false
        && $firstWritePosition !== false
        && $processingReadPosition < $compatibilityPosition
        && $retentionReadPosition < $compatibilityPosition
        && $compatibilityPosition < $firstWritePosition,
    'retention compatibility must be validated before any setting write'
);
expectRetentionSave(
    str_contains($save, 'module_local_speech_to_text_TranscriptRetentionConflict')
        && str_contains($save, 'InvalidArgumentException'),
    'server must reject incompatible settings with a localized validation error'
);
expectRetentionSave(
    str_contains($save, 'TRANSCRIPT_RETENTION_DAYS')
        && str_contains($save, 'TRANSCRIPT_RETENTION_LAST_CLEANUP_AT')
        && str_contains($save, '$previousRetentionDays !== $nextRetentionDays'),
    'saving a changed retention period must make cleanup immediately due'
);
expectRetentionSave(
    str_contains($controller, '$settings->transcript_retention_days = (string)STTSetting::transcriptRetentionDays()'),
    'settings form entity must expose current transcript retention'
);

echo "Transcript retention save contract OK\n";
