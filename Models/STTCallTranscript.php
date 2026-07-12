<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class STTCallTranscript extends ModulesModelsBase
{
    public $id;
    public ?string $call_id = '';
    public ?string $current_revision = '0';
    public ?string $current_content_hash = '';
    public ?string $current_parts_hash = '';
    public ?string $pending_content_hash = '';
    public ?string $pending_parts_hash = '';
    public ?string $pending_payload = '';
    public ?string $completeness_status = 'pending';
    public ?string $stable_after = '0';
    public ?string $published_at = '0';
    public ?string $created_at = '0';
    public ?string $updated_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscript');
        parent::initialize();
    }
}
