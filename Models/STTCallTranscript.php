<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * @Indexes(
 *     [name='call_id', columns=['call_id'], type='UNIQUE'],
 *     [name='publish', columns=['stable_after', 'pending_content_hash'], type='']
 * )
 */
class STTCallTranscript extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /** @Column(type="string", nullable=false) */
    public ?string $call_id = '';

    /** @Column(type="integer", nullable=false, default=0) */
    public ?string $current_revision = '0';

    /** @Column(type="string", nullable=false, allow_empty_string=true, default="") */
    public ?string $current_content_hash = '';

    /** @Column(type="string", nullable=false, allow_empty_string=true, default="") */
    public ?string $current_parts_hash = '';

    /** @Column(type="string", nullable=false, allow_empty_string=true, default="") */
    public ?string $pending_content_hash = '';

    /** @Column(type="string", nullable=false, allow_empty_string=true, default="") */
    public ?string $pending_parts_hash = '';

    /** @Column(type="string", nullable=false, allow_empty_string=true, default="") */
    public ?string $pending_payload = '';

    /** @Column(type="string", nullable=false, default="pending") */
    public ?string $completeness_status = 'pending';

    /** @Column(type="integer", nullable=false, default=0) */
    public ?string $stable_after = '0';

    /** @Column(type="integer", nullable=false, default=0) */
    public ?string $published_at = '0';

    /** @Column(type="integer", nullable=false) */
    public ?string $created_at = '0';

    /** @Column(type="integer", nullable=false) */
    public ?string $updated_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTCallTranscript');
        parent::initialize();

        // These hash/payload columns are documented above as allow_empty_string
        // because a call transcript legitimately starts (and can stay, for a
        // short call whose only part has zero segments) with empty pending/
        // current hashes and payload before anything has been staged or
        // published. Without this, Phalcon's automatic not-null validation
        // would reject the empty-string default on save().
        $this->allowEmptyStringValues([
            'current_content_hash',
            'current_parts_hash',
            'pending_content_hash',
            'pending_parts_hash',
            'pending_payload',
        ]);
    }
}
