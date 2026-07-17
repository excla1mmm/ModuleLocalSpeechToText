<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Forms;

use MikoPBX\AdminCabinet\Forms\BaseForm;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use Phalcon\Forms\Element\Check;
use Phalcon\Forms\Element\Hidden;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Select;
use Phalcon\Forms\Element\TextArea;
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
            'value' => $entity->poll_interval_sec ?? '30',
            'min' => 30,
            'max' => 3600,
        ]));
        $this->add(new Numeric('max_recording_duration_min', [
            'value' => $entity->max_recording_duration_min ?? '60',
            'min' => 1,
            'max' => 1440,
        ]));
        $this->add(new Numeric('cdr_scan_limit', [
            'value' => $entity->cdr_scan_limit ?? '200',
            'min' => 1,
            'max' => 1000,
        ]));
        $this->add(new Hidden('cdr_history_days', [
            'value' => $entity->cdr_history_days ?? '30',
        ]));
        $this->add(new Numeric('processing_timeout_sec', [
            'value' => $entity->processing_timeout_sec ?? '1800',
            'min' => 60,
            'max' => 86400,
        ]));
        $this->add(new TextArea('custom_vocabulary_terms', [
            'value' => $entity->custom_vocabulary_terms ?? '',
            'rows' => 6,
            'placeholder' => 'MikoPBX, Asterisk, SIP, VoIP, CRM',
        ]));

        $processingNumericFields = [
            'temperature' => ['min' => 0, 'max' => 1, 'step' => 0.1],
            'temperature_fallback_step' => ['min' => 0, 'max' => 1, 'step' => 0.1],
            'temperature_fallback_count' => ['min' => 0, 'max' => 10, 'step' => 1],
            'top_k' => ['min' => 1, 'max' => 100, 'step' => 1],
            'compression_ratio_threshold' => ['min' => 0, 'max' => 10, 'step' => 0.1],
            'log_probability_threshold' => ['min' => -10, 'max' => 0, 'step' => 0.1],
            'first_token_log_probability_threshold' => ['min' => -10, 'max' => 0, 'step' => 0.1],
            'no_speech_threshold' => ['min' => 0, 'max' => 1, 'step' => 0.05],
            'max_segment_duration_sec' => ['min' => 1, 'max' => 120, 'step' => 1],
            'segment_overlap_sec' => ['min' => 0, 'max' => 10, 'step' => 0.1],
        ];
        foreach ($processingNumericFields as $field => $attributes) {
            $attributes['value'] = $entity->{$field} ?? '';
            $this->add(new Numeric($field, $attributes));
        }

        foreach (['audio_normalization', 'voice_activity_detection'] as $field) {
            $attributes = ['value' => '1'];
            if ((bool)($entity->{$field} ?? false)) {
                $attributes['checked'] = 'checked';
            }
            $this->add(new Check($field, $attributes));
        }
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
