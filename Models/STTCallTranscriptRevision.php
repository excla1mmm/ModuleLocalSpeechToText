<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

class STTCallTranscriptRevision extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /** @Column(type="integer", nullable=false) */
    public ?int $call_transcript_id = null;

    /** @Column(type="integer", nullable=false) */
    public ?string $revision = '0';

    /** @Column(type="text", nullable=false) */
    public ?string $content_hash = '';

    /** @Column(type="text", nullable=false) */
    public ?string $parts_hash = '';

    /** @Column(type="text", nullable=false) */
    public ?string $completeness_status = 'complete';

    /** @Column(type="text", nullable=false) */
    public ?string $payload = '';

    /** @Column(type="integer", nullable=false) */
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptRevision');
        parent::initialize();
    }
}
