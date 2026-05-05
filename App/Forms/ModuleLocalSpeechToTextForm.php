<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Forms;

use MikoPBX\AdminCabinet\Forms\BaseForm;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Select;
use stdClass;

class ModuleLocalSpeechToTextForm extends BaseForm
{
    public function initialize($entity = null, $options = null): void
    {
        parent::initialize($entity, $options);

        if ($entity === null) {
            $entity = new stdClass();
        }

        $languageSelect = new Select(
            'default_language',
            $this->languageOptions(),
            [
                'class' => 'ui fluid dropdown',
                'useEmpty' => false,
            ]
        );
        $languageSelect->setDefault($entity->default_language ?? STTSetting::AUTO_LANGUAGE);
        $this->add($languageSelect);
        $this->add(new Numeric('poll_interval_sec', [
            'value' => $entity->poll_interval_sec ?? '5',
            'min' => 1,
            'max' => 3600,
        ]));
        $this->add(new Numeric('max_file_size_mb', [
            'value' => $entity->max_file_size_mb ?? '500',
            'min' => 1,
            'max' => 10240,
        ]));
        $this->add(new Numeric('cdr_scan_limit', [
            'value' => $entity->cdr_scan_limit ?? '200',
            'min' => 1,
            'max' => 1000,
        ]));
        $this->add(new Numeric('processing_timeout_sec', [
            'value' => $entity->processing_timeout_sec ?? '1800',
            'min' => 60,
            'max' => 86400,
        ]));
    }

    private function languageOptions(): array
    {
        $options = [];
        foreach (STTSetting::LANGUAGE_PRESETS as $languageId => $label) {
            $options[$languageId] = $this->translation->_($label);
        }

        return $options;
    }
}
