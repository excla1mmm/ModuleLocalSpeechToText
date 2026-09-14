<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Setup;

use MikoPBX\Common\Models\ApiKeys;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Common\Providers\ModulesDBConnectionsProvider;
use MikoPBX\Core\System\Directories;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use MikoPBX\PBXCoreREST\Services\TokenValidationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptSchemaService;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextJournalFormatMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLegacyLogMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogWriter;
use Modules\ModuleLocalSpeechToText\Lib\Services\JobSubmissionReceiptMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleBatchMigrationRunner;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseBackup;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseIndexService;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleMetadataCache;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleSchemaMigrator;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore;
use Modules\ModuleLocalSpeechToText\Lib\Services\SttSourceInstance;
use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptSearchIndexService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiV2Migration;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerBindingIntegrityMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTWorker;
use Throwable;

class PbxExtensionSetup extends PbxExtensionSetupBase
{
    public function unInstallFiles(bool $keepSettings = false): bool
    {
        if (!$keepSettings) {
            return parent::unInstallFiles(false);
        }

        $backup = new ModuleDatabaseBackup();
        $modulesDirectory = rtrim((string)$this->config->path('core.modulesDir'), '/');
        $coreBackupDirectory = $modulesDirectory . '/Backup/' . $this->moduleUniqueID;
        $verifiedSnapshot = $modulesDirectory . '/Backup/.' . $this->moduleUniqueID
            . '.verified-' . getmypid() . '-' . bin2hex(random_bytes(6));

        try {
            $backup->snapshot($this->moduleDir . '/db', $verifiedSnapshot);
        } catch (Throwable $exception) {
            $message = 'Unable to create verified STT database backup: ' . $exception->getMessage();
            self::logSetupFailure('create_database_backup', $exception);
            $this->messages[] = $message;
            return false;
        }

        try {
            if (!parent::unInstallFiles(true)) {
                $this->messages[] = 'Core file cleanup failed; verified STT backup retained at ' . $verifiedSnapshot;
                return false;
            }

            $backup->restore($verifiedSnapshot, $coreBackupDirectory . '/db');
        } catch (Throwable $exception) {
            $message = 'Unable to publish verified STT database backup; snapshot retained at '
                . $verifiedSnapshot . ': ' . $exception->getMessage();
            self::logSetupFailure('publish_database_backup', $exception);
            $this->messages[] = $message;
            return false;
        }

        try {
            $backup->discard($verifiedSnapshot);
        } catch (Throwable $exception) {
            self::logSetupFailure('cleanup_published_backup_snapshot', $exception, LOG_WARNING);
        }

        return true;
    }

    public function installDB(): bool
    {
        $this->migrateLegacyModuleLogs();
        $lifecycleGate = new ModuleLifecycleGate($this->moduleDir . '/db', bootstrap: true);
        if (!$lifecycleGate->beginDrain(30000)) {
            $this->messages[] = 'STT database writers did not drain before setup';
            return false;
        }
        $lifecycleGate->completeDisable();

        $databaseBackup = new ModuleDatabaseBackup();
        $setupSnapshot = null;

        try {
            $setupSnapshot = $this->createSetupDatabaseSnapshot($databaseBackup);
            $this->cleanupLegacyLifecycleFiles();
            $this->cleanupLegacyScannerRuntime();

            (new ModuleMetadataCache())->invalidate();
            $connection = (new STTJob())->getWriteConnection();
            if ($this->isFreshModuleDatabase($connection)) {
                if (!method_exists($connection, 'close') || $connection->close() === false) {
                    throw new \RuntimeException('Unable to close fresh STT database before bootstrap');
                }
                if (!$this->createSettingsTableByModelsAnnotations()) {
                    throw new \RuntimeException('Fresh STT database bootstrap failed');
                }
                $connection = (new STTJob())->getWriteConnection();
            }

            $migrator = new ModuleSchemaMigrator($this->moduleSchemaMigrations());
            $migrator->migrate(
                $connection,
                static function (): never {
                    throw new \RuntimeException('Fresh STT schema was not bootstrapped before migrations');
                }
            );
            $this->prepareTranscriptSearchIndex($connection);
            (new ModuleTransaction())->run(
                $connection,
                static fn (mixed $database): string => (new SttSourceInstance())->ensure($database),
                0
            );
            if (!method_exists($connection, 'close') || $connection->close() === false) {
                throw new \RuntimeException('Unable to close STT database after migrations');
            }
            $connection = null;
            (new ModuleMetadataCache())->invalidate();

            $databasePath = $this->moduleDir . '/db/module.db';
            $databaseBackup->verify($databasePath);
            $databasePragmas = new ModuleDatabasePragmas();
            $databasePragmas->writeSafeBackupCapability($this->moduleDir . '/db');
            $connection = (new STTJob())->getWriteConnection();
            $databasePragmas->enableWalAfterVerifiedSetup($connection, $databasePath);
            $databasePragmas->checkpoint($connection);

            if (!$this->registerNewModule()) {
                throw new \RuntimeException('Unable to register STT module');
            }
            if (!$this->addToSidebar()) {
                throw new \RuntimeException('Unable to register STT sidebar item');
            }
            $this->ensureWorkerApiKeyPermissions();
            if (!method_exists($connection, 'close') || $connection->close() === false) {
                throw new \RuntimeException('Unable to close STT database after runtime configuration');
            }
            $connection = null;
            try {
                $databaseBackup->discard($setupSnapshot);
            } catch (Throwable $exception) {
                self::logSetupFailure('cleanup_setup_snapshot', $exception, LOG_WARNING);
            }
            LocalSpeechToTextLogger::event(
                'setup',
                'module_setup_completed',
                'STT module database setup completed',
                LOG_NOTICE
            );
        } catch (Throwable $exception) {
            $recoveryFailure = null;
            try {
                $this->recoverSetupDatabaseConnection();
            } catch (Throwable $recoveryException) {
                $recoveryFailure = $recoveryException;
            }

            $restoreFailure = null;
            if ($setupSnapshot !== null) {
                try {
                    $this->restoreSetupDatabaseSnapshot($databaseBackup, $setupSnapshot);
                } catch (Throwable $restoreException) {
                    $restoreFailure = $restoreException;
                }
            }

            $message = 'Database setup failed: ' . $exception->getMessage();
            if ($recoveryFailure !== null) {
                $message .= '; connection recovery failed: ' . $recoveryFailure->getMessage();
            }
            if ($restoreFailure !== null) {
                $message .= '; snapshot restore failed: ' . $restoreFailure->getMessage();
            }
            self::logSetupFailure('database_setup', $exception);
            $this->messages[] = $message;
            return false;
        }

        return true;
    }

    private function migrateLegacyModuleLogs(): void
    {
        try {
            $directory = rtrim(Directories::getDir(Directories::CORE_LOGS_DIR), '/')
                . '/ModuleLocalSpeechToText';
            $formatSummary = (new LocalSpeechToTextJournalFormatMigration($directory))->run();
            $summary = ['imported' => 0, 'removed' => 0, 'skipped' => 0];
            if ($formatSummary['failed'] === 0) {
                $summary = (new LocalSpeechToTextLegacyLogMigration(
                    $directory,
                    new LocalSpeechToTextLogWriter($directory)
                ))->run();
            }
            if ($formatSummary['files'] > 0) {
                LocalSpeechToTextLogger::event(
                    'setup',
                    'log_format_migrated',
                    'Module journal format was upgraded',
                    LOG_NOTICE,
                    [
                        'converted_count' => $formatSummary['converted'],
                        'invalid_count' => $formatSummary['invalid'],
                        'truncated_count' => $formatSummary['truncated'],
                    ]
                );
            }
            if ($summary['imported'] > 0 || $summary['removed'] > 0 || $summary['skipped'] > 0) {
                LocalSpeechToTextLogger::event(
                    'setup',
                    'legacy_logs_migrated',
                    'Legacy STT module logs migrated',
                    LOG_NOTICE,
                    [
                        'imported_count' => $summary['imported'],
                        'removed_count' => $summary['removed'],
                        'skipped_count' => $summary['skipped'],
                    ]
                );
            }
        } catch (Throwable $exception) {
            self::logSetupFailure('legacy_log_migration', $exception, LOG_WARNING);
        }
    }

    private static function logSetupFailure(string $operation, Throwable $exception, int $level = LOG_ERR): void
    {
        LocalSpeechToTextLogger::event(
            'setup',
            'operation_failed',
            'STT module setup operation failed',
            $level,
            [
                'operation' => $operation,
                'error_code' => LocalSpeechToTextLogSanitizer::errorCode($exception),
            ]
        );
    }

    private function createSetupDatabaseSnapshot(ModuleDatabaseBackup $backup): string
    {
        $snapshot = $this->moduleDir . '/.setup-database-snapshot-'
            . getmypid() . '-' . bin2hex(random_bytes(6));
        $backup->snapshot($this->moduleDir . '/db', $snapshot);

        return $snapshot;
    }

    private function restoreSetupDatabaseSnapshot(ModuleDatabaseBackup $backup, string $snapshot): void
    {
        $backup->restore($snapshot, $this->moduleDir . '/db');
        try {
            $backup->discard($snapshot);
        } catch (Throwable $exception) {
            self::logSetupFailure('cleanup_restored_snapshot', $exception, LOG_WARNING);
        }
        ModulesDBConnectionsProvider::recreateModulesDBConnections();
    }

    private function recoverSetupDatabaseConnection(): void
    {
        $connection = (new STTJob())->getWriteConnection();
        $recoveryFailure = null;
        try {
            (new ModuleTransaction())->recover($connection);
        } catch (Throwable $exception) {
            $recoveryFailure = $exception;
        }

        if (!method_exists($connection, 'close')) {
            $recoveryFailure ??= new \RuntimeException('Module database connection cannot be closed safely');
        } elseif ($connection->close() === false && $recoveryFailure === null) {
            $recoveryFailure = new \RuntimeException('Unable to close module database connection');
        }
        if ($recoveryFailure !== null) {
            throw $recoveryFailure;
        }
    }

    /**
     * @return array<int, ModuleSchemaMigration>
     */
    private function moduleSchemaMigrations(): array
    {
        return [
            new ModuleSchemaMigration(
                1,
                'base settings',
                fn (mixed $connection): null => $this->applyBaseSettingsMigration($connection),
                'stt-schema-001-base-settings'
            ),
            new ModuleSchemaMigration(
                2,
                'worker and job schema',
                fn (mixed $connection): null => $this->applyWorkerAndJobMigration($connection),
                'stt-schema-002-worker-job'
            ),
            new ModuleSchemaMigration(
                3,
                'durable retry state',
                fn (mixed $connection): null => $this->applyRetryStateMigration($connection),
                'stt-schema-003-retry-state'
            ),
            new ModuleSchemaMigration(
                4,
                'transcript result events',
                fn (mixed $connection): null => $this->applyTranscriptEventMigration($connection),
                'stt-schema-004-transcript-events'
            ),
            new ModuleSchemaMigration(
                5,
                'call transcript revisions',
                fn (mixed $connection): null => $this->applyCallTranscriptMigration($connection),
                'stt-schema-005-call-transcripts'
            ),
            new ModuleSchemaMigration(
                6,
                'worker processing defaults',
                fn (mixed $connection): null => $this->applyWorkerProcessingMigration($connection),
                'stt-schema-006-worker-processing'
            ),
            new ModuleSchemaMigration(
                7,
                'canonical module indexes',
                fn (mixed $connection): null => $this->applyModuleIndexMigration($connection),
                'stt-schema-007-module-indexes'
            ),
            new ModuleSchemaMigration(
                8,
                'complete transcript result event backfill',
                fn (mixed $connection): null => $this->applyTranscriptEventCompletionMigration($connection),
                'stt-schema-008-complete-transcript-events'
            ),
            new ModuleSchemaMigration(
                9,
                'complete call transcript backfill',
                fn (mixed $connection): null => $this->applyCallTranscriptCompletionMigration($connection),
                'stt-schema-009-complete-call-transcripts'
            ),
            new ModuleSchemaMigration(
                10,
                'transcript retention policy',
                fn (mixed $connection): null => $this->applyTranscriptRetentionMigration($connection),
                'stt-schema-010-transcript-retention'
            ),
            new ModuleSchemaMigration(
                11,
                'worker API v2 contract',
                fn (mixed $connection): null => $this->applyWorkerApiV2Migration($connection),
                'stt-schema-011-worker-api-v2'
            ),
            new ModuleSchemaMigration(
                12,
                'exclusive worker API-key bindings',
                fn (mixed $connection): null => $this->applyWorkerBindingIntegrityMigration($connection),
                'stt-schema-012-exclusive-worker-api-key-bindings'
            ),
            new ModuleSchemaMigration(
                13,
                'repair v2-only worker API-key binding index',
                fn (mixed $connection): null => $this->applyWorkerBindingIntegrityMigration($connection),
                'stt-schema-013-repair-v2-worker-api-key-index'
            ),
            new ModuleSchemaMigration(
                14,
                'terminal job submission receipts',
                fn (mixed $connection): null => $this->applyJobSubmissionReceiptMigration($connection),
                'stt-schema-014-job-submission-receipts'
            ),
            new ModuleSchemaMigration(
                15,
                'persistent STT source instance identity',
                fn (mixed $connection): null => $this->applySourceInstanceIdentityMigration($connection),
                'stt-schema-015-source-instance-identity'
            ),
            new ModuleSchemaMigration(
                16,
                'transcript full-text search',
                fn (mixed $connection): null => $this->applyTranscriptSearchMigration($connection),
                'stt-schema-016-transcript-full-text-search'
            ),
        ];
    }

    private function applyTranscriptSearchMigration(mixed $connection): null
    {
        (new TranscriptSearchIndexService())->ensureSchema($connection);

        return null;
    }

    private function prepareTranscriptSearchIndex(mixed $connection): void
    {
        $search = new TranscriptSearchIndexService();
        if (!$search->prepare($connection)) {
            return;
        }

        try {
            (new ModuleBatchMigrationRunner())->run(
                static fn (): int => $search->backfillBatch($connection, 250)
            );
        } catch (Throwable) {
            $search->disable($connection);
        }
    }

    private function applySourceInstanceIdentityMigration(mixed $connection): null
    {
        (new SttSourceInstance())->ensure($connection);

        return null;
    }

    private function isFreshModuleDatabase(mixed $connection): bool
    {
        return $connection->fetchAll(
            "SELECT name
               FROM sqlite_master
              WHERE type = 'table'
                AND name NOT LIKE 'sqlite_%'
                AND name <> 'm_STTSchemaMigration'
              LIMIT 1",
            \PDO::FETCH_ASSOC
        ) === [];
    }

    private function applyBaseSettingsMigration(mixed $connection): null
    {
        foreach ([
            STTSetting::DEFAULT_LANGUAGE => STTSetting::AUTO_LANGUAGE,
            STTSetting::CDR_HISTORY_DAYS => '30',
            STTSetting::CALL_TRANSCRIPT_STABILITY_SEC => '30',
            STTSetting::SELECTED_MODEL => STTSetting::DEFAULT_MODEL,
            STTSetting::DELAYED_RECORDINGS => '[]',
            STTSetting::SKIPPED_RECORDINGS => '[]',
            STTSetting::CDR_SCAN_CURSOR_ENDTIME => '',
            STTSetting::CDR_SCAN_CURSOR_ID => '0',
            STTSetting::CDR_BACKFILL_CURSOR_ENDTIME => '',
            STTSetting::CDR_BACKFILL_CURSOR_ID => '0',
            STTSetting::WORKER_API_KEY_ID => '',
            STTSetting::WORKER_API_KEY_IDS => '[]',
            STTSetting::WORKER_API_KEY_HISTORY_IDS => '[]',
        ] as $key => $value) {
            $this->setDefaultSetting($connection, $key, $value);
        }

        return null;
    }

    private function applyWorkerAndJobMigration(mixed $connection): null
    {
        $this->ensureWorkerProcessingSettingsFlag($connection);
        $this->ensureJobCdrEndtimeColumn($connection);
        $this->ensureJobUpdatedAtColumn($connection);
        $this->ensureJobLeaseColumns($connection);
        $this->ensureJobCallIdentityColumns($connection);
        $this->ensureJobRecordingDurationColumn($connection);

        return null;
    }

    private function applyRetryStateMigration(mixed $connection): null
    {
        $this->ensureRetryStateSchema($connection);
        $this->importLegacyRetryState($connection);

        return null;
    }

    private function applyTranscriptEventMigration(mixed $connection): null
    {
        $this->ensureTechnicalInfoColumn($connection);
        $this->ensureTranscriptEventTable($connection);
        STTTranscriptEvent::backfillCompletedEvents();

        return null;
    }

    private function applyCallTranscriptMigration(mixed $connection): null
    {
        (new CallTranscriptSchemaService())->ensure($connection);
        (new CallTranscriptMigration())->run();

        return null;
    }

    private function applyWorkerProcessingMigration(mixed $connection): null
    {
        foreach (WorkerProcessingSettingsService::defaults() as $key => $value) {
            $encoded = is_bool($value)
                ? ($value ? '1' : '0')
                : (json_encode($value, JSON_PRESERVE_ZERO_FRACTION) ?: (string)$value);
            $this->setDefaultSetting($connection, $key, $encoded);
        }

        return null;
    }

    private function applyModuleIndexMigration(mixed $connection): null
    {
        (new ModuleDatabaseIndexService())->ensure($connection);

        return null;
    }

    private function applyTranscriptEventCompletionMigration(mixed $connection): null
    {
        (new ModuleBatchMigrationRunner())->run(
            static fn (): int => STTTranscriptEvent::backfillCompletedEvents(
                1000,
                preserveCursorVisibility: true
            )
        );

        return null;
    }

    private function applyCallTranscriptCompletionMigration(mixed $connection): null
    {
        $migration = new CallTranscriptMigration();
        (new ModuleBatchMigrationRunner())->run(
            static fn (): int => (int)$migration->run(10000)['source_results']
        );
        $migration->publishAllReady();

        return null;
    }

    private function applyTranscriptRetentionMigration(mixed $connection): null
    {
        $this->setDefaultSetting($connection, STTSetting::TRANSCRIPT_RETENTION_DAYS, '365');
        $this->setDefaultSetting($connection, STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT, '0');

        $historyDays = STTSetting::normalizeCdrHistoryDays(
            $this->settingValue($connection, STTSetting::CDR_HISTORY_DAYS, '30')
        );
        if ($historyDays === 0) {
            $historyDays = 365;
        }
        $connection->execute(
            'UPDATE m_STTSetting SET value = :value WHERE key = :key',
            [
                'value' => (string)$historyDays,
                'key' => STTSetting::CDR_HISTORY_DAYS,
            ]
        );
        (new ModuleDatabaseIndexService())->ensure($connection);

        return null;
    }

    private function applyWorkerApiV2Migration(mixed $connection): null
    {
        (new WorkerApiV2Migration())->migrate($connection);

        return null;
    }

    private function applyWorkerBindingIntegrityMigration(mixed $connection): null
    {
        (new WorkerBindingIntegrityMigration())->migrate($connection);

        return null;
    }

    private function applyJobSubmissionReceiptMigration(mixed $connection): null
    {
        (new JobSubmissionReceiptMigration())->migrate($connection);
        return null;
    }

    private function cleanupLegacyLifecycleFiles(): void
    {
        $databaseDirectory = $this->moduleDir . '/db';
        foreach ([
            '.module-lifecycle-state.json',
            '.module-lifecycle.lock',
            '.module-lifecycle-transition.lock',
        ] as $fileName) {
            $path = $databaseDirectory . '/' . $fileName;
            if (!is_file($path) && !is_link($path)) {
                continue;
            }
            if (!@unlink($path)) {
                LocalSpeechToTextLogger::event(
                    'setup',
                    'operation_failed',
                    'Obsolete module runtime file could not be removed',
                    LOG_WARNING,
                    [
                        'operation' => 'cleanup_legacy_runtime_file',
                        'error_code' => 'unlink_failed',
                    ]
                );
            }
        }
    }

    private function cleanupLegacyScannerRuntime(): void
    {
        $legacyScannerDirectory = '/var/run/php-workers/ModuleLocalSpeechToText/scanner';
        $removedLegacyRuntime = false;
        if (is_dir($legacyScannerDirectory)) {
            foreach (
                [
                    '.scanner-process.lock',
                    '.scanner-recovery-state.json',
                ] as $fileName
            ) {
                $path = $legacyScannerDirectory . '/' . $fileName;
                if (!is_file($path) && !is_link($path)) {
                    continue;
                }
                if (@unlink($path)) {
                    $removedLegacyRuntime = true;
                } else {
                    LocalSpeechToTextLogger::event(
                        'setup',
                        'operation_failed',
                        'Obsolete scanner runtime file could not be removed',
                        LOG_WARNING,
                        [
                            'operation' => 'cleanup_legacy_scanner_runtime',
                            'error_code' => 'unlink_failed',
                        ]
                    );
                }
            }
            $scannerEntries = array_values(
                array_diff(scandir($legacyScannerDirectory) ?: [], ['.', '..'])
            );
            if ($scannerEntries === [] && !@rmdir($legacyScannerDirectory)) {
                LocalSpeechToTextLogger::event(
                    'setup',
                    'operation_failed',
                    'Obsolete scanner runtime directory could not be removed',
                    LOG_WARNING,
                    [
                        'operation' => 'cleanup_legacy_scanner_runtime',
                        'error_code' => 'rmdir_failed',
                    ]
                );
            }
        }
        $legacyParentDirectory = '/var/run/php-workers/ModuleLocalSpeechToText';
        if (is_dir($legacyParentDirectory)) {
            $parentEntries = array_values(
                array_diff(scandir($legacyParentDirectory) ?: [], ['.', '..'])
            );
            if ($parentEntries === [] && !@rmdir($legacyParentDirectory)) {
                LocalSpeechToTextLogger::event(
                    'setup',
                    'operation_failed',
                    'Obsolete scanner runtime parent directory could not be removed',
                    LOG_WARNING,
                    [
                        'operation' => 'cleanup_legacy_scanner_runtime',
                        'error_code' => 'rmdir_failed',
                    ]
                );
            }
        }
        if ($removedLegacyRuntime) {
            LocalSpeechToTextLogger::event(
                'setup',
                'scanner_runtime_migrated',
                'Scanner runtime directory left the Core-normalized worker lock tree',
                LOG_NOTICE,
                [
                    'operation' => 'cleanup_legacy_scanner_runtime',
                ]
            );
        }
    }

    public function addToSidebar(): bool
    {
        $menuSettingsKey = "AdditionalMenuItem$this->moduleUniqueID";
        $menuSettings = PbxSettings::findFirstByKey($menuSettingsKey);
        if ($menuSettings === null) {
            $menuSettings = new PbxSettings();
            $menuSettings->key = $menuSettingsKey;
        }

        $menuSettings->value = json_encode([
            'uniqid' => $this->moduleUniqueID,
            'group' => 'modules',
            'iconClass' => 'microphone',
            'caption' => "Breadcrumb$this->moduleUniqueID",
            'showAtSidebar' => true,
        ]);

        return $menuSettings->save();
    }

    private function setDefaultSetting(mixed $connection, string $key, string $value): void
    {
        $connection->execute(
            'INSERT INTO m_STTSetting (key, value) VALUES (:key, :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value
              WHERE m_STTSetting.value IS NULL OR m_STTSetting.value = \'\'',
            ['key' => $key, 'value' => $value]
        );
    }

    private function ensureWorkerProcessingSettingsFlag(mixed $connection): void
    {
        $worker = new STTWorker();
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

    private function ensureTechnicalInfoColumn(mixed $connection): void
    {
        $result = new STTResult();
        foreach ($connection->describeColumns($result->getSource()) as $column) {
            if ($column->getName() === 'technical_info') {
                return;
            }
        }

        $connection->execute('ALTER TABLE m_STTResult ADD COLUMN technical_info TEXT');
    }

    private function ensureRetryStateSchema(mixed $connection): void
    {
        (new RetryStateStore($connection))->ensureSchema();
    }

    private function importLegacyRetryState(mixed $connection): void
    {
        $store = new RetryStateStore($connection);
        $store->importLegacy(
            $this->storedRetryRecords($connection, STTSetting::DELAYED_RECORDINGS),
            RetryStateStore::TYPE_DELAYED,
            'recording_missing'
        );
        $store->importLegacy(
            $this->storedRetryRecords($connection, STTSetting::SKIPPED_RECORDINGS),
            RetryStateStore::TYPE_SKIPPED,
            'legacy_skipped'
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function storedRetryRecords(mixed $connection, string $settingKey): array
    {
        $rows = $connection->fetchAll(
            'SELECT value FROM m_STTSetting WHERE key = :key LIMIT 1',
            \PDO::FETCH_ASSOC,
            ['key' => $settingKey]
        );
        $records = json_decode((string)($rows[0]['value'] ?? '[]'), true);

        return is_array($records) ? $records : [];
    }

    private function settingValue(mixed $connection, string $key, string $default): string
    {
        $rows = $connection->fetchAll(
            'SELECT value FROM m_STTSetting WHERE key = :key LIMIT 1',
            \PDO::FETCH_ASSOC,
            ['key' => $key]
        );
        $value = (string)($rows[0]['value'] ?? '');

        return $value === '' ? $default : $value;
    }

    private function ensureJobCdrEndtimeColumn(mixed $connection): void
    {
        $job = new STTJob();
        foreach ($connection->describeColumns($job->getSource()) as $column) {
            if ($column->getName() === 'cdr_endtime') {
                return;
            }
        }

        $connection->execute('ALTER TABLE m_STTJob ADD COLUMN cdr_endtime INTEGER');
        $connection->execute('UPDATE m_STTJob SET cdr_endtime = created_at WHERE cdr_endtime IS NULL OR cdr_endtime = 0');
    }

    private function ensureJobUpdatedAtColumn(mixed $connection): void
    {
        $job = new STTJob();
        $hasUpdatedAtColumn = false;
        foreach ($connection->describeColumns($job->getSource()) as $column) {
            if ($column->getName() === 'updated_at') {
                $hasUpdatedAtColumn = true;
                break;
            }
        }

        if (!$hasUpdatedAtColumn) {
            $connection->execute('ALTER TABLE m_STTJob ADD COLUMN updated_at INTEGER NOT NULL DEFAULT 0');
        }
        $connection->execute(
            'UPDATE m_STTJob
                SET updated_at = CASE
                    WHEN finished_at IS NOT NULL AND finished_at <> \'\' AND CAST(finished_at AS INTEGER) > 0 THEN finished_at
                    WHEN started_at IS NOT NULL AND started_at <> \'\' AND CAST(started_at AS INTEGER) > 0 THEN started_at
                    ELSE created_at
                END
              WHERE updated_at IS NULL OR updated_at = 0'
        );
    }

    private function ensureJobLeaseColumns(mixed $connection): void
    {
        $job = new STTJob();
        $existingColumns = [];
        foreach ($connection->describeColumns($job->getSource()) as $column) {
            $existingColumns[$column->getName()] = true;
        }

        if (!isset($existingColumns['lease_token'])) {
            $connection->execute('ALTER TABLE m_STTJob ADD COLUMN lease_token TEXT');
        }
        if (!isset($existingColumns['lease_expires_at'])) {
            $connection->execute('ALTER TABLE m_STTJob ADD COLUMN lease_expires_at INTEGER');
            $connection->execute(
                'UPDATE m_STTJob
                    SET lease_expires_at = started_at + :timeout
                  WHERE status = :processing
                    AND started_at IS NOT NULL
                    AND started_at <> \'\'
                    AND lease_expires_at IS NULL',
                [
                    // Legacy compatibility only: 'processing_timeout_sec' is no longer a
                    // setting the module reads or writes going forward. This one-time
                    // schema migration backfills lease_expires_at for jobs that were
                    // already mid-flight under a pre-lease-column install, using
                    // whatever inert legacy value (if any) happens to still be stored.
                    'timeout' => (int)$this->settingValue(
                        $connection,
                        'processing_timeout_sec',
                        '1800'
                    ),
                    'processing' => STTJob::STATUS_PROCESSING,
                ]
            );
        }
    }

    private function ensureJobCallIdentityColumns(mixed $connection): void
    {
        $job = new STTJob();
        $existingColumns = [];
        foreach ($connection->describeColumns($job->getSource()) as $column) {
            $existingColumns[$column->getName()] = true;
        }

        $columns = [
            'cdr_id' => 'INTEGER',
            'cdr_uniqueid' => 'TEXT',
            'cdr_start_ms' => 'INTEGER',
            'cdr_end_ms' => 'INTEGER',
            'recording_fingerprint' => 'TEXT',
        ];
        foreach ($columns as $name => $type) {
            if (!isset($existingColumns[$name])) {
                $connection->execute("ALTER TABLE m_STTJob ADD COLUMN {$name} {$type}");
            }
        }
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS i_m_STTJob_call_status ON m_STTJob (call_id, status)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS i_m_STTJob_cdr_id ON m_STTJob (cdr_id)'
        );
    }

    private function ensureJobRecordingDurationColumn(mixed $connection): void
    {
        $job = new STTJob();
        foreach ($connection->describeColumns($job->getSource()) as $column) {
            if ($column->getName() === 'recording_duration_sec') {
                return;
            }
        }

        $connection->execute('ALTER TABLE m_STTJob ADD COLUMN recording_duration_sec INTEGER');
    }

    private function ensureTranscriptEventTable(mixed $connection): void
    {
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTTranscriptEvent (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type TEXT NOT NULL,
                event_key TEXT NOT NULL,
                result_id INTEGER NOT NULL,
                job_id INTEGER,
                call_id TEXT,
                update_type TEXT,
                payload TEXT,
                created_at INTEGER NOT NULL
            )'
        );
        $connection->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS i_m_STTTranscriptEvent_event_key
                ON m_STTTranscriptEvent (event_key)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS i_m_STTTranscriptEvent_event_cursor
                ON m_STTTranscriptEvent (created_at, id)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS i_m_STTTranscriptEvent_result_id
                ON m_STTTranscriptEvent (result_id)'
        );
    }

    private function ensureWorkerApiKeyPermissions(): void
    {
        (new WorkerApiKeyService())->reconcileStoredWorkerApiKeys();
    }
}
