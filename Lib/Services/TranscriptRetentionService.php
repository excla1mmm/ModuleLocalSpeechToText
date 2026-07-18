<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTResult;
use PDO;
use RuntimeException;

final class TranscriptRetentionService
{
    private readonly mixed $connection;
    private readonly ModuleTransaction $transaction;

    public function __construct(mixed $connection = null, ?ModuleTransaction $transaction = null)
    {
        $this->connection = $connection ?? (new STTResult())->getWriteConnection();
        $this->transaction = $transaction ?? new ModuleTransaction();
    }

    /**
     * @return array{calls: int, results: int, has_more: bool, cutoff: int}
     */
    public function purgeExpired(int $retentionDays, int $now, int $batchSize = 100): array
    {
        if ($retentionDays === 0) {
            return ['calls' => 0, 'results' => 0, 'has_more' => false, 'cutoff' => 0];
        }

        $cutoff = $now - (max(1, $retentionDays) * 86400);
        $batchSize = max(1, min(1000, $batchSize));

        return $this->transaction->run(
            $this->connection,
            function (mixed $connection) use ($cutoff, $batchSize): array {
                $candidates = $connection->fetchAll(
                    "SELECT CASE
                                WHEN TRIM(COALESCE(j.call_id, '')) = ''
                                    THEN 'result:' || CAST(r.id AS TEXT)
                                ELSE 'call:' || j.call_id
                            END AS retention_key,
                            MAX(CAST(r.created_at AS INTEGER)) AS processed_at
                       FROM m_STTResult r
                  LEFT JOIN m_STTJob j ON j.id = r.job_id
                   GROUP BY retention_key
                     HAVING MAX(CAST(r.created_at AS INTEGER)) < CAST(:cutoff AS INTEGER)
                   ORDER BY processed_at ASC, retention_key ASC
                      LIMIT " . ($batchSize + 1),
                    PDO::FETCH_ASSOC,
                    ['cutoff' => $cutoff]
                );
                $hasMore = count($candidates) > $batchSize;
                $candidates = array_slice($candidates, 0, $batchSize);
                if ($candidates === []) {
                    return ['calls' => 0, 'results' => 0, 'has_more' => false, 'cutoff' => $cutoff];
                }

                $callIds = [];
                $resultIds = [];
                foreach ($candidates as $candidate) {
                    $key = (string)($candidate['retention_key'] ?? '');
                    if (str_starts_with($key, 'call:')) {
                        $callIds[] = substr($key, strlen('call:'));
                    } elseif (str_starts_with($key, 'result:')) {
                        $resultId = (int)substr($key, strlen('result:'));
                        if ($resultId > 0) {
                            $resultIds[] = $resultId;
                        }
                    }
                }
                $callIds = array_values(array_unique(array_filter(
                    $callIds,
                    static fn (string $callId): bool => $callId !== ''
                )));
                if ($callIds !== []) {
                    [$callPlaceholders, $callBind] = $this->boundList('call', $callIds);
                    foreach ($connection->fetchAll(
                        "SELECT r.id
                           FROM m_STTResult r
                           JOIN m_STTJob j ON j.id = r.job_id
                          WHERE j.call_id IN ({$callPlaceholders})",
                        PDO::FETCH_ASSOC,
                        $callBind
                    ) as $row) {
                        $resultId = (int)($row['id'] ?? 0);
                        if ($resultId > 0) {
                            $resultIds[] = $resultId;
                        }
                    }
                }
                $resultIds = array_values(array_unique($resultIds));

                $callTranscriptIds = [];
                if ($callIds !== []) {
                    [$callPlaceholders, $callBind] = $this->boundList('aggregate_call', $callIds);
                    foreach ($connection->fetchAll(
                        "SELECT id FROM m_STTCallTranscript WHERE call_id IN ({$callPlaceholders})",
                        PDO::FETCH_ASSOC,
                        $callBind
                    ) as $row) {
                        $callTranscriptId = (int)($row['id'] ?? 0);
                        if ($callTranscriptId > 0) {
                            $callTranscriptIds[] = $callTranscriptId;
                        }
                    }
                }

                $this->deleteByIds($connection, 'm_STTCallTranscriptEvent', 'call_transcript_id', $callTranscriptIds, 'call_event');
                $this->deleteByIds($connection, 'm_STTCallTranscriptRevision', 'call_transcript_id', $callTranscriptIds, 'call_revision');
                $this->deleteByIds($connection, 'm_STTCallTranscriptPart', 'call_transcript_id', $callTranscriptIds, 'call_part');
                $this->deleteByIds($connection, 'm_STTCallTranscript', 'id', $callTranscriptIds, 'call_transcript');
                $this->deleteByIds($connection, 'm_STTTranscriptEvent', 'result_id', $resultIds, 'result_event');
                $this->deleteByIds($connection, 'm_STTResult', 'id', $resultIds, 'result');

                return [
                    'calls' => count($candidates),
                    'results' => count($resultIds),
                    'has_more' => $hasMore,
                    'cutoff' => $cutoff,
                ];
            }
        );
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteByIds(
        mixed $connection,
        string $table,
        string $column,
        array $ids,
        string $bindPrefix
    ): void {
        if ($ids === []) {
            return;
        }
        [$placeholders, $bind] = $this->boundList($bindPrefix, $ids);
        if ($connection->execute("DELETE FROM {$table} WHERE {$column} IN ({$placeholders})", $bind) !== true) {
            throw new RuntimeException('Unable to delete expired transcript rows from ' . $table);
        }
    }

    /**
     * @param array<int, int|string> $values
     * @return array{0: string, 1: array<string, int|string>}
     */
    private function boundList(string $prefix, array $values): array
    {
        $placeholders = [];
        $bind = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . '_' . $index;
            $placeholders[] = ':' . $key;
            $bind[$key] = $value;
        }

        return [implode(', ', $placeholders), $bind];
    }
}
