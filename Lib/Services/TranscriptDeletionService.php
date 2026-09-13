<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Lib\Exceptions\TranscriptDeletionBlockedException;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use PDO;
use RuntimeException;

final class TranscriptDeletionService
{
    private const string JOB_PENDING = 'pending';
    private const string JOB_PROCESSING = 'processing';

    private readonly mixed $connection;
    private readonly ModuleTransaction $transaction;

    public function __construct(mixed $connection = null, ?ModuleTransaction $transaction = null)
    {
        $this->connection = $connection ?? (new STTResult())->getWriteConnection();
        $this->transaction = $transaction ?? new ModuleTransaction();
    }

    /** @return array{call_id: string, results: int} */
    public function deleteByResultId(int $resultId): array
    {
        if ($resultId < 1) {
            throw new RuntimeException('Transcript was not found');
        }

        return $this->transaction->run($this->connection, function (mixed $connection) use ($resultId): array {
            $row = $connection->fetchOne(
                'SELECT j.call_id FROM m_STTResult r JOIN m_STTJob j ON j.id = r.job_id WHERE r.id = :id LIMIT 1',
                PDO::FETCH_ASSOC,
                ['id' => $resultId]
            );
            if (!is_array($row)) {
                throw new RuntimeException('Transcript was not found');
            }

            $callId = trim((string)($row['call_id'] ?? ''));
            $resultIds = [$resultId];
            $callTranscriptIds = [];
            if ($callId !== '') {
                if ($connection->execute(
                    'UPDATE m_STTJob SET updated_at = updated_at WHERE call_id = :call_id',
                    ['call_id' => $callId]
                ) !== true) {
                    throw new RuntimeException('Unable to lock transcript jobs for deletion');
                }
                $activeJob = $connection->fetchOne(
                    'SELECT id FROM m_STTJob WHERE call_id = :call_id'
                    . ' AND (status = :pending OR status = :processing) LIMIT 1',
                    PDO::FETCH_ASSOC,
                    [
                        'call_id' => $callId,
                        'pending' => self::JOB_PENDING,
                        'processing' => self::JOB_PROCESSING,
                    ]
                );
                if (is_array($activeJob)) {
                    throw new TranscriptDeletionBlockedException('Transcript processing is still active');
                }
                $resultIds = array_map(
                    static fn (array $item): int => (int)$item['id'],
                    $connection->fetchAll(
                        'SELECT r.id FROM m_STTResult r JOIN m_STTJob j ON j.id = r.job_id WHERE j.call_id = :call_id',
                        PDO::FETCH_ASSOC,
                        ['call_id' => $callId]
                    )
                );
                $callTranscriptIds = array_map(
                    static fn (array $item): int => (int)$item['id'],
                    $connection->fetchAll(
                        'SELECT id FROM m_STTCallTranscript WHERE call_id = :call_id',
                        PDO::FETCH_ASSOC,
                        ['call_id' => $callId]
                    )
                );
            }

            $this->deleteByIds($connection, 'm_STTCallTranscriptEvent', 'call_transcript_id', $callTranscriptIds);
            $this->deleteByIds($connection, 'm_STTCallTranscriptRevision', 'call_transcript_id', $callTranscriptIds);
            $this->deleteByIds($connection, 'm_STTCallTranscriptPart', 'call_transcript_id', $callTranscriptIds);
            $this->deleteByIds($connection, 'm_STTCallTranscript', 'id', $callTranscriptIds);
            $this->deleteByIds($connection, 'm_STTTranscriptEvent', 'result_id', $resultIds);
            $this->deleteByIds($connection, 'm_STTResult', 'id', $resultIds);

            return ['call_id' => $callId, 'results' => count($resultIds)];
        });
    }

    /** @param array<int, int> $ids */
    private function deleteByIds(mixed $connection, string $table, string $column, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $bind = [];
        $placeholders = [];
        foreach (array_values(array_unique($ids)) as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $bind[$key] = $id;
        }
        if ($connection->execute(
            "DELETE FROM {$table} WHERE {$column} IN (" . implode(', ', $placeholders) . ')',
            $bind
        ) !== true) {
            throw new RuntimeException('Unable to delete transcript data from ' . $table);
        }
    }
}
