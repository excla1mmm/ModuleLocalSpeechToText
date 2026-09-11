<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CdrTranscriptNotFoundException;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;
use Phalcon\Db\Enum;
use RuntimeException;

final class CdrTranscriptService
{
    private const int MAX_CALL_IDS = 100;
    private const int MAX_CALL_ID_BYTES = 255;

    public function __construct(
        private readonly mixed $translation,
        private readonly UsersUITranscriptAccessService $access,
        private readonly ?ConversationSpeakerLabelResolver $speakerLabelResolver = null,
        private readonly ?CallRecordingService $recordings = null,
    ) {
    }

    /** @param array<int, mixed> $callIds @return array<string, int> */
    public function lookup(array $callIds, UsersUICallScope $scope): array
    {
        $callIds = $this->normalizeCallIds($callIds);
        if ($callIds === []) {
            return [];
        }

        $placeholders = [];
        $bind = [];
        foreach ($callIds as $index => $callId) {
            $key = 'call_id_' . $index;
            $placeholders[] = ':' . $key;
            $bind[$key] = $callId;
        }

        $rows = (new STTCallTranscript())->getReadConnection()->fetchAll(
            'SELECT id, call_id FROM m_STTCallTranscript'
            . ' WHERE CAST(current_revision AS INTEGER) > 0'
            . ' AND call_id IN (' . implode(', ', $placeholders) . ')',
            Enum::FETCH_ASSOC,
            $bind
        );

        $result = [];
        foreach ($rows as $row) {
            $callId = (string)($row['call_id'] ?? '');
            if ($callId !== '' && $this->access->allowsCallId($callId, $scope)) {
                $result[$callId] = (int)($row['id'] ?? 0);
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @return array{
     *     aggregateId: int,
     *     callId: string,
     *     revision: int,
     *     completenessStatus: string,
     *     playbackCdrId: ?int,
     *     conversation: array<string, mixed>
     * }
     * @throws CdrTranscriptNotFoundException
     * @throws JsonException
     */
    public function detail(string $callId, UsersUICallScope $scope): array
    {
        $callId = $this->normalizeCallIds([$callId])[0] ?? '';
        $call = $callId === '' ? null : STTCallTranscript::findFirst([
            'conditions' => 'call_id = :call_id: AND current_revision > 0',
            'bind' => ['call_id' => $callId],
        ]);
        if (!$call instanceof STTCallTranscript || !$this->access->allowsCallId($callId, $scope)) {
            throw new CdrTranscriptNotFoundException('Transcript not found');
        }

        $revision = STTCallTranscriptRevision::findFirst([
            'conditions' => 'call_transcript_id = :aggregate_id: AND revision = :revision:',
            'bind' => [
                'aggregate_id' => (int)$call->id,
                'revision' => (int)$call->current_revision,
            ],
        ]);
        if (!$revision instanceof STTCallTranscriptRevision) {
            throw new RuntimeException('Published transcript revision is missing');
        }

        $payload = json_decode((string)$revision->payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Published transcript revision payload is invalid');
        }

        $manifest = is_array($payload['part_manifest'] ?? null) ? $payload['part_manifest'] : [];
        $segments = is_array($payload['transcript']['segments'] ?? null)
            ? array_values(array_filter($payload['transcript']['segments'], 'is_array'))
            : [];
        [$segments, $timelineReliable] = $this->callRelativeSegments($segments, $manifest);
        $segmentsJson = json_encode(
            $segments,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );

        $cdrIds = [];
        foreach ($manifest as $part) {
            if (!is_array($part) || !is_numeric($part['cdr_id'] ?? null)) {
                continue;
            }
            $cdrId = (int)$part['cdr_id'];
            if ($cdrId > 0) {
                $cdrIds[$cdrId] = $cdrId;
            }
        }
        $playbackCdrId = $timelineReliable && count($cdrIds) === 1
            ? array_values($cdrIds)[0]
            : null;

        $speakerLabels = null;
        if ($playbackCdrId !== null && $this->speakerLabelResolver !== null && $this->recordings !== null) {
            $speakerLabels = $this->speakerLabelResolver->resolve($this->recordings->cdrRecordById($playbackCdrId));
        }

        $conversation = (new TranscriptConversationService($this->translation))
            ->conversationFromTranscript($segmentsJson, $timelineReliable, $speakerLabels);

        return [
            'aggregateId' => (int)$call->id,
            'callId' => (string)$call->call_id,
            'revision' => (int)$revision->revision,
            'completenessStatus' => (string)$revision->completeness_status,
            'playbackCdrId' => $playbackCdrId,
            'conversation' => $conversation,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @param array<int, mixed> $manifest
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function callRelativeSegments(array $segments, array $manifest): array
    {
        $callStartMs = null;
        foreach ($manifest as $part) {
            $value = is_array($part) ? ($part['cdr_start_ms'] ?? null) : null;
            if (!is_numeric($value) || (int)$value <= 0) {
                continue;
            }
            $callStartMs = $callStartMs === null ? (int)$value : min($callStartMs, (int)$value);
        }

        $timelineReliable = $callStartMs !== null;
        foreach ($segments as $index => $segment) {
            $absoluteStart = $segment['absolute_start_ms'] ?? null;
            $absoluteEnd = $segment['absolute_end_ms'] ?? null;
            if (
                $callStartMs === null
                || !is_numeric($absoluteStart)
                || !is_numeric($absoluteEnd)
            ) {
                $timelineReliable = false;
                continue;
            }

            $startMs = max(0, (int)$absoluteStart - $callStartMs);
            $endMs = max($startMs, (int)$absoluteEnd - $callStartMs);
            $segments[$index]['start_ms'] = $startMs;
            $segments[$index]['end_ms'] = $endMs;
        }

        return [$segments, $timelineReliable];
    }

    /** @param array<int, mixed> $callIds @return array<int, string> */
    private function normalizeCallIds(array $callIds): array
    {
        $normalized = [];
        foreach ($callIds as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $callId = trim((string)$value);
            if ($callId === '' || strlen($callId) > self::MAX_CALL_ID_BYTES) {
                continue;
            }
            $normalized[$callId] = $callId;
            if (count($normalized) >= self::MAX_CALL_IDS) {
                break;
            }
        }

        return array_values($normalized);
    }
}
