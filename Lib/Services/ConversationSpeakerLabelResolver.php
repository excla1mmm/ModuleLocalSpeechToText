<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use Closure;
use MikoPBX\Common\Models\CallDetailRecords;

/**
 * Maps the stereo recording channels (left/right) of a single-CDR call to
 * "Client"/employee labels for the transcript conversation card. An employee
 * side is labeled with that employee's PBX name when one is known and falls
 * back to the generic "Employee" otherwise.
 *
 * The mapping relies on two CDR facts: `rec_src_channel` (which physical
 * channel carries src_num's audio: '0'=left, '1'=right, ''=mono/unknown) and
 * the call direction (which of src_num/dst_num is external). A call that
 * spans more than one CDR (e.g. a transfer) has no single reliable mapping,
 * so callers should only pass a CDR when the whole conversation is backed by
 * exactly one CDR row.
 */
final class ConversationSpeakerLabelResolver
{
    private const string CHANNEL_LEFT = 'left';
    private const string CHANNEL_RIGHT = 'right';

    /** @var Closure(string):string|null */
    private readonly ?Closure $employeeName;

    /** @param callable(string):string|null $employeeName trusted employee name by internal number, '' when unknown */
    public function __construct(
        private readonly mixed $translation,
        private readonly CallDirectionResolver $directionResolver,
        ?callable $employeeName = null,
    ) {
        $this->employeeName = $employeeName === null ? null : Closure::fromCallable($employeeName);
    }

    /** @return array{left: string, right: string}|null */
    public function resolve(?CallDetailRecords $cdr): ?array
    {
        if (!$cdr instanceof CallDetailRecords) {
            return null;
        }

        $recSrcChannel = trim((string)($cdr->rec_src_channel ?? ''));
        if ($recSrcChannel !== '0' && $recSrcChannel !== '1') {
            return null;
        }

        $direction = $this->directionResolver->resolve($cdr)['direction'];
        if (!in_array($direction, [
            CallDirectionResolver::DIRECTION_INCOMING,
            CallDirectionResolver::DIRECTION_OUTGOING,
            CallDirectionResolver::DIRECTION_INTERNAL,
        ], true)) {
            return null;
        }

        $client = (string)$this->translation->_('module_local_speech_to_text_Client');
        $employee = (string)$this->translation->_('module_local_speech_to_text_Employee');

        // src_num is external (the client) only for an incoming call; everywhere
        // else (outgoing, internal) it is the employee side.
        $sourceLabel = $direction === CallDirectionResolver::DIRECTION_INCOMING
            ? $client
            : $this->employeeLabel((string)$cdr->src_num, $employee);
        $destinationLabel = $direction === CallDirectionResolver::DIRECTION_OUTGOING
            ? $client
            : $this->employeeLabel((string)$cdr->dst_num, $employee);

        return $recSrcChannel === '0'
            ? [self::CHANNEL_LEFT => $sourceLabel, self::CHANNEL_RIGHT => $destinationLabel]
            : [self::CHANNEL_LEFT => $destinationLabel, self::CHANNEL_RIGHT => $sourceLabel];
    }

    private function employeeLabel(string $number, string $fallback): string
    {
        if ($this->employeeName === null || trim($number) === '') {
            return $fallback;
        }
        $name = trim((string)($this->employeeName)(trim($number)));

        return $name !== '' ? $name : $fallback;
    }
}
