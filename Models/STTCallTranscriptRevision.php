<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class STTCallTranscriptRevision extends ModulesModelsBase
{
    public $id;
    public ?int $call_transcript_id = null;
    public ?string $revision = '0';
    public ?string $content_hash = '';
    public ?string $parts_hash = '';
    public ?string $completeness_status = 'complete';
    public ?string $payload = '';
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptRevision');
        parent::initialize();
    }
}
