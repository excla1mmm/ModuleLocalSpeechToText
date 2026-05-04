<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\App\Forms;

use MikoPBX\AdminCabinet\Forms\BaseForm;
use Phalcon\Forms\Element\Numeric;
use Phalcon\Forms\Element\Text;
use stdClass;

class ModuleLocalSpeechToTextForm extends BaseForm
{
    public function initialize($entity = null, $options = null): void
    {
        parent::initialize($entity, $options);

        if ($entity === null) {
            $entity = new stdClass();
        }

        $this->add(new Text('default_language', [
            'value' => $entity->default_language ?? 'ru',
            'maxlength' => 16,
        ]));
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
}
