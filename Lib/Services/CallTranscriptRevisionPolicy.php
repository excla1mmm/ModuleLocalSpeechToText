<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

final class CallTranscriptRevisionPolicy
{
    public function __construct(private readonly int $stabilitySeconds = 30)
    {
    }

    /**
     * @param array<string, mixed> $call
     * @return array{state: string, call: array<string, mixed>}
     */
    public function stage(
        array $call,
        string $contentHash,
        string $partsHash,
        string $payload,
        string $completenessStatus,
        int $now
    ): array {
        if ($contentHash === (string)($call['current_content_hash'] ?? '')
            || $contentHash === (string)($call['pending_content_hash'] ?? '')
        ) {
            return [
                'state' => 'unchanged',
                'stable_after' => (int)($call['stable_after'] ?? 0),
                'call' => $call,
            ];
        }

        $call['pending_content_hash'] = $contentHash;
        $call['pending_parts_hash'] = $partsHash;
        $call['pending_payload'] = $payload;
        $call['completeness_status'] = $completenessStatus;
        $call['stable_after'] = $now + max(0, $this->stabilitySeconds);
        $call['updated_at'] = $now;

        return [
            'state' => 'staged',
            'stable_after' => (int)$call['stable_after'],
            'call' => $call,
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed>|null
     */
    public function publication(array $call, int $now, bool $allKnownJobsTerminal): ?array
    {
        $contentHash = trim((string)($call['pending_content_hash'] ?? ''));
        if ($contentHash === ''
            || $contentHash === (string)($call['current_content_hash'] ?? '')
            || !$allKnownJobsTerminal
            || (int)($call['stable_after'] ?? PHP_INT_MAX) > $now
        ) {
            return null;
        }

        $revision = max(0, (int)($call['current_revision'] ?? 0)) + 1;

        return [
            'revision' => $revision,
            'event_type' => $revision === 1 ? 'call-transcript.completed' : 'call-transcript.updated',
            'content_hash' => $contentHash,
            'parts_hash' => (string)($call['pending_parts_hash'] ?? ''),
            'payload' => (string)($call['pending_payload'] ?? ''),
            'completeness_status' => (string)($call['completeness_status'] ?? 'complete'),
        ];
    }

    /**
     * @param array<string, mixed> $call
     * @param array<string, mixed> $publication
     * @return array<string, mixed>
     */
    public function applyPublication(array $call, array $publication, int $now): array
    {
        $call['current_revision'] = (int)$publication['revision'];
        $call['current_content_hash'] = (string)$publication['content_hash'];
        $call['current_parts_hash'] = (string)$publication['parts_hash'];
        $call['pending_content_hash'] = '';
        $call['pending_parts_hash'] = '';
        $call['pending_payload'] = '';
        $call['stable_after'] = 0;
        $call['published_at'] = $now;
        $call['updated_at'] = $now;

        return $call;
    }
}
