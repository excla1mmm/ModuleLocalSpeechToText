<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class STTCallTranscriptPart extends ModulesModelsBase
{
    public $id;
    public ?int $call_transcript_id = null;
    public ?int $result_id = null;
    public ?int $job_id = null;
    public ?int $cdr_id = null;
    public ?string $cdr_uniqueid = '';
    public ?string $cdr_start_ms = null;
    public ?string $cdr_end_ms = null;
    public ?string $recording_fingerprint = '';
    public ?string $content_hash = '';
    public ?string $source_label = '';
    public ?string $resolution_status = 'complete';
    public ?string $segments_json = '[]';
    public ?string $is_current = '1';
    public ?int $superseded_by_part_id = null;
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptPart');
        parent::initialize();
    }
}
