<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Modules\ModuleLocalSpeechToText\Models\STTCallTranscript;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptPart;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptRevision;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Modules\ModuleLocalSpeechToText\Models\STTSetting;
use RuntimeException;

final class CallTranscriptRevisionService
{
    private readonly CallTranscriptAssembler $assembler;
    private readonly CallTranscriptRevisionPolicy $policy;

    public function __construct(
        ?CallTranscriptAssembler $assembler = null,
        ?CallTranscriptRevisionPolicy $policy = null,
    ) {
        $this->assembler = $assembler ?? new CallTranscriptAssembler();
        $this->policy = $policy ?? new CallTranscriptRevisionPolicy(
            max(0, min(3600, (int)STTSetting::getValue(STTSetting::CALL_TRANSCRIPT_STABILITY_SEC, '30')))
        );
    }

    /** @return array{state: string, call_transcript_id?: int, content_hash?: string} */
    public function recordCompletedPart(STTResult $result, STTJob $job): array
    {
        $callId = trim((string)$job->call_id);
        if ($callId === '') {
            return ['state' => 'skipped_empty_call_id'];
        }

        $segments = json_decode((string)$result->transcript, true);
        $segments = is_array($segments) ? array_values(array_filter($segments, 'is_array')) : [];
        $contentHash = hash('sha256', $this->canonicalJson($segments));
        $fingerprint = trim((string)$job->recording_fingerprint);
        if ($fingerprint === '') {
            $fingerprint = hash('sha256', 'legacy-result:' . (int)$result->id);
        }

        $now = time();
        $call = STTCallTranscript::findFirst([
            'conditions' => 'call_id = :call_id:',
            'bind' => ['call_id' => $callId],
        ]);
        if (!$call instanceof STTCallTranscript) {
            $call = new STTCallTranscript();
            $call->call_id = $callId;
            $call->created_at = (string)$now;
        }

        $existing = null;
        if ((int)$call->id > 0) {
            $existing = STTCallTranscriptPart::findFirst([
                'conditions' => 'call_transcript_id = :call_id: AND result_id = :result_id: AND is_current = 1',
                'bind' => [
                    'call_id' => (int)$call->id,
                    'result_id' => (int)$result->id,
                ],
            ]);
            if ($existing instanceof STTCallTranscriptPart
                && hash_equals((string)$existing->content_hash, $contentHash)
                && hash_equals((string)$existing->recording_fingerprint, $fingerprint)
            ) {
                return [
                    'state' => 'unchanged',
                    'call_transcript_id' => (int)$call->id,
                    'content_hash' => (string)$call->current_content_hash,
                ];
            }
        }

        $call->updated_at = (string)$now;
        $this->save($call, 'call transcript');

        if ($existing instanceof STTCallTranscriptPart) {
            $existing->is_current = '0';
            $this->save($existing, 'superseded call transcript part');
        }

        $resolutionStatus = (int)$job->cdr_id > 0 && (int)$job->cdr_start_ms > 0
            ? 'complete'
            : 'legacy_unresolved';
        $part = new STTCallTranscriptPart();
        $part->call_transcript_id = (int)$call->id;
        $part->result_id = (int)$result->id;
        $part->job_id = (int)$job->id > 0 ? (int)$job->id : null;
        $part->cdr_id = (int)$job->cdr_id > 0 ? (int)$job->cdr_id : null;
        $part->cdr_uniqueid = trim((string)$job->cdr_uniqueid);
        $part->cdr_start_ms = (int)$job->cdr_start_ms > 0 ? (string)(int)$job->cdr_start_ms : null;
        $part->cdr_end_ms = (int)$job->cdr_end_ms > 0 ? (string)(int)$job->cdr_end_ms : null;
        $part->recording_fingerprint = $fingerprint;
        $part->content_hash = $contentHash;
        $part->source_label = $part->cdr_id === null ? 'legacy:' . (int)$result->id : 'cdr:' . $part->cdr_id;
        $part->resolution_status = $resolutionStatus;
        $part->segments_json = $this->canonicalJson($segments);
        $part->is_current = '1';
        $part->created_at = (string)$now;
        $this->save($part, 'call transcript part');

        if ($existing instanceof STTCallTranscriptPart) {
            $existing->superseded_by_part_id = (int)$part->id;
            $this->save($existing, 'call transcript part supersession link');
        }

        $assembled = $this->assembler->assemble($callId, $this->currentParts((int)$call->id));
        $staged = $this->policy->stage(
            $this->callState($call),
            (string)$assembled['content_hash'],
            (string)$assembled['parts_hash'],
            $this->canonicalJson($assembled),
            (string)$assembled['completeness_status'],
            $now
        );
        $this->applyCallState($call, $staged['call']);
        $this->save($call, 'staged call transcript');

        return [
            'state' => $staged['state'],
            'call_transcript_id' => (int)$call->id,
            'content_hash' => (string)$assembled['content_hash'],
        ];
    }

    /** @return array{scanned: int, published: int, blocked_jobs: int} */
    public function publishReady(?int $now = null, int $limit = 100): array
    {
        $now ??= time();
        $summary = ['scanned' => 0, 'published' => 0, 'blocked_jobs' => 0];
        $calls = STTCallTranscript::find([
            'conditions' => 'pending_content_hash <> :empty: AND CAST(stable_after AS INTEGER) <= :now:',
            'bind' => ['empty' => '', 'now' => $now],
            'order' => 'CAST(stable_after AS INTEGER) ASC, id ASC',
            'limit' => max(1, min(1000, $limit)),
        ]);

        foreach ($calls as $call) {
            if (!$call instanceof STTCallTranscript) {
                continue;
            }
            $summary['scanned']++;
            $allTerminal = STTJob::count([
                'conditions' => 'call_id = :call_id: AND (status = :pending: OR status = :processing:)',
                'bind' => [
                    'call_id' => (string)$call->call_id,
                    'pending' => STTJob::STATUS_PENDING,
                    'processing' => STTJob::STATUS_PROCESSING,
                ],
            ]) === 0;
            $publication = $this->policy->publication($this->callState($call), $now, $allTerminal);
            if ($publication === null) {
                if (!$allTerminal) {
                    $summary['blocked_jobs']++;
                }
                continue;
            }

            $revision = STTCallTranscriptRevision::findFirst([
                'conditions' => 'call_transcript_id = :call_id: AND revision = :revision:',
                'bind' => ['call_id' => (int)$call->id, 'revision' => (int)$publication['revision']],
            ]);
            if (!$revision instanceof STTCallTranscriptRevision) {
                $revision = new STTCallTranscriptRevision();
                $revision->call_transcript_id = (int)$call->id;
                $revision->revision = (string)(int)$publication['revision'];
                $revision->content_hash = (string)$publication['content_hash'];
                $revision->parts_hash = (string)$publication['parts_hash'];
                $revision->completeness_status = (string)$publication['completeness_status'];
                $revision->payload = (string)$publication['payload'];
                $revision->created_at = (string)$now;
                $this->save($revision, 'call transcript revision');
            }

            $eventKey = sprintf(
                '%s:%d:%d:%s',
                (string)$publication['event_type'],
                (int)$call->id,
                (int)$publication['revision'],
                (string)$publication['content_hash']
            );
            $event = STTCallTranscriptEvent::findFirst([
                'conditions' => 'event_key = :event_key:',
                'bind' => ['event_key' => $eventKey],
            ]);
            if (!$event instanceof STTCallTranscriptEvent) {
                $event = new STTCallTranscriptEvent();
                $event->event_type = (string)$publication['event_type'];
                $event->event_key = $eventKey;
                $event->call_transcript_id = (int)$call->id;
                $event->call_id = (string)$call->call_id;
                $event->revision = (string)(int)$publication['revision'];
                $event->content_hash = (string)$publication['content_hash'];
                $event->parts_hash = (string)$publication['parts_hash'];
                $event->completeness_status = (string)$publication['completeness_status'];
                $event->created_at = (string)$now;
                $this->save($event, 'call transcript event');
            }

            $this->applyCallState($call, $this->policy->applyPublication($this->callState($call), $publication, $now));
            $this->save($call, 'published call transcript');
            $summary['published']++;
        }

        return $summary;
    }

    /** @return array<int, array<string, mixed>> */
    private function currentParts(int $callTranscriptId): array
    {
        $parts = [];
        foreach (STTCallTranscriptPart::find([
            'conditions' => 'call_transcript_id = :call_id: AND is_current = 1',
            'bind' => ['call_id' => $callTranscriptId],
        ]) as $part) {
            if (!$part instanceof STTCallTranscriptPart) {
                continue;
            }
            $segments = json_decode((string)$part->segments_json, true);
            $parts[] = [
                'result_id' => (int)$part->result_id,
                'job_id' => $part->job_id === null ? null : (int)$part->job_id,
                'cdr_id' => $part->cdr_id === null ? null : (int)$part->cdr_id,
                'uniqueid' => (string)$part->cdr_uniqueid,
                'cdr_start_ms' => $part->cdr_start_ms === null ? null : (int)$part->cdr_start_ms,
                'cdr_end_ms' => $part->cdr_end_ms === null ? null : (int)$part->cdr_end_ms,
                'recording_fingerprint' => (string)$part->recording_fingerprint,
                'content_hash' => (string)$part->content_hash,
                'source_label' => (string)$part->source_label,
                'resolution_status' => (string)$part->resolution_status,
                'segments' => is_array($segments) ? $segments : [],
            ];
        }

        return $parts;
    }

    /** @return array<string, mixed> */
    private function callState(STTCallTranscript $call): array
    {
        return [
            'current_revision' => (int)$call->current_revision,
            'current_content_hash' => (string)$call->current_content_hash,
            'current_parts_hash' => (string)$call->current_parts_hash,
            'pending_content_hash' => (string)$call->pending_content_hash,
            'pending_parts_hash' => (string)$call->pending_parts_hash,
            'pending_payload' => (string)$call->pending_payload,
            'completeness_status' => (string)$call->completeness_status,
            'stable_after' => (int)$call->stable_after,
            'published_at' => (int)$call->published_at,
            'updated_at' => (int)$call->updated_at,
        ];
    }

    /** @param array<string, mixed> $state */
    private function applyCallState(STTCallTranscript $call, array $state): void
    {
        foreach ([
            'current_revision', 'current_content_hash', 'current_parts_hash',
            'pending_content_hash', 'pending_parts_hash', 'pending_payload',
            'completeness_status', 'stable_after', 'published_at', 'updated_at',
        ] as $field) {
            if (array_key_exists($field, $state)) {
                $call->{$field} = (string)$state[$field];
            }
        }
    }

    private function save(object $model, string $label): void
    {
        if (!$model->save()) {
            throw new RuntimeException($label . ': ' . implode(', ', $model->getMessages()));
        }
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
