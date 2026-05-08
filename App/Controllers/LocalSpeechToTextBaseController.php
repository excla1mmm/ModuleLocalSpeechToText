<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Controllers;

use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleLocalSpeechToText\App\Forms\ModuleLocalSpeechToTextForm;
use Modules\ModuleLocalSpeechToText\App\Providers\AssetProvider;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
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
        STTSetting::setValue(
            STTSetting::PROCESSING_TIMEOUT_SEC,
            (string)$this->postInt(STTSetting::PROCESSING_TIMEOUT_SEC, 1800, 60, 86400)
        );
        STTSetting::setValue(
            STTSetting::SELECTED_MODEL,
            STTSetting::normalizeModelId($this->postString(STTSetting::SELECTED_MODEL, STTSetting::DEFAULT_MODEL, 64))
        );
        $selectedDictionaries = $this->request->getPost(STTSetting::SELECTED_VOCABULARY_DICTIONARIES);
        STTSetting::setSelectedVocabularyDictionaryIds(is_array($selectedDictionaries) ? $selectedDictionaries : []);
        $customTerms = trim((string)($this->request->getPost(STTSetting::CUSTOM_VOCABULARY_TERMS) ?? ''));
        STTSetting::setValue(
            STTSetting::CUSTOM_VOCABULARY_TERMS,
            function_exists('mb_substr') ? mb_substr($customTerms, 0, 8000) : substr($customTerms, 0, 8000)
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
        $settings->processing_timeout_sec = STTSetting::getValue(STTSetting::PROCESSING_TIMEOUT_SEC, '1800');
        $settings->selected_model = STTSetting::selectedModelId();
        $settings->custom_vocabulary_terms = STTSetting::customVocabularyTermsText();

        return $settings;
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
