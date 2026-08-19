<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;
use Phalcon\Db\Enum;
use RuntimeException;

require_once __DIR__ . '/CallTranscriptTurnProjector.php';

final class CallTranscriptIntegrationService
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function callTranscriptEvents(array $filters): array
    {
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        [$createdAt, $eventId] = $this->decodeCursor((string)($filters['cursor'] ?? '0:0'));
        $rows = (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT id, event_type, call_transcript_id, call_id, revision,
                    content_hash, parts_hash, completeness_status, created_at
               FROM m_STTCallTranscriptEvent
              WHERE CAST(created_at AS INTEGER) > :created_at
                 OR (CAST(created_at AS INTEGER) = :created_at AND CAST(id AS INTEGER) > :event_id)
              ORDER BY CAST(created_at AS INTEGER) ASC, CAST(id AS INTEGER) ASC
              LIMIT ' . $limit,
            Enum::FETCH_ASSOC,
            ['created_at' => $createdAt, 'event_id' => $eventId]
        );

        $events = [];
        $nextCursor = $this->encodeCursor($createdAt, $eventId);
        foreach ($rows as $row) {
            $rowCreatedAt = (int)($row['created_at'] ?? 0);
            $rowId = (int)($row['id'] ?? 0);
            $events[] = [
                'cursor' => $this->encodeCursor($rowCreatedAt, $rowId),
                'event_type' => (string)($row['event_type'] ?? ''),
                'aggregate_id' => (int)($row['call_transcript_id'] ?? 0),
                'call_id' => (string)($row['call_id'] ?? ''),
                'revision' => (int)($row['revision'] ?? 0),
                'content_hash' => (string)($row['content_hash'] ?? ''),
                'parts_hash' => (string)($row['parts_hash'] ?? ''),
                'completeness_status' => (string)($row['completeness_status'] ?? ''),
                'created_at' => $rowCreatedAt,
            ];
            $nextCursor = $this->encodeCursor($rowCreatedAt, $rowId);
        }

        return ['events' => $events, 'next_cursor' => $nextCursor, 'limit' => $limit];
    }

    /** @return array<string, mixed> */
    public function callTranscriptDetail(int $aggregateId, ?int $revision = null): array
    {
        $call = STTCallTranscript::findFirstById($aggregateId);
        if (!$call instanceof STTCallTranscript) {
            throw new RuntimeException('Call transcript aggregate not found');
        }
        $revision ??= (int)$call->current_revision;
        if ($revision <= 0) {
            throw new RuntimeException('Call transcript revision not found');
        }

        $stored = STTCallTranscriptRevision::findFirst([
            'conditions' => 'call_transcript_id = :aggregate_id: AND revision = :revision:',
            'bind' => ['aggregate_id' => $aggregateId, 'revision' => $revision],
        ]);
        if (!$stored instanceof STTCallTranscriptRevision) {
            throw new RuntimeException('Call transcript revision not found');
        }
        $payload = json_decode((string)$stored->payload, true);
        $payload = is_array($payload) ? $payload : [];
        if (is_array($payload['transcript'] ?? null)) {
            $payload['transcript'] = (new CallTranscriptTurnProjector())->project($payload['transcript']);
        }

        return [
            'aggregate_id' => $aggregateId,
            'call_id' => (string)$call->call_id,
            'revision' => (int)$stored->revision,
            'content_hash' => (string)$stored->content_hash,
            'parts_hash' => (string)$stored->parts_hash,
            'completeness_status' => (string)$stored->completeness_status,
            'is_current' => (int)$stored->revision === (int)$call->current_revision,
            'created_at' => (int)$stored->created_at,
        ] + $payload;
    }

    /** @return array{0: int, 1: int} */
    private function decodeCursor(string $cursor): array
    {
        if (preg_match('/^(\d+):(\d+)$/', trim($cursor), $matches) !== 1) {
            return [0, 0];
        }

        return [(int)$matches[1], (int)$matches[2]];
    }

    private function encodeCursor(int $createdAt, int $eventId): string
    {
        return max(0, $createdAt) . ':' . max(0, $eventId);
    }
}
