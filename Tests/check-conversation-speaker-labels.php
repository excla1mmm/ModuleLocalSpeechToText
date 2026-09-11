<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    class CallDetailRecords
    {
        public string $src_num = '';
        public string $dst_num = '';
        public string $did = '';
        public string $from_account = '';
        public string $to_account = '';
        public string $rec_src_channel = '';
    }
}

namespace {
    require_once __DIR__ . '/../Lib/Services/CallDirectionResolver.php';
    require_once __DIR__ . '/../Lib/Services/ConversationSpeakerLabelResolver.php';
    require_once __DIR__ . '/../Lib/TranscriptTimeNormalizer.php';
    require_once __DIR__ . '/../Lib/Services/TranscriptConversationService.php';

    use MikoPBX\Common\Models\CallDetailRecords;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallDirectionResolver;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ConversationSpeakerLabelResolver;
    use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptConversationService;

    function expectSpeakerLabels(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    function cdr(string $src, string $dst, string $recSrcChannel, string $did = '', string $fromAccount = '', string $toAccount = ''): CallDetailRecords
    {
        $cdr = new CallDetailRecords();
        $cdr->src_num = $src;
        $cdr->dst_num = $dst;
        $cdr->rec_src_channel = $recSrcChannel;
        $cdr->did = $did;
        $cdr->from_account = $fromAccount;
        $cdr->to_account = $toAccount;

        return $cdr;
    }

    $translation = new class {
        public function _(string $key): string
        {
            return match ($key) {
                'module_local_speech_to_text_Client' => 'Клиент',
                'module_local_speech_to_text_Employee' => 'Сотрудник',
                'module_local_speech_to_text_Speaker1' => 'Участник 1',
                'module_local_speech_to_text_Speaker2' => 'Участник 2',
                default => $key,
            };
        }
    };
    $internalNumbers = ['208' => true, '206' => true];
    $directionResolver = new CallDirectionResolver($internalNumbers);
    $labelResolver = new ConversationSpeakerLabelResolver($translation, $directionResolver);

    // Incoming call: external src_num on the recorded channel (rec_src_channel=0 → left).
    expectSpeakerLabels(
        $directionResolver->resolve(cdr('790099241808', '208', '0'))['direction'] === CallDirectionResolver::DIRECTION_INCOMING,
        'external -> internal must resolve as incoming'
    );
    expectSpeakerLabels(
        $labelResolver->resolve(cdr('790099241808', '208', '0')) === ['left' => 'Клиент', 'right' => 'Сотрудник'],
        'incoming call: src_num on left must be labeled client, dst_num on right employee'
    );

    // Same incoming call, but this time the recording put src_num on the right channel.
    expectSpeakerLabels(
        $labelResolver->resolve(cdr('790099241808', '208', '1')) === ['left' => 'Сотрудник', 'right' => 'Клиент'],
        'incoming call: src_num on right must be labeled client, dst_num on left employee'
    );

    // Outgoing call: internal src_num (employee) calls an external destination (client).
    expectSpeakerLabels(
        $directionResolver->resolve(cdr('208', '790099241808', '0'))['direction'] === CallDirectionResolver::DIRECTION_OUTGOING,
        'internal -> external must resolve as outgoing'
    );
    expectSpeakerLabels(
        $labelResolver->resolve(cdr('208', '790099241808', '0')) === ['left' => 'Сотрудник', 'right' => 'Клиент'],
        'outgoing call: src_num (employee) on left, dst_num (client) on right'
    );

    // Internal call: both sides are extensions, labeled "Employee" on both channels.
    expectSpeakerLabels(
        $directionResolver->resolve(cdr('208', '206', '0'))['direction'] === CallDirectionResolver::DIRECTION_INTERNAL,
        'internal <-> internal must resolve as internal'
    );
    expectSpeakerLabels(
        $labelResolver->resolve(cdr('208', '206', '0')) === ['left' => 'Сотрудник', 'right' => 'Сотрудник'],
        'internal call must label both channels as employee'
    );

    // Neither number is a known extension, but a DID/from_account hints at an incoming call.
    expectSpeakerLabels(
        $directionResolver->resolve(cdr('79001112233', '79004445566', '0', did: '74951234567'))['direction']
            === CallDirectionResolver::DIRECTION_INCOMING,
        'a DID on an otherwise unmatched call must still resolve as incoming'
    );

    // Mono/undetermined recording channel: never guess.
    expectSpeakerLabels(
        $labelResolver->resolve(cdr('790099241808', '208', '')) === null,
        'a mono or undetermined rec_src_channel must not produce a client/employee guess'
    );

    // Genuinely unknown direction (no internal match, no DID/account hints): never guess.
    expectSpeakerLabels(
        $labelResolver->resolve(cdr('79001112233', '79004445566', '0')) === null,
        'an unresolved direction must not produce a client/employee guess'
    );

    // No CDR at all (e.g. a multi-part/transferred call the caller deliberately withheld).
    expectSpeakerLabels($labelResolver->resolve(null) === null, 'a missing CDR must not produce a client/employee guess');

    // End-to-end: the resolved labels must flow through into the rendered conversation.
    $segments = [
        ['speaker' => 'left', 'start_ms' => 0, 'end_ms' => 1_000, 'text' => 'Hello'],
        ['speaker' => 'right', 'start_ms' => 1_000, 'end_ms' => 2_000, 'text' => 'Hi'],
    ];
    $conversation = (new TranscriptConversationService($translation))->conversationFromTranscript(
        json_encode($segments, JSON_THROW_ON_ERROR),
        true,
        $labelResolver->resolve(cdr('790099241808', '208', '0'))
    );
    $labelsByKey = [];
    foreach ($conversation['speakers'] as $speaker) {
        $labelsByKey[$speaker['key']] = $speaker['label'];
    }
    expectSpeakerLabels($labelsByKey === ['left' => 'Клиент', 'right' => 'Сотрудник'], 'conversation speakers must use the resolved client/employee labels');
    expectSpeakerLabels($conversation['timeline'][0]['speakerLabel'] === 'Клиент', 'turn speaker label must reflect the override');

    // Without an override, the generic speaker labels are preserved.
    $genericConversation = (new TranscriptConversationService($translation))->conversationFromTranscript(
        json_encode($segments, JSON_THROW_ON_ERROR)
    );
    $genericLabelsByKey = [];
    foreach ($genericConversation['speakers'] as $speaker) {
        $genericLabelsByKey[$speaker['key']] = $speaker['label'];
    }
    expectSpeakerLabels(
        $genericLabelsByKey === ['left' => 'Участник 1', 'right' => 'Участник 2'],
        'without a resolved override the generic speaker labels must remain'
    );

    echo "Conversation speaker label resolution OK\n";
}
