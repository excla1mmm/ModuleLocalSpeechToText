<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * @Indexes(
 *     [name='key', columns=['event_key'], type='UNIQUE'],
 *     [name='cursor', columns=['created_at', 'id'], type='']
 * )
 */
class STTCallTranscriptEvent extends ModulesModelsBase
{
    public const string TYPE_COMPLETED = 'call-transcript.completed';
    public const string TYPE_UPDATED = 'call-transcript.updated';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /** @Column(type="string", nullable=false) */
    public ?string $event_type = self::TYPE_COMPLETED;

    /** @Column(type="string", nullable=false) */
    public ?string $event_key = '';

    /** @Column(type="integer", nullable=false) */
    public ?int $call_transcript_id = null;

    /** @Column(type="string", nullable=false) */
    public ?string $call_id = '';

    /** @Column(type="integer", nullable=false) */
    public ?string $revision = '0';

    /** @Column(type="string", nullable=false) */
    public ?string $content_hash = '';

    /** @Column(type="string", nullable=false) */
    public ?string $parts_hash = '';

    /** @Column(type="string", nullable=false) */
    public ?string $completeness_status = 'complete';

    /** @Column(type="integer", nullable=false) */
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscriptEvent');
        parent::initialize();
    }
}
