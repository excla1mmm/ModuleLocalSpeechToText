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
require_once __DIR__ . '/CallTranscriptDeletionEvents.php';
require_once __DIR__ . '/CallTranscriptPayloadCodec.php';
require_once __DIR__ . '/CallSummaryEvents.php';

final class CallTranscriptIntegrationService
{
    /**
     * Version of the call-transcript REST contract. 2 adds contract_version
     * itself and the opt-in include_deleted tombstone feed; 3 adds the
     * opt-in include_summaries call-summary.completed events and the
     * call-dialogs/call-summaries reads. Consumers that do not opt in
     * receive exactly the version 1 events.
     */
    public const int CONTRACT_VERSION = 3;
    private const array LIVE_EVENT_TYPES = ['call-transcript.completed', 'call-transcript.updated'];

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function callTranscriptEvents(array $filters): array
    {
        $limit = max(1, min(200, (int)($filters['limit'] ?? 100)));
        [$createdAt, $eventId] = $this->decodeCursor((string)($filters['cursor'] ?? '0:0'));
        $includeDeleted = $this->flag($filters['include_deleted'] ?? null);
        $liveTypes = $this->flag($filters['include_summaries'] ?? null)
            ? [...self::LIVE_EVENT_TYPES, CallSummaryEvents::EVENT_TYPE]
            : self::LIVE_EVENT_TYPES;
        $rows = $includeDeleted
            ? $this->eventRowsWithDeletions($createdAt, $eventId, $limit, $liveTypes)
            : $this->eventRows($createdAt, $eventId, $limit, $liveTypes);

        $events = [];
        $nextCursor = $this->encodeCursor($createdAt, $eventId);
        foreach ($rows as $row) {
            $rowCreatedAt = (int)($row['created_at'] ?? 0);
            $rowId = (int)($row['id'] ?? 0);
            if ($includeDeleted && (string)($row['event_type'] ?? '') === CallTranscriptDeletionEvents::EVENT_TYPE) {
                $event = $this->deletionEvent($row, $rowCreatedAt, $rowId);
                if ($event === null) {
                    $this->logRejectedEvent($rowId, (int)($row['call_transcript_id'] ?? 0));
                    break;
                }
                $events[] = $event;
                $nextCursor = $this->encodeCursor($rowCreatedAt, $rowId);
                continue;
            }
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
            if (!$this->validEvent($event, $liveTypes)) {
                $this->logRejectedEvent($rowId, $event['aggregate_id']);
                break;
            }
            $events[] = $event;
            $nextCursor = $this->encodeCursor($rowCreatedAt, $rowId);
        }

        return [
            'source_instance_id' => (new SttSourceInstance())->current(),
            'contract_version' => self::CONTRACT_VERSION,
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
        $payload = (new CallTranscriptPayloadCodec())->decodeOrEmpty((string)$stored->payload);
        $language = $this->callLanguage(is_array($payload['part_manifest'] ?? null) ? $payload['part_manifest'] : []);
        if (is_array($payload['transcript'] ?? null)) {
            $manifest = is_array($payload['part_manifest'] ?? null) ? $payload['part_manifest'] : [];
            $payload['transcript'] = (new CallTranscriptTurnProjector())->project($payload['transcript']);
            $payload['transcript'] = (new CallTranscriptTimelineProjector())->project($payload['transcript'], $manifest);
            $payload['transcript'] = (new TranscriptPublicProjection())->project($payload['transcript']);
        }

        return [
            'source_instance_id' => (new SttSourceInstance())->current(),
            'contract_version' => self::CONTRACT_VERSION,
            'aggregate_id' => $aggregateId,
            'call_id' => (string)$call->call_id,
            'revision' => (int)$stored->revision,
            'content_hash' => (string)$stored->content_hash,
            'parts_hash' => (string)$stored->parts_hash,
            'completeness_status' => (string)$stored->completeness_status,
            'language' => $language,
            'is_current' => (int)$stored->revision === (int)$call->current_revision,
            'created_at' => (int)$stored->created_at,
        ] + $payload;
    }

    /**
     * Language of the whole call: the one language every part's worker
     * result reports, or null when a part has none or the parts disagree
     * (a mixed-language call). Read from the part results at request time
     * instead of the stored revision payload, so adding it never changes
     * content_hash and never creates new revisions.
     *
     * @param array<int, mixed> $manifest
     */
    private function callLanguage(array $manifest): ?string
    {
        $resultIds = [];
        foreach ($this->manifestResultIds($manifest) as $resultId) {
            $resultIds[$resultId] = $resultId;
        }
        if ($resultIds === []) {
            return null;
        }

        $placeholders = [];
        $bind = [];
        foreach (array_values($resultIds) as $index => $resultId) {
            $placeholders[] = ':result_' . $index;
            $bind['result_' . $index] = $resultId;
        }
        $rows = (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT id AS result_id, language FROM m_STTResult WHERE id IN (' . implode(', ', $placeholders) . ')',
            Enum::FETCH_ASSOC,
            $bind
        );

        $languages = [];
        $seen = [];
        foreach ($rows as $row) {
            $resultId = (int)($row['result_id'] ?? 0);
            if (!isset($resultIds[$resultId])) {
                continue;
            }
            $seen[$resultId] = true;
            $language = self::primaryLanguage($row['language'] ?? null);
            if ($language === null) {
                return null;
            }
            $languages[$language] = true;
        }

        return count($seen) === count($resultIds) && count($languages) === 1
            ? (string)array_key_first($languages)
            : null;
    }

    /**
     * @param array<int, mixed> $manifest
     * @return array<int, int> part result ids in call order
     */
    public function manifestResultIds(array $manifest): array
    {
        $resultIds = [];
        foreach ($manifest as $part) {
            $resultId = is_array($part) ? (int)($part['result_id'] ?? 0) : 0;
            if ($resultId > 0 && !in_array($resultId, $resultIds, true)) {
                $resultIds[] = $resultId;
            }
        }

        return $resultIds;
    }

    /** 'ru', 'ru-RU', 'RU_ru' -> 'ru'; anything that is not a language tag -> null. */
    private static function primaryLanguage(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        if ($value === 'auto' || preg_match('/\A([a-z]{2,3})(?:[-_][a-z0-9]{2,8})*\z/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Version 1 event page: completed/updated events of live aggregates only,
     * plus call-summary.completed when the consumer opted in.
     *
     * @param array<int, string> $liveTypes
     * @return array<int, array<string, mixed>>
     */
    private function eventRows(int $createdAt, int $eventId, int $limit, array $liveTypes): array
    {
        [$typeList, $typeBind] = $this->typeList($liveTypes);

        return (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT event.id, event.event_type, event.call_transcript_id, event.revision, event.created_at,
                    aggregate.call_id, revision.content_hash, revision.parts_hash,
                    revision.completeness_status
               FROM m_STTCallTranscriptEvent event
               JOIN m_STTCallTranscript aggregate ON aggregate.id = event.call_transcript_id
               JOIN m_STTCallTranscriptRevision revision
                 ON revision.call_transcript_id = event.call_transcript_id
                AND revision.revision = event.revision
              WHERE (CAST(event.created_at AS INTEGER) > :created_at
                     OR (CAST(event.created_at AS INTEGER) = :created_at AND CAST(event.id AS INTEGER) > :event_id))
                AND event.event_type IN (' . $typeList . ')
              ORDER BY CAST(event.created_at AS INTEGER) ASC, CAST(event.id AS INTEGER) ASC
              LIMIT ' . $limit,
            Enum::FETCH_ASSOC,
            ['created_at' => $createdAt, 'event_id' => $eventId] + $typeBind
        );
    }

    /**
     * Same keyset page as the default feed, plus tombstones whose aggregate
     * is already gone (hence the LEFT JOINs).
     *
     * @param array<int, string> $liveTypes
     * @return array<int, array<string, mixed>>
     */
    private function eventRowsWithDeletions(int $createdAt, int $eventId, int $limit, array $liveTypes): array
    {
        [$typeList, $typeBind] = $this->typeList($liveTypes);

        return (new STTCallTranscriptEvent())->getReadConnection()->fetchAll(
            'SELECT event.id, event.event_type, event.event_key, event.call_transcript_id, event.revision,
                    event.created_at, aggregate.call_id, revision.content_hash, revision.parts_hash,
                    revision.completeness_status
               FROM m_STTCallTranscriptEvent event
          LEFT JOIN m_STTCallTranscript aggregate ON aggregate.id = event.call_transcript_id
          LEFT JOIN m_STTCallTranscriptRevision revision
                 ON revision.call_transcript_id = event.call_transcript_id
                AND revision.revision = event.revision
              WHERE (CAST(event.created_at AS INTEGER) > :created_at
                     OR (CAST(event.created_at AS INTEGER) = :created_at AND CAST(event.id AS INTEGER) > :event_id))
                AND (event.event_type = :deleted_type
                     OR (event.event_type IN (' . $typeList . ')
                         AND aggregate.id IS NOT NULL AND revision.id IS NOT NULL))
              ORDER BY CAST(event.created_at AS INTEGER) ASC, CAST(event.id AS INTEGER) ASC
              LIMIT ' . $limit,
            Enum::FETCH_ASSOC,
            [
                'created_at' => $createdAt,
                'event_id' => $eventId,
                'deleted_type' => CallTranscriptDeletionEvents::EVENT_TYPE,
            ] + $typeBind
        );
    }

    /**
     * @param array<int, string> $types
     * @return array{0: string, 1: array<string, string>} placeholder list and its binds
     */
    private function typeList(array $types): array
    {
        $placeholders = [];
        $bind = [];
        foreach (array_values($types) as $index => $type) {
            $placeholders[] = ':type_' . $index;
            $bind['type_' . $index] = $type;
        }

        return [implode(', ', $placeholders), $bind];
    }

    /** @param array<string, mixed> $row @return array<string, mixed>|null */
    private function deletionEvent(array $row, int $createdAt, int $rowId): ?array
    {
        $aggregateId = (int)($row['call_transcript_id'] ?? 0);
        $reason = CallTranscriptDeletionEvents::reasonFromEventKey((string)($row['event_key'] ?? ''));
        if ($aggregateId < 1 || $reason === null || $createdAt < 1) {
            return null;
        }

        return [
            'cursor' => $this->encodeCursor($createdAt, $rowId),
            'event_type' => CallTranscriptDeletionEvents::EVENT_TYPE,
            'call_id' => null,
            'aggregate_id' => $aggregateId,
            'revision' => max(0, (int)($row['revision'] ?? 0)),
            'content_hash' => null,
            'parts_hash' => null,
            'completeness_status' => null,
            'deleted_at' => $createdAt,
            'deletion_reason' => $reason,
        ];
    }

    private function logRejectedEvent(int $rowId, int $aggregateId): void
    {
        if (class_exists(LocalSpeechToTextLogger::class)) {
            LocalSpeechToTextLogger::event(
                'transcript_import',
                'contract_event_rejected',
                'Stored transcript event identity is invalid',
                LOG_ERR,
                ['event_id' => $rowId, 'aggregate_id' => $aggregateId]
            );
        }
    }

    private function flag(mixed $value): bool
    {
        return $value === true || in_array(strtolower(trim((string)(is_scalar($value) ? $value : ''))), ['1', 'true', 'yes'], true);
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

    /** @param array<string, mixed> $event @param array<int, string> $liveTypes */
    private function validEvent(array $event, array $liveTypes): bool
    {
        return in_array($event['event_type'] ?? '', $liveTypes, true)
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
