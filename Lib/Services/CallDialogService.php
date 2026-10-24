<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use Modules\ModuleLocalSpeechToText\Lib\Exceptions\CallTranscriptNotFoundException;
use Modules\ModuleLocalSpeechToText\Models\STTCallTranscriptEvent;
use Modules\ModuleLocalSpeechToText\Models\STTJob;
use Modules\ModuleLocalSpeechToText\Models\STTResult;
use Phalcon\Db\Enum;
use Phalcon\Di\Di;
use Throwable;

require_once __DIR__ . '/CallTranscriptIntegrationService.php';
require_once __DIR__ . '/CallSummaryProjection.php';

/**
 * The simple reads of one call for third-party services, looked up by the PBX
 * call id (linkedid, or the UNIQUEID of any recorded leg) and always for the
 * latest published revision: the dialog (phrases joined into turns, signed
 * with the speaker role) and, separately, the call summary.
 */
final class CallDialogService
{
    public const string ROLE_CLIENT = 'client';
    public const string ROLE_EMPLOYEE = 'employee';
    private const int MAX_CALL_ID_LENGTH = 255;

    /** @var Closure(int): array{direction: ?string, speakers: ?array<string, array{role: string, name: ?string}>} */
    private readonly Closure $partContext;
    /** @var Closure(string): string */
    private readonly Closure $roleLabel;

    /**
     * @param (callable(int): array{direction: ?string, speakers: ?array<string, array{role: string, name: ?string}>})|null $partContext
     *        call direction and channel speakers of one part result
     * @param (callable(string): string)|null $roleLabel display name of a role
     */
    public function __construct(
        private readonly CallTranscriptIntegrationService $transcripts = new CallTranscriptIntegrationService(),
        ?callable $partContext = null,
        ?callable $roleLabel = null,
    ) {
        $this->partContext = Closure::fromCallable($partContext ?? self::pbxPartContext(...));
        $this->roleLabel = Closure::fromCallable($roleLabel ?? self::translatedRoleLabel(...));
    }

    public static function validCallId(string $callId): bool
    {
        return $callId !== ''
            && strlen($callId) <= self::MAX_CALL_ID_LENGTH
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\-]*\z/', $callId) === 1;
    }

    /** @return array<string, mixed> */
    public function forCallId(string $callId): array
    {
        $dialog = $this->signedDialog($this->transcripts->callTranscriptDetail($this->requireAggregateId($callId)));

        $dialog['turns'] = array_map(static fn (array $turn): array => array_diff_key($turn, ['result_id' => true]), $dialog['turns']);

        return array_diff_key($dialog, ['content_hash' => true]);
    }

    /**
     * The signed dialog of one call revision for the summary prompt: every
     * turn also names the part result (the leg) it comes from.
     *
     * @return array<string, mixed>
     */
    public function forAggregateRevision(int $aggregateId, int $revision): array
    {
        return $this->signedDialog($this->transcripts->callTranscriptDetail($aggregateId, $revision));
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function signedDialog(array $detail): array
    {
        $contexts = [];
        $turns = [];
        $lines = [];
        $rawTurns = is_array($detail['transcript']['turns'] ?? null) ? $detail['transcript']['turns'] : [];
        foreach ($rawTurns as $turn) {
            if (!is_array($turn) || trim((string)($turn['text'] ?? '')) === '') {
                continue;
            }
            $resultId = (int)(is_array($turn['source_result_ids'] ?? null) ? ($turn['source_result_ids'][0] ?? 0) : 0);
            $contexts[$resultId] ??= ($this->partContext)($resultId);
            $channel = is_string($turn['channel'] ?? null) && $turn['channel'] !== '' ? (string)$turn['channel'] : null;
            $speaker = $channel === null ? null : ($contexts[$resultId]['speakers'][$channel] ?? null);
            $text = trim((string)$turn['text']);
            $turns[] = [
                'start_ms' => isset($turn['start_ms']) ? (int)$turn['start_ms'] : null,
                'end_ms' => isset($turn['end_ms']) ? (int)$turn['end_ms'] : null,
                'channel' => $channel,
                'speaker' => $speaker['role'] ?? null,
                'speaker_name' => $speaker['name'] ?? null,
                'text' => $text,
                'result_id' => $resultId,
            ];
            $lines[] = $this->speakerLabel($speaker, $channel) . ': ' . $text;
        }
        $firstResultId = (int)($detail['part_manifest'][0]['result_id'] ?? 0);
        $contexts[$firstResultId] ??= ($this->partContext)($firstResultId);

        return [
            'source_instance_id' => $detail['source_instance_id'],
            'contract_version' => $detail['contract_version'],
            'call_id' => $detail['call_id'],
            'aggregate_id' => $detail['aggregate_id'],
            'revision' => $detail['revision'],
            'content_hash' => $detail['content_hash'] ?? '',
            'completeness_status' => $detail['completeness_status'],
            'language' => $detail['language'],
            'direction' => $contexts[$firstResultId]['direction'] ?? null,
            'turns' => $turns,
            'text' => implode("\n", $lines),
        ];
    }

    /**
     * The summary of the whole call, every leg in it. Read at request time,
     * so a summary that appears later never changes the transcript revision.
     *
     * @return array<string, mixed>
     */
    public function summaryForCallId(string $callId): array
    {
        $detail = $this->transcripts->callTranscriptDetail($this->requireAggregateId($callId));

        return [
            'source_instance_id' => $detail['source_instance_id'],
            'contract_version' => $detail['contract_version'],
            'call_id' => $detail['call_id'],
            'aggregate_id' => $detail['aggregate_id'],
            'revision' => $detail['revision'],
        ] + (new CallSummaryProjection())->forAggregateId((int)$detail['aggregate_id']);
    }

    private function requireAggregateId(string $callId): int
    {
        $callId = trim($callId);
        $aggregateId = self::validCallId($callId) ? $this->aggregateId($callId) : 0;
        if ($aggregateId < 1) {
            throw new CallTranscriptNotFoundException('Call transcript not found by call id');
        }

        return $aggregateId;
    }

    /** The call aggregate of a linkedid, or of the call that recorded the given leg UNIQUEID. */
    private function aggregateId(string $callId): int
    {
        $connection = (new STTCallTranscriptEvent())->getReadConnection();
        foreach ([
            'SELECT id FROM m_STTCallTranscript
              WHERE call_id = :call_id AND CAST(current_revision AS INTEGER) > 0 LIMIT 1',
            'SELECT c.id FROM m_STTCallTranscriptPart p
               JOIN m_STTCallTranscript c ON c.id = p.call_transcript_id
              WHERE p.cdr_uniqueid = :call_id AND CAST(c.current_revision AS INTEGER) > 0
              ORDER BY p.is_current DESC, p.id DESC LIMIT 1',
        ] as $sql) {
            $rows = $connection->fetchAll($sql, Enum::FETCH_ASSOC, ['call_id' => $callId]);
            $id = (int)($rows[0]['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /** @param array{role: string, name: ?string}|null $speaker */
    private function speakerLabel(?array $speaker, ?string $channel): string
    {
        if ($speaker !== null) {
            return $speaker['name'] ?? ($this->roleLabel)($speaker['role']);
        }

        return ($this->roleLabel)($channel === 'right' ? 'speaker_2' : 'speaker_1');
    }

    /**
     * Direction and channel speakers from the part's CDR, as the transcript
     * card resolves them. An unknown mapping leaves the speakers null.
     *
     * @return array{direction: ?string, speakers: ?array<string, array{role: string, name: ?string}>}
     */
    private static function pbxPartContext(int $resultId): array
    {
        $none = ['direction' => null, 'speakers' => null];
        $result = $resultId > 0 ? STTResult::findFirstById($resultId) : null;
        if (!$result instanceof STTResult) {
            return $none;
        }
        // Role markers instead of display labels; an employee with a known
        // PBX name comes back as that name.
        $markers = new class {
            public function _(string $key): string
            {
                return match ($key) {
                    'module_local_speech_to_text_Client' => "\0" . CallDialogService::ROLE_CLIENT,
                    'module_local_speech_to_text_Employee' => "\0" . CallDialogService::ROLE_EMPLOYEE,
                    default => $key,
                };
            }
        };
        try {
            $recordings = new CallRecordingService($markers, null, null, null);
            $directions = new CallDirectionResolver(CallDirectionResolver::loadInternalNumbers());
            $job = $result->STTJob;
            $cdr = $job instanceof STTJob ? $recordings->cdrRecordForJob($job) : null;
            $cdr ??= $recordings->cdrRecordForResult($result);
            $direction = $directions->resolve($cdr)['direction'];
            $labels = (new ConversationSpeakerLabelResolver($markers, $directions, $recordings->employeeName(...)))->resolve($cdr);
        } catch (Throwable) {
            return $none;
        }

        $speakers = null;
        foreach ($labels ?? [] as $channel => $label) {
            $speakers[$channel] = match ($label) {
                "\0" . self::ROLE_CLIENT => ['role' => self::ROLE_CLIENT, 'name' => null],
                "\0" . self::ROLE_EMPLOYEE => ['role' => self::ROLE_EMPLOYEE, 'name' => null],
                default => ['role' => self::ROLE_EMPLOYEE, 'name' => $label],
            };
        }

        return [
            'direction' => $direction === CallDirectionResolver::DIRECTION_UNKNOWN ? null : $direction,
            'speakers' => $speakers,
        ];
    }

    private static function translatedRoleLabel(string $role): string
    {
        [$key, $fallback] = match ($role) {
            self::ROLE_CLIENT => ['module_local_speech_to_text_Client', 'Client'],
            self::ROLE_EMPLOYEE => ['module_local_speech_to_text_Employee', 'Employee'],
            'speaker_2' => ['module_local_speech_to_text_DialogSpeaker2', 'Speaker 2'],
            default => ['module_local_speech_to_text_DialogSpeaker1', 'Speaker 1'],
        };
        try {
            $translated = (string)Di::getDefault()?->getShared('translation')->_($key);
        } catch (Throwable) {
            $translated = '';
        }

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }
}
