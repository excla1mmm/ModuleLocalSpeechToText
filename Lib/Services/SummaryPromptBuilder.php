<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;

/**
 * Builds the complete llama-server chat request that summarizes one
 * transcript. Workers send it unchanged, so prompt tuning stays in the module.
 *
 * The prompt was tuned on real calls with Gemma 3 4B: speaker sides and the
 * call direction come from the PBX (CDR), and the task is repeated after the
 * transcript because small models follow the last instruction best.
 */
final class SummaryPromptBuilder
{
    // Head and tail of a longer dialog are kept; ~12k characters fit the
    // 8192-token context together with the instructions and the answer.
    private const int MAX_DIALOG_CHARS = 12000;
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
     * @param string $transcriptJson STTResult::$transcript (segments with text and channel)
     * @param string $direction CallDirectionResolver direction, '' when unknown
     * @param array{left: string, right: string}|null $labels speaker labels by channel, null when unknown
     * @param string $language ISO code of the conversation, '' when unknown
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function build(string $transcriptJson, string $direction, ?array $labels, string $language): array
    {
        $segments = json_decode($transcriptJson === '' ? '[]' : $transcriptJson, true, 64, JSON_THROW_ON_ERROR);
        $segments = is_array($segments) ? $segments : [];

        $system = self::SYSTEM_PROMPT . ($labels === null ? self::UNLABELED_SPEAKERS : self::LABELED_SPEAKERS) . self::JSON_ONLY;
        $user = "Расшифровка разговора:\n\n" . $this->dialog($segments, $labels) . "\n\n" . $this->task($direction, $labels, $language);

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

    /** @param array<int, mixed> $segments */
    private function dialog(array $segments, ?array $labels): string
    {
        $segments = array_values(array_filter($segments, static fn (mixed $segment): bool => is_array($segment)
            && is_string($segment['text'] ?? null) && trim($segment['text']) !== ''));
        usort($segments, static fn (array $left, array $right): int => (int)($left['start_ms'] ?? 0) <=> (int)($right['start_ms'] ?? 0));

        $anonymous = [];
        $turns = [];
        foreach ($segments as $segment) {
            $side = (string)($segment['channel'] ?? $segment['speaker'] ?? '');
            $speaker = $labels === null
                ? ($anonymous[$side] ??= 'Собеседник ' . (count($anonymous) + 1))
                : $this->speakerName((string)($labels[$side] ?? ''));
            $text = trim(preg_replace('/\s+/u', ' ', (string)$segment['text']) ?? '');
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last][0] === $speaker) {
                $turns[$last][1] .= ' ' . $text;
            } else {
                $turns[] = [$speaker, $text];
            }
        }

        $dialog = implode("\n", array_map(static fn (array $turn): string => $turn[0] . ': ' . $turn[1], $turns));
        if (mb_strlen($dialog) > self::MAX_DIALOG_CHARS) {
            $half = intdiv(self::MAX_DIALOG_CHARS, 2);
            $dialog = mb_substr($dialog, 0, $half) . "\n[...]\n" . mb_substr($dialog, -$half);
        }

        return $dialog;
    }

    private function speakerName(string $label): string
    {
        if ($label === '' || $label === self::EMPLOYEE_LABEL) {
            return self::EMPLOYEE_LABEL;
        }

        return $label === self::CLIENT_LABEL ? $label : $label . ' (сотрудник)';
    }

    private function task(string $direction, ?array $labels, string $language): string
    {
        $employees = array_values(array_filter(
            $labels ?? [],
            static fn (string $label): bool => $label !== '' && $label !== self::CLIENT_LABEL && $label !== self::EMPLOYEE_LABEL
        ));
        $employee = $employees === [] ? 'сотрудник' : 'сотрудник ' . implode(' и ', array_unique($employees));
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
        $languageName = self::LANGUAGE_NAMES[strtolower(substr(trim($language), 0, 2))] ?? null;
        $languageText = $languageName === null
            ? 'Пиши topic и summary на том языке, на котором идёт разговор.'
            : "Пиши topic и summary на {$languageName} языке.";
        $names = $labels === null ? '' : 'Используй имена из подписей, не путай клиента и сотрудника. ';

        return "Задание. {$directionText}\n"
            . "Верни JSON с двумя полями:\n"
            . "- \"topic\": тема разговора, 3-8 слов, без точки в конце;\n"
            . "- \"summary\": 1-2 коротких предложения: {$purpose} и чем закончился разговор "
            . "(о чём договорились, что пообещали, что осталось нерешённым).\n"
            . $names
            . 'Не выдумывай факты, которых нет в тексте. Если разговора по сути нет '
            . '(автоответчик, гудки, голосовое меню, тишина), так и напиши в summary. '
            . $languageText;
    }
}
