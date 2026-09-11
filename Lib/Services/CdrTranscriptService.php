<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Phalcon\Db\Enum;
use RuntimeException;

final class CdrTranscriptService
{
    private const int MAX_CALL_IDS = 100;
    private const int MAX_CALL_ID_BYTES = 255;

    public function __construct(
        private readonly mixed $translation,
        private readonly UsersUITranscriptAccessService $access,
        private readonly CallTranscriptIntegrationService $integration,
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
     *     conversation: array<string, mixed>
     * }
     */
    public function detail(string $callId, UsersUICallScope $scope): array
    {
        $callId = $this->normalizeCallIds([$callId])[0] ?? '';
        $call = $callId === '' ? null : STTCallTranscript::findFirst([
            'conditions' => 'call_id = :call_id: AND current_revision > 0',
            'bind' => ['call_id' => $callId],
        ]);
        if (!$call instanceof STTCallTranscript || !$this->access->allowsCallId($callId, $scope)) {
            throw new RuntimeException('Transcript not found');
        }

        $aggregate = $this->integration->callTranscriptDetail((int)$call->id);
        $segments = is_array($aggregate['transcript']['segments'] ?? null)
            ? $aggregate['transcript']['segments']
            : [];
        $segmentsJson = json_encode(
            $segments,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '[]';

        return [
            'aggregateId' => (int)$aggregate['aggregate_id'],
            'callId' => (string)$aggregate['call_id'],
            'revision' => (int)$aggregate['revision'],
            'completenessStatus' => (string)$aggregate['completeness_status'],
            'conversation' => (new TranscriptConversationService($this->translation))
                ->conversationFromTranscript($segmentsJson),
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
