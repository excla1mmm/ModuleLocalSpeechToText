<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CallTranscriptNotFoundException;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;
use Phalcon\Db\Enum;

require_once __DIR__ . '/CallTranscriptTurnProjector.php';
require_once __DIR__ . '/CallTranscriptTimelineProjector.php';
require_once __DIR__ . '/TranscriptPublicProjection.php';
require_once __DIR__ . '/SttSourceInstance.php';

final class CallTranscriptIntegrationService
{
    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function callTranscriptEvents(array $filters): array
    {
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        [$createdAt, $eventId] = $this->decodeCursor((string)($filters['cursor'] ?? '0:0'));
        $rows = (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT event.id, event.event_type, event.call_transcript_id, event.revision, event.created_at,
                    aggregate.call_id, revision.content_hash, revision.parts_hash,
                    revision.completeness_status
               FROM m_STTCallTranscriptEvent event
               JOIN m_STTCallTranscript aggregate ON aggregate.id = event.call_transcript_id
               JOIN m_STTCallTranscriptRevision revision
                 ON revision.call_transcript_id = event.call_transcript_id
                AND revision.revision = event.revision
              WHERE CAST(event.created_at AS INTEGER) > :created_at
                 OR (CAST(event.created_at AS INTEGER) = :created_at AND CAST(event.id AS INTEGER) > :event_id)
              ORDER BY CAST(event.created_at AS INTEGER) ASC, CAST(event.id AS INTEGER) ASC
              LIMIT ' . $limit,
            Enum::FETCH_ASSOC,
            ['created_at' => $createdAt, 'event_id' => $eventId]
        );

        $events = [];
        $nextCursor = $this->encodeCursor($createdAt, $eventId);
        foreach ($rows as $row) {
            $rowCreatedAt = (int)($row['created_at'] ?? 0);
            $rowId = (int)($row['id'] ?? 0);
            $event = [
                'cursor' => $this->encodeCursor($rowCreatedAt, $rowId),
                'event_type' => (string)($row['event_type'] ?? ''),
                'call_id' => trim((string)($row['call_id'] ?? '')),
                'aggregate_id' => (int)($row['call_transcript_id'] ?? 0),
                'revision' => (int)($row['revision'] ?? 0),
                'content_hash' => trim((string)($row['content_hash'] ?? '')),
                'parts_hash' => trim((string)($row['parts_hash'] ?? '')),
                'completeness_status' => trim((string)($row['completeness_status'] ?? '')),
            ];
            if (!$this->validEvent($event)) {
                if (class_exists(LocalSpeechToTextLogger::class)) {
                    LocalSpeechToTextLogger::event(
                        'transcript_import',
                        'contract_event_rejected',
                        'Stored transcript event identity is invalid',
                        LOG_ERR,
                        ['event_id' => $rowId, 'aggregate_id' => $event['aggregate_id']]
                    );
                }
                break;
            }
            $events[] = $event;
            $nextCursor = $this->encodeCursor($rowCreatedAt, $rowId);
        }

        return [
            'source_instance_id' => (new SttSourceInstance())->current(),
            'events' => $events,
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return array<string, mixed> */
    public function callTranscriptDetail(int $aggregateId, ?int $revision = null): array
    {
        $call = STTCallTranscript::findFirstById($aggregateId);
        if (!$call instanceof STTCallTranscript) {
            throw new CallTranscriptNotFoundException('Call transcript aggregate not found');
        }
        $revision ??= (int)$call->current_revision;
        if ($revision <= 0) {
            throw new CallTranscriptNotFoundException('Call transcript revision not found');
        }

        $stored = STTCallTranscriptRevision::findFirst([
            'conditions' => 'call_transcript_id = :aggregate_id: AND revision = :revision:',
            'bind' => ['aggregate_id' => $aggregateId, 'revision' => $revision],
        ]);
        if (!$stored instanceof STTCallTranscriptRevision) {
            throw new CallTranscriptNotFoundException('Call transcript revision not found');
        }
        $payload = json_decode((string)$stored->payload, true);
        $payload = is_array($payload) ? $payload : [];
        if (is_array($payload['transcript'] ?? null)) {
            $manifest = is_array($payload['part_manifest'] ?? null) ? $payload['part_manifest'] : [];
            $payload['transcript'] = (new CallTranscriptTurnProjector())->project($payload['transcript']);
            $payload['transcript'] = (new CallTranscriptTimelineProjector())->project($payload['transcript'], $manifest);
            $payload['transcript'] = (new TranscriptPublicProjection())->project($payload['transcript']);
        }

        return [
            'source_instance_id' => (new SttSourceInstance())->current(),
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

    /** @param array<string, mixed> $event */
    private function validEvent(array $event): bool
    {
        return in_array($event['event_type'] ?? '', ['call-transcript.completed', 'call-transcript.updated'], true)
            && is_string($event['call_id'] ?? null)
            && $event['call_id'] !== ''
            && strlen($event['call_id']) <= 255
            && (int)($event['aggregate_id'] ?? 0) > 0
            && (int)($event['revision'] ?? 0) > 0
            && preg_match('/\A[a-f0-9]{64}\z/', (string)($event['content_hash'] ?? '')) === 1
            && preg_match('/\A[a-f0-9]{64}\z/', (string)($event['parts_hash'] ?? '')) === 1
            && in_array($event['completeness_status'] ?? '', ['complete', 'legacy_unresolved'], true);
    }
}
