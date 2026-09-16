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

    /**
     * Labels the call's own parties for the call-history modal.
     *
     * The modal cannot take these from the history row it was opened from:
     * for an incoming call with a DID, Core shows the DID as the row's
     * destination and deliberately blanks its name, so the extension the
     * transcript is actually about is never named there. The transcript's own
     * CDR rows carry both names, ungrouped.
     *
     * Labels are decoration; without a CDR record the modal keeps showing the
     * bare numbers it already had.
     *
     * @param array<int, int> $cdrIds
     * @return array<string, string>
     */
    private function partyLabels(array $cdrIds): array
    {
        if ($this->recordings === null || $cdrIds === []) {
            return [];
        }
        $cdr = $this->recordings->cdrRecordById(array_values($cdrIds)[0]);
        if ($cdr === null) {
            return [];
        }

        return array_filter([
            'source' => $this->recordings->numberWithEmployeeName((string)($cdr->src_num ?? ''), (string)($cdr->src_name ?? '')),
            'destination' => $this->recordings->numberWithEmployeeName((string)($cdr->dst_num ?? ''), (string)($cdr->dst_name ?? '')),
        ], static fn (string $label): bool => $label !== '');
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
        $projected = (new CallTranscriptTimelineProjector())->project(['segments' => $segments], $manifest);
        $segments = $projected['segments'];
        $timelineReliable = (bool)($projected['timeline_reliable'] ?? false);
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
            'parties' => $this->partyLabels($cdrIds),
            'conversation' => $conversation,
        ];
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
