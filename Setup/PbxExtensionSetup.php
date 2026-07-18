<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Setup;

use MikoPBX\Common\Models\ApiKeys;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Common\Providers\ModulesDBConnectionsProvider;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use MikoPBX\PBXCoreREST\Services\TokenValidationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptSchemaService;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseBackup;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleTransaction;
use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\RetryStateStore;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerApiKeyService;
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
            LocalSpeechToTextLogger::write('setup', $message, LOG_ERR);
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
            LocalSpeechToTextLogger::write('setup', $message, LOG_ERR);
            $this->messages[] = $message;
            return false;
        }

        try {
            $backup->discard($verifiedSnapshot);
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write(
                'setup',
                'Verified STT backup was published but temporary snapshot cleanup failed: '
                    . $exception->getMessage(),
                LOG_WARNING
            );
        }

        return true;
    }

    public function installDB(): bool
    {
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
            if (!parent::installDB()) {
                throw new \RuntimeException('Core database setup returned failure');
            }

            $this->cleanupLegacyLifecycleFiles();
            $this->setDefaultSetting(STTSetting::DEFAULT_LANGUAGE, STTSetting::AUTO_LANGUAGE);
            $this->setDefaultSetting(STTSetting::POLL_INTERVAL_SEC, '30');
            $this->setDefaultSetting(STTSetting::MAX_RECORDING_DURATION_MIN, '60');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_LIMIT, '200');
            $this->setDefaultSetting(STTSetting::CDR_HISTORY_DAYS, '30');
            $this->setDefaultSetting(STTSetting::PROCESSING_TIMEOUT_SEC, '1800');
            $this->setDefaultSetting(STTSetting::CALL_TRANSCRIPT_STABILITY_SEC, '30');
            $this->setDefaultSetting(STTSetting::SELECTED_MODEL, STTSetting::DEFAULT_MODEL);
            $this->setDefaultSetting(STTSetting::DELAYED_RECORDINGS, '[]');
            $this->setDefaultSetting(STTSetting::SKIPPED_RECORDINGS, '[]');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_CURSOR_ENDTIME, '');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_CURSOR_ID, '0');
            $this->setDefaultSetting(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, '');
            $this->setDefaultSetting(STTSetting::CDR_BACKFILL_CURSOR_ID, '0');
            $this->setDefaultSetting(STTSetting::WORKER_API_KEY_ID, '');
            $this->setDefaultSetting(STTSetting::WORKER_API_KEY_IDS, '[]');
            foreach (WorkerProcessingSettingsService::defaults() as $key => $value) {
                $encoded = is_bool($value)
                    ? ($value ? '1' : '0')
                    : (json_encode($value, JSON_PRESERVE_ZERO_FRACTION) ?: (string)$value);
                $this->setDefaultSetting($key, $encoded);
            }
            $this->ensureWorkerProcessingSettingsFlag();
            $this->ensureJobCdrEndtimeColumn();
            $this->ensureJobUpdatedAtColumn();
            $this->ensureJobLeaseColumns();
            $this->ensureJobCallIdentityColumns();
            $this->ensureJobRecordingDurationColumn();
            $this->ensureRetryStateSchema();
            $this->importLegacyRetryState();
            $this->ensureTechnicalInfoColumn();
            $this->ensureTranscriptEventTable();
            (new CallTranscriptSchemaService())->ensure((new STTResult())->getWriteConnection());
            STTTranscriptEvent::backfillCompletedEvents();
            (new CallTranscriptMigration())->run();
            $this->ensureWorkerApiKeyPermissions();
            try {
                $databaseBackup->discard($setupSnapshot);
            } catch (Throwable $exception) {
                LocalSpeechToTextLogger::write(
                    'setup',
                    'Database setup succeeded but temporary snapshot cleanup failed: '
                        . $exception->getMessage(),
                    LOG_WARNING
                );
            }
            LocalSpeechToTextLogger::write('setup', 'Database setup completed', LOG_NOTICE);
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
            LocalSpeechToTextLogger::write('setup', $message, LOG_ERR);
            $this->messages[] = $message;
            return false;
        }

        return true;
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
            LocalSpeechToTextLogger::write(
                'setup',
                'Database snapshot restored but temporary snapshot cleanup failed: '
                    . $exception->getMessage(),
                LOG_WARNING
            );
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
        } elseif ($connection->close() !== true && $recoveryFailure === null) {
            $recoveryFailure = new \RuntimeException('Unable to close module database connection');
        }
        if ($recoveryFailure !== null) {
            throw $recoveryFailure;
        }
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
                LocalSpeechToTextLogger::write(
                    'setup',
                    'Unable to remove obsolete runtime file: ' . $fileName,
                    LOG_WARNING
                );
            }
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

    private function setDefaultSetting(string $key, string $value): void
    {
        if (STTSetting::getValue($key, '') === '') {
            STTSetting::setValue($key, $value);
        }
    }

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

    private function ensureTechnicalInfoColumn(): void
    {
        $result = new STTResult();
        $connection = $result->getWriteConnection();
        foreach ($connection->describeColumns($result->getSource()) as $column) {
            if ($column->getName() === 'technical_info') {
                return;
            }
        }

        $connection->execute('ALTER TABLE m_STTResult ADD COLUMN technical_info TEXT');
    }

    private function ensureRetryStateSchema(): void
    {
        (new RetryStateStore((new STTJob())->getWriteConnection()))->ensureSchema();
    }

    private function importLegacyRetryState(): void
    {
        $store = new RetryStateStore((new STTJob())->getWriteConnection());
        $store->importLegacy(
            $this->storedRetryRecords(STTSetting::DELAYED_RECORDINGS),
            RetryStateStore::TYPE_DELAYED,
            'recording_missing'
        );
        $store->importLegacy(
            $this->storedRetryRecords(STTSetting::SKIPPED_RECORDINGS),
            RetryStateStore::TYPE_SKIPPED,
            'legacy_skipped'
        );
    }

    /**
     * @return array<int|string, mixed>
     */
    private function storedRetryRecords(string $settingKey): array
    {
        $records = json_decode(STTSetting::getValue($settingKey, '[]'), true);

        return is_array($records) ? $records : [];
    }

    private function ensureJobCdrEndtimeColumn(): void
    {
        $job = new STTJob();
        $connection = $job->getWriteConnection();
        foreach ($connection->describeColumns($job->getSource()) as $column) {
            if ($column->getName() === 'cdr_endtime') {
                return;
            }
        }

        $connection->execute('ALTER TABLE m_STTJob ADD COLUMN cdr_endtime INTEGER');
        $connection->execute('UPDATE m_STTJob SET cdr_endtime = created_at WHERE cdr_endtime IS NULL OR cdr_endtime = 0');
    }

    private function ensureJobUpdatedAtColumn(): void
    {
        $job = new STTJob();
        $connection = $job->getWriteConnection();
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

    private function ensureJobLeaseColumns(): void
    {
        $job = new STTJob();
        $connection = $job->getWriteConnection();
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
                    'timeout' => (int)STTSetting::getValue(STTSetting::PROCESSING_TIMEOUT_SEC, '1800'),
                    'processing' => STTJob::STATUS_PROCESSING,
                ]
            );
        }
    }

    private function ensureJobCallIdentityColumns(): void
    {
        $job = new STTJob();
        $connection = $job->getWriteConnection();
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
            'CREATE INDEX IF NOT EXISTS m_STTJob_call_status ON m_STTJob (call_id, status)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS m_STTJob_cdr_id ON m_STTJob (cdr_id)'
        );
    }

    private function ensureJobRecordingDurationColumn(): void
    {
        $job = new STTJob();
        $connection = $job->getWriteConnection();
        foreach ($connection->describeColumns($job->getSource()) as $column) {
            if ($column->getName() === 'recording_duration_sec') {
                return;
            }
        }

        $connection->execute('ALTER TABLE m_STTJob ADD COLUMN recording_duration_sec INTEGER');
    }

    private function ensureTranscriptEventTable(): void
    {
        $result = new STTResult();
        $connection = $result->getWriteConnection();
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
            'CREATE UNIQUE INDEX IF NOT EXISTS m_STTTranscriptEvent_event_key
                ON m_STTTranscriptEvent (event_key)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS m_STTTranscriptEvent_event_cursor
                ON m_STTTranscriptEvent (created_at, id)'
        );
        $connection->execute(
            'CREATE INDEX IF NOT EXISTS m_STTTranscriptEvent_result_id
                ON m_STTTranscriptEvent (result_id)'
        );
    }

    private function ensureWorkerApiKeyPermissions(): void
    {
        $changed = false;

        foreach (STTSetting::workerApiKeyIds() as $apiKeyId) {
            $apiKey = ApiKeys::findFirstById($apiKeyId);
            if (!$apiKey instanceof ApiKeys) {
                continue;
            }

            $allowedPaths = json_decode((string)$apiKey->allowed_paths, true);
            if (!is_array($allowedPaths)) {
                $allowedPaths = [];
            }

            $apiKeyChanged = $allowedPaths !== WorkerApiKeyService::WORKER_ALLOWED_PATHS
                || (string)$apiKey->full_permissions !== '0';

            if ($apiKeyChanged) {
                $apiKey->full_permissions = '0';
                $apiKey->allowed_paths = json_encode(
                    WorkerApiKeyService::WORKER_ALLOWED_PATHS,
                    JSON_THROW_ON_ERROR
                );
                if (!$apiKey->save()) {
                    throw new \RuntimeException(implode(', ', $apiKey->getMessages()));
                }
                $changed = true;
            }
        }

        if ($changed) {
            TokenValidationService::clearCache();
        }
    }
}
