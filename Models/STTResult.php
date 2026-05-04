<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;
use RuntimeException;

/**
 * @Indexes(
 *     [name='job_id', columns=['job_id'], type='UNIQUE']
 * )
 */
class STTResult extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?int $job_id = null;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $transcript = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $language = '';

    /**
     * @Column(type="float", nullable=true)
     */
    public ?string $duration = null;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $model_used = '';

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTResult');
        parent::initialize();

        $this->belongsTo(
            'job_id',
            STTJob::class,
            'id',
            [
                'alias' => 'STTJob',
                'foreignKey' => [
                    'allowNulls' => false,
                    'action' => Relation::ACTION_CASCADE,
                ],
            ]
        );
    }

    public static function saveForJob(
        STTJob $job,
        string $transcript,
        ?string $language,
        ?float $duration,
        ?string $modelUsed
    ): self {
        $result = self::findFirst([
            'conditions' => 'job_id = :job_id:',
            'bind' => [
                'job_id' => $job->id,
            ],
        ]);
        if ($result === null) {
            $result = new self();
            $result->job_id = (int)$job->id;
        }

        $result->transcript = $transcript;
        $result->language = $language;
        $result->duration = $duration === null ? null : (string)$duration;
        $result->model_used = $modelUsed;
        $result->created_at = (string)time();

        if (!$result->save()) {
            throw new RuntimeException(implode(', ', $result->getMessages()));
        }

        return $result;
    }
}
