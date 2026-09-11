<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use MikoPBX\Common\Models\CallDetailRecords;

/**
 * Maps the stereo recording channels (left/right) of a single-CDR call to
 * "Client"/"Employee" labels for the transcript conversation card.
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

    public function __construct(
        private readonly mixed $translation,
        private readonly CallDirectionResolver $directionResolver,
    ) {
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
        $sourceLabel = $direction === CallDirectionResolver::DIRECTION_INCOMING ? $client : $employee;
        $destinationLabel = $direction === CallDirectionResolver::DIRECTION_OUTGOING ? $client : $employee;

        return $recSrcChannel === '0'
            ? [self::CHANNEL_LEFT => $sourceLabel, self::CHANNEL_RIGHT => $destinationLabel]
            : [self::CHANNEL_LEFT => $destinationLabel, self::CHANNEL_RIGHT => $sourceLabel];
    }
}
