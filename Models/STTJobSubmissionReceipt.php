<?php
declare(strict_types=1);
namespace Modules\ModuleLocalSpeechToText\Models;
use MikoPBX\Modules\Models\ModulesModelsBase;
/** @Indexes([name='job_lease', columns=['job_id','lease_token_hash'], type='UNIQUE']) */
class STTJobSubmissionReceipt extends ModulesModelsBase
{
    /** @Primary @Identity @Column(type="integer", nullable=false) */ public $id;
    /** @Column(type="integer", nullable=false) */ public ?int $job_id = null;
    /** @Column(type="integer", nullable=false) */ public ?int $worker_id = null;
    /** @Column(type="string", nullable=false) */ public ?string $lease_token_hash = '';
    /** @Column(type="string", nullable=false) */ public ?string $operation = '';
    /** @Column(type="string", nullable=false) */ public ?string $payload_digest = '';
    /** @Column(type="string", nullable=false) */ public ?string $response_json = '';
    /** @Column(type="integer", nullable=false) */ public ?string $http_code = '200';
    /** @Column(type="integer", nullable=false) */ public ?string $created_at = '0';
    public function initialize(): void { $this->setSource('m_STTJobSubmissionReceipt'); parent::initialize(); }
}
