<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class STTCallTranscriptEvent extends ModulesModelsBase
{
    public const string TYPE_COMPLETED = 'call-transcript.completed';
    public const string TYPE_UPDATED = 'call-transcript.updated';

    public $id;
    public ?string $event_type = self::TYPE_COMPLETED;
    public ?string $event_key = '';
    public ?int $call_transcript_id = null;
    public ?string $call_id = '';
    public ?string $revision = '0';
    public ?string $content_hash = '';
    public ?string $parts_hash = '';
    public ?string $completeness_status = 'complete';
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptEvent');
        parent::initialize();
    }
}
