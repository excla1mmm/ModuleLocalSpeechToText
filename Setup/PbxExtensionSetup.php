<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
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
            $this->setDefaultSetting(STTSetting::POLL_INTERVAL_SEC, '5');
            $this->setDefaultSetting(STTSetting::MAX_FILE_SIZE_MB, '500');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_LIMIT, '200');
            $this->setDefaultSetting(STTSetting::PROCESSING_TIMEOUT_SEC, '1800');
            $this->setDefaultSetting(STTSetting::SELECTED_MODEL, STTSetting::DEFAULT_MODEL);
            $this->setDefaultSetting(STTSetting::DELAYED_RECORDINGS, '[]');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_CURSOR_ENDTIME, '');
            $this->setDefaultSetting(STTSetting::CDR_SCAN_CURSOR_ID, '0');
            $this->setDefaultSetting(STTSetting::WORKER_API_KEY_ID, '');
        } catch (Throwable $exception) {
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
}
