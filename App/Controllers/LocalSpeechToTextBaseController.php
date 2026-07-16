<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleLocalSpeechToText\App\Forms\ModuleLocalSpeechToTextForm;
use Modules\ModuleLocalSpeechToText\App\Providers\AssetProvider;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGuard;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use RuntimeException;
use stdClass;

class LocalSpeechToTextBaseController extends BaseController
{
    protected const string MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText';

    protected string $moduleDir;

    public function initialize(): void
    {
        $this->moduleDir = PbxExtensionUtils::getModuleDir(self::MODULE_UNIQUE_ID);
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

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    protected function withModuleWriteLease(callable $operation): mixed
    {
        $guard = new ModuleLifecycleGuard($this->moduleDir . '/db');
        $writeLease = $guard->acquireWriteLease();
        if ($writeLease === null) {
            throw new RuntimeException('Local speech-to-text module is changing state');
        }

        try {
            return $operation();
        } finally {
            $guard->releaseWriteLease($writeLease);
        }
    }

    protected function buildSettingsForm(): ModuleLocalSpeechToTextForm
    {
        return new ModuleLocalSpeechToTextForm($this->settingsEntity());
    }

    protected function saveSettingsFromPost(): void
    {
        STTSetting::setValue(
            STTSetting::DEFAULT_LANGUAGE,
            STTSetting::normalizeLanguageId($this->postString(STTSetting::DEFAULT_LANGUAGE, STTSetting::AUTO_LANGUAGE, 16))
        );
        STTSetting::setValue(
            STTSetting::POLL_INTERVAL_SEC,
            (string)$this->postInt(STTSetting::POLL_INTERVAL_SEC, 30, 30, 3600)
        );
        STTSetting::setValue(
            STTSetting::MAX_FILE_SIZE_MB,
            (string)$this->postInt(STTSetting::MAX_FILE_SIZE_MB, 500, 1, 10240)
        );
        STTSetting::setValue(
            STTSetting::CDR_SCAN_LIMIT,
            (string)$this->postInt(STTSetting::CDR_SCAN_LIMIT, 200, 1, 1000)
        );
        $previousHistoryDays = STTSetting::cdrHistoryDays();
        $nextHistoryDays = STTSetting::normalizeCdrHistoryDays(
            (int)$this->request->getPost(STTSetting::CDR_HISTORY_DAYS, 'int', 30)
        );
        STTSetting::setValue(STTSetting::CDR_HISTORY_DAYS, (string)$nextHistoryDays);
        if ($previousHistoryDays !== $nextHistoryDays) {
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ENDTIME, date('Y-m-d H:i:s'));
            STTSetting::setValue(STTSetting::CDR_SCAN_CURSOR_ID, '0');
            STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ENDTIME, '');
            STTSetting::setValue(STTSetting::CDR_BACKFILL_CURSOR_ID, '0');
        }
        STTSetting::setValue(
            STTSetting::PROCESSING_TIMEOUT_SEC,
            (string)$this->postInt(STTSetting::PROCESSING_TIMEOUT_SEC, 1800, 60, 86400)
        );
        if ($this->request->hasPost(STTSetting::SELECTED_MODEL)) {
            STTSetting::setValue(
                STTSetting::SELECTED_MODEL,
                STTSetting::normalizeModelId($this->postString(STTSetting::SELECTED_MODEL, STTSetting::selectedModelId(), 255))
            );
        }
        $customTerms = $this->vocabularyTermsTextFromRequest();
        STTSetting::setValue(
            STTSetting::CUSTOM_VOCABULARY_TERMS,
            function_exists('mb_substr') ? mb_substr($customTerms, 0, 8000) : substr($customTerms, 0, 8000)
        );
        LocalSpeechToTextLogger::write(
            'web-ui',
            sprintf(
                'Settings saved: language=%s model=%s history_days=%d vocabulary_terms=%d',
                STTSetting::selectedLanguageId(),
                STTSetting::selectedModelId(),
                $nextHistoryDays,
                count(STTSetting::selectedVocabularyTerms())
            ),
            LOG_NOTICE
        );
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
        $settings->max_file_size_mb = STTSetting::getValue(STTSetting::MAX_FILE_SIZE_MB, '500');
        $settings->cdr_scan_limit = STTSetting::getValue(STTSetting::CDR_SCAN_LIMIT, '200');
        $settings->cdr_history_days = (string)STTSetting::cdrHistoryDays();
        $settings->processing_timeout_sec = STTSetting::getValue(STTSetting::PROCESSING_TIMEOUT_SEC, '1800');
        $settings->selected_model = STTSetting::selectedModelId();
        $settings->custom_vocabulary_terms = STTSetting::customVocabularyTermsText();

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
