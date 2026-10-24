<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

/**
 * Builds the complete llama-server chat request that summarizes one call.
 * Workers send it unchanged, so prompt tuning stays in the module.
 *
 * The prompt is made from the call's published revision: every leg of the
 * call (a transfer, a consultation) in one time-ordered dialog, each turn
 * signed with the speaker side from that leg's CDR, and a marker where the
 * next leg starts, so a transferred call gets one summary of the whole call.
 *
 * The prompt was tuned on real calls with Gemma 3 4B: speaker sides and the
 * call direction come from the PBX (CDR), and the task is repeated after the
 * transcript because small models follow the last instruction best.
 */
final class SummaryPromptBuilder
{
    // Head and tail of a longer dialog are kept; ~18k characters fit the
    // 8192-token context together with the instructions and the answer.
    private const int MAX_DIALOG_CHARS = 18000;
    private const int MAX_TOKENS = 300;

    public const string CLIENT_LABEL = 'Клиент';
    public const string EMPLOYEE_LABEL = 'Сотрудник';

    private const string SYSTEM_PROMPT = 'Ты анализируешь расшифровки телефонных разговоров компании. '
        . 'Расшифровка сделана автоматически и может содержать ошибки распознавания. ';
    private const string LABELED_SPEAKERS = 'Участники подписаны по данным АТС: «Клиент» — внешний собеседник, '
        . 'остальные — сотрудники компании (указано имя). Эти подписи точные, опирайся на них. ';
    private const string UNLABELED_SPEAKERS = 'Участники обозначены как «Собеседник 1», «Собеседник 2»; '
        . 'кто из них клиент, а кто сотрудник, определи по смыслу разговора. ';
    private const string JSON_ONLY = 'Отвечай только JSON-объектом без Markdown, без заголовков и пояснений.';

    private const array LANGUAGE_NAMES = [
        'ru' => 'русском', 'en' => 'английском', 'de' => 'немецком', 'es' => 'испанском',
        'fr' => 'французском', 'it' => 'итальянском', 'pt' => 'португальском', 'zh' => 'китайском',
        'ja' => 'японском', 'ko' => 'корейском', 'ar' => 'арабском', 'tr' => 'турецком',
        'pl' => 'польском', 'uk' => 'украинском', 'nl' => 'нидерландском', 'cs' => 'чешском',
        'sv' => 'шведском', 'fi' => 'финском', 'da' => 'датском', 'el' => 'греческом',
        'hu' => 'венгерском', 'ro' => 'румынском', 'hr' => 'хорватском', 'ka' => 'грузинском',
        'az' => 'азербайджанском', 'kk' => 'казахском', 'vi' => 'вьетнамском', 'th' => 'тайском',
    ];

    /**
     * @param array<int, array<string, mixed>> $turns CallDialogService turns of the revision, in time order:
     *        text, channel, speaker (client/employee or null when unknown), speaker_name, result_id (the leg)
     * @param string $direction CallDirectionResolver direction of the first leg, '' when unknown
     * @param string $language ISO code of the conversation, '' when unknown
     * @return array<string, mixed>
     */
    public function build(array $turns, string $direction, string $language): array
    {
        $turns = array_values(array_filter($turns, static fn (mixed $turn): bool => is_array($turn)
            && is_string($turn['text'] ?? null) && trim($turn['text']) !== ''));
        $labeled = $turns !== [] && array_filter($turns, static fn (array $turn): bool => !is_string($turn['speaker'] ?? null)) === [];
        $legs = $this->legs($turns);

        $system = self::SYSTEM_PROMPT . ($labeled ? self::LABELED_SPEAKERS : self::UNLABELED_SPEAKERS) . self::JSON_ONLY;
        $user = "Расшифровка разговора:\n\n" . $this->dialog($turns, $legs) . "\n\n" . $this->task($direction, $labeled, $legs, $language);

        return [
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0,
            'max_tokens' => self::MAX_TOKENS,
            'json_schema' => [
                'type' => 'object',
                'properties' => [
                    'topic' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                ],
                'required' => ['topic', 'summary'],
            ],
        ];
    }

    /**
     * Legs in the order they join the call, each with its named employees.
     *
     * @param array<int, array<string, mixed>> $turns
     * @return array<int, array<int, string>> employee names by leg result id
     */
    private function legs(array $turns): array
    {
        $legs = [];
        foreach ($turns as $turn) {
            $leg = (int)($turn['result_id'] ?? 0);
            $legs[$leg] ??= [];
            $name = trim((string)($turn['speaker_name'] ?? ''));
            if (($turn['speaker'] ?? null) === CallDialogService::ROLE_EMPLOYEE && $name !== '' && !in_array($name, $legs[$leg], true)) {
                $legs[$leg][] = $name;
            }
        }

        return $legs;
    }

    /**
     * @param array<int, array<string, mixed>> $turns
     * @param array<int, array<int, string>> $legs
     */
    private function dialog(array $turns, array $legs): string
    {
        $anonymous = [];
        $joined = [];
        $lines = [];
        $last = null;
        foreach ($turns as $turn) {
            $leg = (int)($turn['result_id'] ?? 0);
            if ($joined !== [] && !isset($joined[$leg])) {
                $names = $legs[$leg] ?? [];
                $lines[] = '— Следующий участок звонка (перевод или консультация)'
                    . ($names === [] ? '' : ', участвует ' . implode(', ', $names)) . ' —';
                $last = null;
            }
            $joined[$leg] = true;

            $role = $turn['speaker'] ?? null;
            $speaker = match (true) {
                $role === CallDialogService::ROLE_CLIENT => self::CLIENT_LABEL,
                $role === CallDialogService::ROLE_EMPLOYEE => $this->speakerName(trim((string)($turn['speaker_name'] ?? ''))),
                // Without the CDR sides, a leg's channel is all that is known.
                default => $anonymous[$leg . '|' . (string)($turn['channel'] ?? '')] ??= 'Собеседник ' . (count($anonymous) + 1),
            };
            $text = trim(preg_replace('/\s+/u', ' ', (string)$turn['text']) ?? '');
            if ($last !== null && $lines[$last][0] === $speaker) {
                $lines[$last][1] .= ' ' . $text;
            } else {
                $lines[] = [$speaker, $text];
                $last = array_key_last($lines);
            }
        }

        $dialog = implode("\n", array_map(static fn (array|string $line): string => is_array($line) ? $line[0] . ': ' . $line[1] : $line, $lines));
        if (mb_strlen($dialog) > self::MAX_DIALOG_CHARS) {
            $half = intdiv(self::MAX_DIALOG_CHARS, 2);
            $dialog = mb_substr($dialog, 0, $half) . "\n[...]\n" . mb_substr($dialog, -$half);
        }

        return $dialog;
    }

    private function speakerName(string $name): string
    {
        return $name === '' ? self::EMPLOYEE_LABEL : $name . ' (сотрудник)';
    }

    /** @param array<int, array<int, string>> $legs */
    private function task(string $direction, bool $labeled, array $legs, string $language): string
    {
        $first = array_values($legs)[0] ?? [];
        $employee = $first === [] ? 'сотрудник' : 'сотрудник ' . implode(' и ', $first);
        [$directionText, $purpose] = match ($direction) {
            CallDirectionResolver::DIRECTION_INCOMING => [
                "Это ВХОДЯЩИЙ звонок: Клиент сам позвонил в компанию, ответил {$employee}.",
                'с чем обратился Клиент',
            ],
            CallDirectionResolver::DIRECTION_OUTGOING => [
                "Это ИСХОДЯЩИЙ звонок: {$employee} сам позвонил Клиенту.",
                'зачем сотрудник звонил Клиенту',
            ],
            CallDirectionResolver::DIRECTION_INTERNAL => [
                'Это ВНУТРЕННИЙ звонок между сотрудниками компании.',
                'о чём говорили сотрудники',
            ],
            default => ['Направление звонка неизвестно.', 'кто и зачем звонил'],
        };
        $sentences = '1-2 коротких предложения';
        if (count($legs) > 1) {
            $all = array_values(array_unique(array_merge(...array_values($legs))));
            $directionText .= ' Звонок состоит из нескольких участков: его переводили или сотрудник консультировался с коллегой'
                . ($all === [] ? '' : ' (участвовали: ' . implode(', ', $all) . ')')
                . '. Это один звонок — опиши его целиком, а не только один участок.';
            $sentences = '1-3 коротких предложения';
        }
        $languageName = self::LANGUAGE_NAMES[strtolower(substr(trim($language), 0, 2))] ?? null;
        $languageText = $languageName === null
            ? 'Пиши topic и summary на том языке, на котором идёт разговор.'
            : "Пиши topic и summary на {$languageName} языке.";
        $names = $labeled ? 'Используй имена из подписей, не путай клиента и сотрудника. ' : '';

        return "Задание. {$directionText}\n"
            . "Верни JSON с двумя полями:\n"
            . "- \"topic\": тема разговора, 3-8 слов, без точки в конце;\n"
            . "- \"summary\": {$sentences}: {$purpose} и чем закончился разговор "
            . "(о чём договорились, что пообещали, что осталось нерешённым).\n"
            . $names
            . 'Не выдумывай факты, которых нет в тексте. Если разговора по сути нет '
            . '(автоответчик, гудки, голосовое меню, тишина), так и напиши в summary. '
            . $languageText;
    }
}
