<?php

declare(strict_types=1);

namespace MikoPBX\Modules\Models {
    class ModulesModelsBase
    {
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;

    require_once __DIR__ . '/../Models/STTSetting.php';

    function expectRetentionSettings(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    expectRetentionSettings(
        defined(STTSetting::class . '::TRANSCRIPT_RETENTION_DAYS')
            && STTSetting::TRANSCRIPT_RETENTION_DAYS === 'transcript_retention_days',
        'transcript retention setting key is missing'
    );
    expectRetentionSettings(
        defined(STTSetting::class . '::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT')
            && STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT === 'transcript_retention_last_cleanup_at',
        'transcript retention cleanup marker key is missing'
    );
    expectRetentionSettings(
        STTSetting::TRANSCRIPT_RETENTION_PRESETS === [30, 90, 180, 365, 0],
        'transcript retention presets mismatch'
    );
    expectRetentionSettings(
        STTSetting::CDR_HISTORY_PRESETS === [1, 7, 30, 90, 180, 365, 0],
        'recording processing presets must not contain two years'
    );
    expectRetentionSettings(
        STTSetting::normalizeCdrHistoryDays(730) === 365,
        'legacy two-year processing window must normalize to one year'
    );
    expectRetentionSettings(
        STTSetting::normalizeTranscriptRetentionDays(999) === 365,
        'unsupported retention must use the one-year default'
    );
    expectRetentionSettings(
        STTSetting::normalizeTranscriptRetentionDays(0) === 0,
        'unlimited retention must remain unlimited'
    );
    expectRetentionSettings(
        STTSetting::retentionAllowsProcessing(365, 365),
        'equal finite windows must be valid'
    );
    expectRetentionSettings(
        !STTSetting::retentionAllowsProcessing(0, 365),
        'all-recordings processing requires unlimited retention'
    );
    expectRetentionSettings(
        !STTSetting::retentionAllowsProcessing(180, 90),
        'processing cannot exceed finite retention'
    );
    expectRetentionSettings(
        STTSetting::retentionAllowsProcessing(0, 0),
        'both unlimited windows must be valid'
    );
    expectRetentionSettings(
        STTSetting::retentionAllowsProcessing(365, 0),
        'unlimited retention must allow finite processing windows'
    );

    $setup = (string)file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
    $indexes = (string)file_get_contents(__DIR__ . '/../Lib/Services/ModuleDatabaseIndexService.php');
    expectRetentionSettings(
        str_contains($setup, "10,\n                'transcript retention policy'")
            && str_contains($setup, 'applyTranscriptRetentionMigration'),
        'schema migration 10 must install transcript retention policy'
    );
    expectRetentionSettings(
        str_contains($indexes, 'i_m_STTResult_created_at'),
        'result processing timestamp index is missing'
    );

    echo "Transcript retention settings contract OK\n";
}
