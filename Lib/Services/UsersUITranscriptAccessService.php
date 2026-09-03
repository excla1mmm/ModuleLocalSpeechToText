<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Common\Models\CallDetailRecords;
use Throwable;

/** Applies the ModuleUsersUI CDR rule to every CDR leg of one logical call. */
final class UsersUITranscriptAccessService
{
    /** @var Closure(string): array<int, array{source: string, destination: string}> */
    private readonly Closure $participants;

    public function __construct(?callable $participants = null)
    {
        $this->participants = Closure::fromCallable($participants ?? self::defaultParticipants(...));
    }

    public function allowsCallId(string $callId, UsersUICallScope $scope): bool
    {
        if ($scope->allowsEveryCall()) {
            return true;
        }

        $callId = trim($callId);
        if ($callId === '') {
            return false;
        }

        try {
            return $scope->allowsParticipants(($this->participants)($callId));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, array{source: string, destination: string}> */
    private static function defaultParticipants(string $callId): array
    {
        $participants = [];
        foreach (CallDetailRecords::find([
            'conditions' => 'linkedid = :call_id: OR UNIQUEID = :call_id:',
            'bind' => ['call_id' => $callId],
            'order' => 'start ASC, id ASC',
        ]) as $record) {
            if (!$record instanceof CallDetailRecords) {
                continue;
            }
            $participants[] = [
                'source' => (string)$record->src_num,
                'destination' => (string)$record->dst_num,
            ];
        }

        return $participants;
    }
}
