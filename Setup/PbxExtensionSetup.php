<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Setup;

use MikoPBX\Common\Models\ApiKeys;
use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use MikoPBX\PBXCoreREST\Services\TokenValidationService;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Modules\ModuleLocalSpeechToText\Models\STTTranscriptEvent;
use Throwable;

class PbxExtensionSetup extends PbxExtensionSetupBase
{
    public function installDB(): bool
    {
        if (!parent::installDB()) {
            return false;
        }

        try {
            $this->setDefaultSetting(STTSetting::DEFAULT_LANGUAGE, STTSetting::AUTO_LANGUAGE);
            $this->setDefaultSetting(STTSetting::POLL_INTERVAL_SEC, '30');
            $this->setDefaultSetting(STTSetting::MAX_FILE_SIZE_MB, '500');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_LIMIT, '200');
            $this->setDefaultSetting(STTSetting::CDR_HISTORY_DAYS, '30');
            $this->setDefaultSetting(STTSetting::PROCESSING_TIMEOUT_SEC, '1800');
            $this->setDefaultSetting(STTSetting::SELECTED_MODEL, STTSetting::DEFAULT_MODEL);
            $this->setDefaultSetting(STTSetting::DELAYED_RECORDINGS, '[]');
            $this->setDefaultSetting(STTSetting::SKIPPED_RECORDINGS, '[]');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_CURSOR_ENDTIME, '');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_CURSOR_ID, '0');
            $this->setDefaultSetting(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, '');
            $this->setDefaultSetting(STTSetting::CDR_BACKFILL_CURSOR_ID, '0');
            $this->setDefaultSetting(STTSetting::WORKER_API_KEY_ID, '');
            $this->setDefaultSetting(STTSetting::WORKER_API_KEY_IDS, '[]');
            $this->ensureJobCdrEndtimeColumn();
            $this->ensureJobUpdatedAtColumn();
            $this->ensureJobLeaseColumns();
            $this->ensureTechnicalInfoColumn();
            $this->ensureTranscriptEventTable();
            STTTranscriptEvent::backfillCompletedEvents();
            $this->ensureWorkerApiKeyPermissions();
            LocalSpeechToTextLogger::write('setup', 'Database setup completed', LOG_NOTICE);
        } catch (Throwable $exception) {
            LocalSpeechToTextLogger::write('setup', 'Database setup failed: ' . $exception->getMessage(), LOG_ERR);
            $this->messages[] = $exception->getMessage();
            return false;
        }

        return true;
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
        $requiredPaths = [
            '/api/v3/module-local-speech-to-text/workers' => 'write',
            '/api/v3/module-local-speech-to-text/jobs' => 'write',
            '/api/v3/module-local-speech-to-text/transcripts' => 'read',
            '/api/v3/module-local-speech-to-text/transcripts/events' => 'read',
        ];

        foreach (STTSetting::workerApiKeyIds() as $apiKeyId) {
            $apiKey = ApiKeys::findFirstById($apiKeyId);
            if (!$apiKey instanceof ApiKeys || (string)$apiKey->full_permissions === '1') {
                continue;
            }

            $allowedPaths = json_decode((string)$apiKey->allowed_paths, true);
            if (!is_array($allowedPaths)) {
                $allowedPaths = [];
            }

            $apiKeyChanged = false;
            foreach ($requiredPaths as $path => $permission) {
                if (($allowedPaths[$path] ?? null) !== $permission) {
                    $allowedPaths[$path] = $permission;
                    $apiKeyChanged = true;
                }
            }

            if ($apiKeyChanged) {
                $apiKey->allowed_paths = json_encode($allowedPaths) ?: '{}';
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
