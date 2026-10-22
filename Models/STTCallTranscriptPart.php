<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * @Indexes(
 *     [name='version', columns=['call_transcript_id', 'result_id', 'content_hash', 'recording_fingerprint'], type='UNIQUE'],
 *     [name='result', columns=['result_id'], type=''],
 *     [name='cdr_uniqueid', columns=['cdr_uniqueid'], type='']
 * )
 */
class STTCallTranscriptPart extends ModulesModelsBase
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
    public ?int $result_id = null;

    /** @Column(type="integer", nullable=true) */
    public ?int $job_id = null;

    /** @Column(type="integer", nullable=true) */
    public ?int $cdr_id = null;

    /** @Column(type="string", nullable=true) */
    public ?string $cdr_uniqueid = '';

    /** @Column(type="integer", nullable=true) */
    public ?string $cdr_start_ms = null;

    /** @Column(type="integer", nullable=true) */
    public ?string $cdr_end_ms = null;

    /** @Column(type="string", nullable=false) */
    public ?string $recording_fingerprint = '';

    /** @Column(type="string", nullable=false) */
    public ?string $content_hash = '';

    /** @Column(type="string", nullable=false) */
    public ?string $source_label = '';

    /** @Column(type="string", nullable=false, default="complete") */
    public ?string $resolution_status = 'complete';

    /** @Column(type="string", nullable=false, default="[]") */
    public ?string $segments_json = '[]';

    /** @Column(type="integer", nullable=false, default=1) */
    public ?string $is_current = '1';

    /** @Column(type="integer", nullable=true) */
    public ?int $superseded_by_part_id = null;

    /** @Column(type="integer", nullable=false) */
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptPart');
        parent::initialize();
    }
}
