<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;
use RuntimeException;

/**
 * @Indexes(
 *     [name='event_key', columns=['event_key'], type='UNIQUE'],
 *     [name='event_cursor', columns=['created_at', 'id'], type=''],
 *     [name='result_id', columns=['result_id'], type='']
 * )
 */
class STTTranscriptEvent extends ModulesModelsBase
{
    public const string TYPE_TRANSCRIPT_COMPLETED = 'transcript.completed';
    public const string UPDATE_CREATED = 'created';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $event_type = self::TYPE_TRANSCRIPT_COMPLETED;

    /**
     * @Column(type="string", nullable=false)
     */
    public ?string $event_key = '';

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?int $result_id = null;

    /**
     * @Column(type="integer", nullable=true)
     */
    public ?int $job_id = null;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $call_id = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $update_type = self::UPDATE_CREATED;

    /**
     * @Column(type="string", nullable=true)
     */
    public ?string $payload = '';

    /**
     * @Column(type="integer", nullable=false)
     */
    public ?string $created_at = '0';

    public function initialize(): void
    {
        $this->setSource('m_STTTranscriptEvent');
        parent::initialize();

        $this->belongsTo(
            'result_id',
            STTResult::class,
            'id',
            [
                'alias' => 'STTResult',
                'foreignKey' => [
                    'allowNulls' => false,
                    'action' => Relation::NO_ACTION,
                ],
            ]
        );
        $this->belongsTo(
            'job_id',
            STTJob::class,
            'id',
            [
                'alias' => 'STTJob',
                'foreignKey' => [
                    'allowNulls' => true,
                    'action' => Relation::NO_ACTION,
                ],
            ]
        );
    }

    public static function ensureCompletedForResult(STTResult $result, ?STTJob $job = null): self
    {
        $resultId = (int)$result->id;
        if ($resultId <= 0) {
            throw new RuntimeException('Transcript result id is required for event creation');
        }

        $eventKey = self::completedEventKey($resultId);
        $event = self::findFirst([
            'conditions' => 'event_key = :event_key:',
            'bind' => [
                'event_key' => $eventKey,
            ],
        ]);
        if ($event instanceof self) {
            return $event;
        }

        if (!$job instanceof STTJob) {
            $relatedJob = $result->STTJob;
            $job = $relatedJob instanceof STTJob ? $relatedJob : null;
        }

        $createdAt = (int)$result->created_at;
        if ($createdAt <= 0) {
            $createdAt = time();
        }

        $event = new self();
        $event->event_type = self::TYPE_TRANSCRIPT_COMPLETED;
        $event->event_key = $eventKey;
        $event->result_id = $resultId;
        $event->job_id = $job instanceof STTJob ? (int)$job->id : null;
        $event->call_id = $job instanceof STTJob ? (string)$job->call_id : '';
        $event->update_type = self::UPDATE_CREATED;
        $event->created_at = (string)$createdAt;
        $event->payload = json_encode([
            'result_id' => $event->result_id,
            'job_id' => $event->job_id,
            'call_id' => $event->call_id,
            'event_type' => $event->event_type,
            'update_type' => $event->update_type,
        ]) ?: '{}';

        if (!$event->save()) {
            throw new RuntimeException(implode(', ', $event->getMessages()));
        }

        return $event;
    }

    public static function backfillCompletedEvents(int $limit = 1000): int
    {
        $connection = (new STTResult())->getReadConnection();
        $rows = $connection->fetchAll(
            "SELECT r.id AS result_id
               FROM m_STTResult r
          LEFT JOIN m_STTTranscriptEvent e
                 ON e.event_key = ('transcript.completed:' || r.id)
              WHERE e.id IS NULL
           ORDER BY CAST(r.created_at AS INTEGER) ASC, CAST(r.id AS INTEGER) ASC
              LIMIT " . max(1, min(10000, $limit)),
            \Phalcon\Db\Enum::FETCH_ASSOC
        );

        $created = 0;
        foreach ($rows as $row) {
            $result = STTResult::findFirstById((int)($row['result_id'] ?? 0));
            if (!$result instanceof STTResult) {
                continue;
            }

            self::ensureCompletedForResult($result);
            $created++;
        }

        return $created;
    }

    private static function completedEventKey(int $resultId): string
    {
        return self::TYPE_TRANSCRIPT_COMPLETED . ':' . $resultId;
    }
}
