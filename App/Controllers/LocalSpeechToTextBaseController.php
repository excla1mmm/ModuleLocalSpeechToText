<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleLocalSpeechToText\App\Forms\ModuleLocalSpeechToTextForm;
use Modules\ModuleLocalSpeechToText\App\Providers\AssetProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
use Modules\ModuleLocalSpeechToText\Lib\Services\WorkerProcessingSettingsService;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use InvalidArgumentException;
use stdClass;

class LocalSpeechToTextBaseController extends BaseController
{
    protected const string MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText';

    protected string $moduleDir;

    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir(self::MODULE_UNIQUE_ID);
        (new ModuleDatabasePragmas())->configureRuntime((new STTJob())->getWriteConnection());
        $this->view->logoImagePath = $this->url->get() . 'assets/img/cache/' . self::MODULE_UNIQUE_ID . '/logo.svg';
        $this->view->submitMode = null;
        parent::initialize();
    }

    protected function addModuleAssets(bool $includeJs = true): void
    {
        AssetProvider::addCss($this->assets);
        if ($includeJs) {
            AssetProvider::addJs($this->assets);
        }
    }

    protected function buildSettingsForm(): ModuleLocalSpeechToTextForm
    {
        return new ModuleLocalSpeechToTextForm($this->settingsEntity());
    }

    protected function saveSettingsFromPost(): void
    {
        $processingInput = [
            STTSetting::TEMPERATURE => $this->request->getPost(STTSetting::TEMPERATURE),
            STTSetting::TEMPERATURE_FALLBACK_STEP => $this->request->getPost(STTSetting::TEMPERATURE_FALLBACK_STEP),
            STTSetting::TEMPERATURE_FALLBACK_COUNT => $this->request->getPost(STTSetting::TEMPERATURE_FALLBACK_COUNT),
            STTSetting::TOP_K => $this->request->getPost(STTSetting::TOP_K),
            STTSetting::COMPRESSION_RATIO_THRESHOLD => $this->request->getPost(STTSetting::COMPRESSION_RATIO_THRESHOLD),
            STTSetting::LOG_PROBABILITY_THRESHOLD => $this->request->getPost(STTSetting::LOG_PROBABILITY_THRESHOLD),
            STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD => $this->request->getPost(STTSetting::FIRST_TOKEN_LOG_PROBABILITY_THRESHOLD),
            STTSetting::NO_SPEECH_THRESHOLD => $this->request->getPost(STTSetting::NO_SPEECH_THRESHOLD),
            STTSetting::AUDIO_NORMALIZATION => $this->request->getPost(STTSetting::AUDIO_NORMALIZATION, null, false),
            STTSetting::VOICE_ACTIVITY_DETECTION => $this->request->getPost(STTSetting::VOICE_ACTIVITY_DETECTION, null, false),
            STTSetting::MAX_SEGMENT_DURATION_MS => (int)round(
                (float)$this->request->getPost('max_segment_duration_sec') * 1000
            ),
            STTSetting::SEGMENT_OVERLAP_MS => (int)round(
                (float)$this->request->getPost('segment_overlap_sec') * 1000
            ),
        ];
        $normalizedProcessing = WorkerProcessingSettingsService::normalize($processingInput);
        $previousProcessing = WorkerProcessingSettingsService::current();
        $previousHistoryDays = STTSetting::cdrHistoryDays();
        $previousRetentionDays = STTSetting::transcriptRetentionDays();
        $nextHistoryDays = STTSetting::normalizeCdrHistoryDays(
            (int)$this->request->getPost(STTSetting::CDR_HISTORY_DAYS, 'int', 30)
        );
        $nextRetentionDays = STTSetting::normalizeTranscriptRetentionDays(
            (int)$this->request->getPost(STTSetting::TRANSCRIPT_RETENTION_DAYS, 'int', 365)
        );
        if (!STTSetting::retentionAllowsProcessing($nextHistoryDays, $nextRetentionDays)) {
            throw new InvalidArgumentException(
                $this->translation->_('module_local_speech_to_text_TranscriptRetentionConflict')
            );
        }

        $customTerms = $this->vocabularyTermsTextFromRequest();
        $nextValues = [
            STTSetting::DEFAULT_LANGUAGE => STTSetting::normalizeLanguageId(
                $this->postString(STTSetting::DEFAULT_LANGUAGE, STTSetting::AUTO_LANGUAGE, 16)
            ),
            STTSetting::POLL_INTERVAL_SEC => (string)$this->postInt(
                STTSetting::POLL_INTERVAL_SEC,
                30,
                30,
                3600
            ),
            STTSetting::MAX_RECORDING_DURATION_MIN => (string)STTSetting::normalizeRecordingDurationMinutes(
                $this->postInt(STTSetting::MAX_RECORDING_DURATION_MIN, 60, 1, 1440)
            ),
            STTSetting::CDR_SCAN_LIMIT => (string)$this->postInt(
                STTSetting::CDR_SCAN_LIMIT,
                STTSetting::DEFAULT_CDR_SCAN_LIMIT,
                1,
                1000
            ),
            STTSetting::CDR_HISTORY_DAYS => (string)$nextHistoryDays,
            STTSetting::TRANSCRIPT_RETENTION_DAYS => (string)$nextRetentionDays,
            STTSetting::PROCESSING_TIMEOUT_SEC => (string)$this->postInt(
                STTSetting::PROCESSING_TIMEOUT_SEC,
                1800,
                60,
                86400
            ),
            STTSetting::SELECTED_MODEL => $this->request->hasPost(STTSetting::SELECTED_MODEL)
                ? STTSetting::normalizeModelId(
                    $this->postString(STTSetting::SELECTED_MODEL, STTSetting::selectedModelId(), 255)
                )
                : STTSetting::selectedModelId(),
            STTSetting::CUSTOM_VOCABULARY_TERMS => function_exists('mb_substr')
                ? mb_substr($customTerms, 0, 8000)
                : substr($customTerms, 0, 8000),
        ];
        $previousValues = [
            STTSetting::DEFAULT_LANGUAGE => STTSetting::selectedLanguageId(),
            STTSetting::POLL_INTERVAL_SEC => STTSetting::getValue(STTSetting::POLL_INTERVAL_SEC, '30'),
            STTSetting::MAX_RECORDING_DURATION_MIN => (string)STTSetting::maxRecordingDurationMinutes(),
            STTSetting::CDR_SCAN_LIMIT => (string)STTSetting::cdrScanLimit(),
            STTSetting::CDR_HISTORY_DAYS => (string)$previousHistoryDays,
            STTSetting::TRANSCRIPT_RETENTION_DAYS => (string)$previousRetentionDays,
            STTSetting::PROCESSING_TIMEOUT_SEC => STTSetting::getValue(STTSetting::PROCESSING_TIMEOUT_SEC, '1800'),
            STTSetting::SELECTED_MODEL => STTSetting::selectedModelId(),
            STTSetting::CUSTOM_VOCABULARY_TERMS => STTSetting::customVocabularyTermsText(),
        ];

        foreach ($nextValues as $key => $value) {
            STTSetting::setValue($key, (string)$value);
        }
        if ($previousHistoryDays !== $nextHistoryDays) {
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, date('Y-m-d H:i:s'));
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ID, '0');
            STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, '');
            STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ID, '0');
        }
        if ($previousRetentionDays !== $nextRetentionDays) {
            STTSetting::setValue(STTSetting::TRANSCRIPT_RETENTION_LAST_CLEANUP_AT, '0');
        }
        (new WorkerProcessingSettingsService())->save($normalizedProcessing);

        $changedFields = [];
        foreach ($nextValues as $key => $value) {
            if ((string)($previousValues[$key] ?? '') !== (string)$value) {
                $changedFields[] = $key;
            }
        }
        foreach ($normalizedProcessing as $key => $value) {
            if (($previousProcessing[$key] ?? null) !== $value) {
                $changedFields[] = $key;
            }
        }
        $changedFields = array_values(array_unique($changedFields));
        sort($changedFields);
        if (in_array(STTSetting::SELECTED_MODEL, $changedFields, true)) {
            WorkerProcessingSettingsService::markAllWorkersChanged();
        }
        if ($changedFields !== []) {
            LocalSpeechToTextLogger::event(
                'settings',
                'settings_changed',
                'STT module settings changed',
                LOG_NOTICE,
                ['changed_fields' => implode(',', $changedFields)]
            );
        }
    }

    protected function countJobsByStatus(string $status): int
    {
        return (int)STTJob::count([
            'conditions' => 'status = :status:',
            'bind' => [
                'status' => $status,
            ],
        ]);
    }

    private function settingsEntity(): stdClass
    {
        $settings = new stdClass();
        $settings->default_language = STTSetting::selectedLanguageId();
        $settings->poll_interval_sec = STTSetting::getValue(STTSetting::POLL_INTERVAL_SEC, '30');
        $settings->max_recording_duration_min = (string)STTSetting::maxRecordingDurationMinutes();
        $settings->cdr_scan_limit = (string)STTSetting::cdrScanLimit();
        $settings->cdr_history_days = (string)STTSetting::cdrHistoryDays();
        $settings->transcript_retention_days = (string)STTSetting::transcriptRetentionDays();
        $settings->processing_timeout_sec = STTSetting::getValue(STTSetting::PROCESSING_TIMEOUT_SEC, '1800');
        $settings->selected_model = STTSetting::selectedModelId();
        $settings->custom_vocabulary_terms = STTSetting::customVocabularyTermsText();
        $processing = WorkerProcessingSettingsService::current();
        foreach ($processing as $key => $value) {
            $settings->{$key} = $value;
        }
        $settings->max_segment_duration_sec = $processing[STTSetting::MAX_SEGMENT_DURATION_MS] / 1000;
        $settings->segment_overlap_sec = $processing[STTSetting::SEGMENT_OVERLAP_MS] / 1000;

        return $settings;
    }

    private function vocabularyTermsTextFromRequest(): string
    {
        $termsText = trim((string)($this->request->getPost(STTSetting::CUSTOM_VOCABULARY_TERMS) ?? ''));
        $uploadedTermsText = $this->uploadedVocabularyTermsText();
        if ($uploadedTermsText === '') {
            return $termsText;
        }

        $terms = array_merge(
            STTSetting::splitVocabularyTerms($termsText),
            STTSetting::splitVocabularyTerms($uploadedTermsText)
        );

        $deduped = [];
        $seen = [];
        foreach ($terms as $term) {
            $term = trim((string)$term);
            if ($term === '') {
                continue;
            }

            $key = function_exists('mb_strtolower') ? mb_strtolower($term) : strtolower($term);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $term;
        }

        return implode(', ', array_slice($deduped, 0, 100));
    }

    private function uploadedVocabularyTermsText(): string
    {
        if ((int)$this->request->hasFiles() <= 0) {
            return '';
        }

        foreach ($this->request->getUploadedFiles() as $file) {
            if ((int)$file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $fileName = strtolower((string)$file->getName());
            if (!str_ends_with($fileName, '.txt')) {
                continue;
            }

            $filePath = (string)$file->getTempName();
            if ($filePath === '' || !is_readable($filePath)) {
                continue;
            }

            $contents = file_get_contents($filePath, false, null, 0, 8000);
            if ($contents === false) {
                continue;
            }

            return trim(preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents);
        }

        return '';
    }

    private function postString(string $name, string $default, int $maxLength): string
    {
        $value = trim((string)$this->request->getPost($name, 'string', $default));
        if ($value === '') {
            return $default;
        }

        return substr($value, 0, $maxLength);
    }

    private function postInt(string $name, int $default, int $min, int $max): int
    {
        $value = (int)$this->request->getPost($name, 'int', $default);

        return max($min, min($max, $value));
    }
}
