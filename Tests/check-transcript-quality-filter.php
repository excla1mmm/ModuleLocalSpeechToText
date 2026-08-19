<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\TranscriptQualityFilter;

require_once __DIR__ . '/../Lib/TranscriptQualityFilter.php';

function expectSameTranscriptQualityFilter(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function expectTrueTranscriptQualityFilter(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array<string, mixed>
 */
function segmentTranscriptQualityFilter(string $text, int $startMs = 0, int $endMs = 10000): array
{
    return [
        'start_ms' => $startMs,
        'end_ms' => $endMs,
        'text' => $text,
    ];
}

function expectRejectedTranscriptQualityFilter(string $reason, string $text, int $startMs = 0, int $endMs = 10000): void
{
    $result = TranscriptQualityFilter::filterSegments([
        segmentTranscriptQualityFilter($text, $startMs, $endMs),
    ]);

    expectSameTranscriptQualityFilter([], $result['segments'], "Segment must be rejected for {$reason}");
    expectSameTranscriptQualityFilter(1, $result['stats']['raw_segments'], "Raw counter mismatch for {$reason}");
    expectSameTranscriptQualityFilter(0, $result['stats']['submitted_segments'], "Submitted counter mismatch for {$reason}");
    expectSameTranscriptQualityFilter(1, $result['stats']['rejected_segments'], "Rejected counter mismatch for {$reason}");
    expectTrueTranscriptQualityFilter(
        in_array($reason, $result['stats']['reasons'], true),
        "Expected rejection reason {$reason}"
    );
}

function expectRejectedForRussianTranscriptQualityFilter(string $reason, string $text, int $startMs = 0, int $endMs = 10000): void
{
    $result = TranscriptQualityFilter::filterSegments([
        segmentTranscriptQualityFilter($text, $startMs, $endMs),
    ], 'ru');

    expectSameTranscriptQualityFilter([], $result['segments'], "Russian segment must be rejected for {$reason}");
    expectSameTranscriptQualityFilter(1, $result['stats']['rejected_segments'], "Russian rejected counter mismatch for {$reason}");
    expectTrueTranscriptQualityFilter(
        in_array($reason, $result['stats']['reasons'], true),
        "Expected Russian rejection reason {$reason}"
    );
}

function expectAcceptedForRussianTranscriptQualityFilter(string $text, string $message, int $startMs = 0, int $endMs = 10000): void
{
    $result = TranscriptQualityFilter::filterSegments([
        segmentTranscriptQualityFilter($text, $startMs, $endMs),
    ], 'ru');

    expectSameTranscriptQualityFilter(1, $result['stats']['submitted_segments'], $message . ': submitted counter mismatch');
    expectSameTranscriptQualityFilter(0, $result['stats']['rejected_segments'], $message . ': rejected counter mismatch');
    expectSameTranscriptQualityFilter($text, $result['segments'][0]['text'] ?? null, $message . ': text must be preserved');
}

/**
 * @return array<int, string>
 */
function knownWhisperHallucinationsTranscriptQualityFilter(): array
{
    $catalog = <<<'TEXT'
ВЕСЕЛАЯ МУЗЫКА
СПОКОЙНАЯ МУЗЫКА
ГРУСТНАЯ МЕЛОДИЯ
ЛИРИЧЕСКАЯ МУЗЫКА
ДИНАМИЧНАЯ МУЗЫКА
ТАИНСТВЕННАЯ МУЗЫКА
ТОРЖЕСТВЕННАЯ МУЗЫКА
ИНТРИГУЮЩАЯ МУЗЫКА
НАПРЯЖЕННАЯ МУЗЫКА
ПЕЧАЛЬНАЯ МУЗЫКА
ТРЕВОЖНАЯ МУЗЫКА
МУЗЫКАЛЬНАЯ ЗАСТАВКА
ПЕРЕСТРЕЛКА
ГУДОК ПОЕЗДА
РЁВ МОТОРА
ШУМ ДВИГАТЕЛЯ
СИГНАЛ АВТОМОБИЛЯ
ЛАЙ СОБАК
ПЕС ЛАЕТ
КАШЕЛЬ
ВЫСТРЕЛЫ
ШУМ ДОЖДЯ
ПЕСНЯ
ПО ГРОМКОГОВОРИЧЕСКОМ ЯЗЫКЕ
ПО ГРОМКОГОВОРИТЕЛЮ
ВЗРЫВ
ШУМ МОТОРА
ПЛЕСК ВОДЫ
ГУДОК АВТОМОБИЛЯ
ЛАЙ СОБАКИ
ПО ТВ.
АПЛОДИСМЕНТЫ
ГОРОДСКОЙ ШУМ
ПОЛИЦИЯ
ГОРОДСКОЙ ГУДОК
СИГНАЛ МАШИНЫ
СМЕХ
СТУК В ДВЕРЬ
ААААААААААААААААААААА
ПОЛИЦЕЙСКАЯ СИРЕНА
ЗВОНОК В ДВЕРЬ
Спасибо за субтитры!
Субтитры добавил DimaTorzok
Субтитры подогнал «Симон»!
Редактор субтитров М.Лосева Корректор А.Егорова
Редактор субтитров А.Синецкая Корректор А.Егорова
Редактор субтитров Т.Горелова Корректор А.Егорова
Редактор субтитров Е.Жукова Корректор А.Егорова
Редактор субтитров А.Семкин Корректор А.Егорова
Редактор субтитров А.Захарова Корректор А.Егорова
Смотрите продолжение во второй части видео.
Смотрите продолжение в следующей части.
Смотрите продолжение в следующей части видео.
Смотрите продолжение в 4 части видео.
Смотрите продолжение в следующей серии...
Смотрите продолжение во второй части.
Продолжение следует...
ПОДПИШИСЬ НА КАНАЛ
ПОДПИШИСЬ!
ПОДПИШИСЬ
🦜
💥
😎
🤨
🤔
Поехали!
Поехали.
Девушки отдыхают...
TEXT;

    return array_values(array_unique(preg_split('/\R/u', $catalog) ?: []));
}

$accepted = TranscriptQualityFilter::filterSegments([
    segmentTranscriptQualityFilter('Customer asked to call back tomorrow.', 0, 4000),
    segmentTranscriptQualityFilter('Agent confirmed the next steps.', 4000, 9000),
]);
expectSameTranscriptQualityFilter(2, $accepted['stats']['raw_segments'], 'Accepted raw segment count mismatch');
expectSameTranscriptQualityFilter(2, $accepted['stats']['submitted_segments'], 'Accepted submitted segment count mismatch');
expectSameTranscriptQualityFilter(0, $accepted['stats']['rejected_segments'], 'Accepted rejected segment count mismatch');
expectSameTranscriptQualityFilter([], $accepted['stats']['reasons'], 'Accepted transcript must not report filter reasons');
expectSameTranscriptQualityFilter(
    'Customer asked to call back tomorrow.',
    $accepted['segments'][0]['text'] ?? null,
    'Accepted transcript text must be preserved'
);

$acceptedEnglishWithoutLanguageHint = TranscriptQualityFilter::filterSegments([
    segmentTranscriptQualityFilter('Hello, please call me back tomorrow.', 0, 3000),
]);
expectSameTranscriptQualityFilter(
    1,
    $acceptedEnglishWithoutLanguageHint['stats']['submitted_segments'],
    'English text must remain accepted when no language hint is provided'
);

expectRejectedTranscriptQualityFilter('empty_text', '   ');
expectRejectedTranscriptQualityFilter('replacement_character', "Audio decoder returned \u{FFFD} bytes");
expectRejectedTranscriptQualityFilter(
    'text_density',
    implode(' ', array_map(static fn (int $index): string => 'word' . $index, range(1, 45))),
    0,
    100
);
expectRejectedTranscriptQualityFilter('hallucination_marker', 'Generated subtitles by an unrelated service.');
foreach (knownWhisperHallucinationsTranscriptQualityFilter() as $hallucination) {
    expectRejectedTranscriptQualityFilter('hallucination_marker', $hallucination);
}
foreach ([
    'Музыка',
    'Спасибо за просмотр!',
    'До новых встреч!',
    'Встреча субтитров А.Семкин Корректор А.Егорова',
] as $hallucination) {
    expectRejectedTranscriptQualityFilter('hallucination_marker', $hallucination);
}
foreach ([
    'Спасибо за внимание!',
    'Ставьте лайк!',
    'Не забудьте подписаться!',
    'Подписывайтесь на канал!',
    'Thanks for watching!',
    'Thank you for watching!',
    'Please subscribe to the channel!',
    'See you next time!',
    'To be continued...',
] as $outro) {
    expectRejectedTranscriptQualityFilter('hallucination_marker', $outro);
}
foreach ([
    'Я включил субтитры на телевизоре.',
    'Для субтитров нужен отдельный редактор.',
    'В офисе играет спокойная музыка.',
    'Ну что, поехали оформлять заказ.',
    'Корректор согласовал текст фильма с автором.',
    'Спасибо за просмотр документов, я перезвоню завтра.',
    'До новых встреч по проекту осталось два дня.',
] as $legitimateSpeech) {
    expectAcceptedForRussianTranscriptQualityFilter(
        $legitimateSpeech,
        'Hallucination vocabulary inside natural speech must remain accepted'
    );
}
expectRejectedTranscriptQualityFilter('symbol_only', '...');
expectRejectedTranscriptQualityFilter('symbol_only', '🧪');
expectRejectedTranscriptQualityFilter('punctuation_flood', str_repeat('?!', 12));
expectRejectedTranscriptQualityFilter('single_letter', 'a');
expectRejectedTranscriptQualityFilter('repeated_character', str_repeat('b', 18));
expectRejectedTranscriptQualityFilter('repeated_substring', str_repeat('ab', 10));
expectRejectedTranscriptQualityFilter('repeated_token', str_repeat('hello ', 8));
expectRejectedTranscriptQualityFilter('repeated_ngram', 'intro ' . str_repeat('alpha beta ', 8) . 'outro');

$consecutiveDuplicates = TranscriptQualityFilter::filterSegments([
    segmentTranscriptQualityFilter('Спасибо.', 0, 1000) + ['channel' => 'left', 'speaker' => 'customer'],
    segmentTranscriptQualityFilter('Спасибо!', 1000, 2000) + ['channel' => 'left', 'speaker' => 'customer'],
    segmentTranscriptQualityFilter('СПАСИБО', 2000, 3000) + ['channel' => 'left', 'speaker' => 'customer'],
    segmentTranscriptQualityFilter('Спасибо.', 3000, 4000) + ['channel' => 'left', 'speaker' => 'customer'],
]);
expectSameTranscriptQualityFilter(2, count($consecutiveDuplicates['segments']), 'Only two short consecutive duplicates may remain');
expectSameTranscriptQualityFilter(2, $consecutiveDuplicates['stats']['rejected_segments'], 'Duplicate rejection counter mismatch');
expectTrueTranscriptQualityFilter(
    in_array('consecutive_duplicate', $consecutiveDuplicates['stats']['reasons'], true),
    'Consecutive duplicate rejection reason must be reported'
);

$differentSpeakers = TranscriptQualityFilter::filterSegments([
    segmentTranscriptQualityFilter('Угу.', 0, 1000) + ['channel' => 'left', 'speaker' => 'customer'],
    segmentTranscriptQualityFilter('Угу.', 1000, 2000) + ['channel' => 'right', 'speaker' => 'agent'],
    segmentTranscriptQualityFilter('Угу.', 2000, 3000) + ['channel' => 'left', 'speaker' => 'customer'],
]);
expectSameTranscriptQualityFilter(3, count($differentSpeakers['segments']), 'Duplicate runs must not cross channel or speaker identity');

expectRejectedForRussianTranscriptQualityFilter('wrong_script', 'Alô?', 5150, 6150);
expectRejectedForRussianTranscriptQualityFilter('wrong_script', 'Hvað er þetta?', 232700, 234340);
expectRejectedForRussianTranscriptQualityFilter('wrong_script', '네, 네.', 554270, 555310);
expectRejectedForRussianTranscriptQualityFilter('wrong_script', 'Mm-hmm.', 31460, 33190);
expectRejectedForRussianTranscriptQualityFilter('wrong_script', 'Second, it is focused on', 1000, 3000);

expectAcceptedForRussianTranscriptQualityFilter(
    'Да, давайте сразу на тот компьютер по AnyDesk попробуем.',
    'Russian technical brand terms must remain accepted',
    0,
    4200
);
expectAcceptedForRussianTranscriptQualityFilter(
    'Единственное, что VPN.',
    'Russian technical acronyms must remain accepted',
    0,
    2200
);
expectAcceptedForRussianTranscriptQualityFilter(
    'Там, WhatsApp, Telegram.',
    'Russian messenger brand terms must remain accepted',
    0,
    2600
);
expectAcceptedForRussianTranscriptQualityFilter(
    'На docs.telefon1s.ru есть инструкция по FreePBX.',
    'Russian domain and PBX terms must remain accepted',
    0,
    4200
);
expectAcceptedForRussianTranscriptQualityFilter(
    'Клиент сказал: please review FreePBX dashboard metrics before release and send report tomorrow.',
    'Long mixed Russian/Latin segments must remain accepted',
    0,
    12000
);

$trimmedSuffix = TranscriptQualityFilter::filterSegments([
    segmentTranscriptQualityFilter('meaningful prefix before issue ' . str_repeat('noise ', 8)),
]);
expectSameTranscriptQualityFilter(1, $trimmedSuffix['stats']['submitted_segments'], 'Trimmed suffix segment must remain submitted');
expectSameTranscriptQualityFilter(1, $trimmedSuffix['stats']['trimmed_segments'], 'Trimmed suffix counter mismatch');
expectTrueTranscriptQualityFilter(
    in_array('trimmed_repeated_token', $trimmedSuffix['stats']['reasons'], true),
    'Trimmed suffix must expose the trimmed_repeated_token reason'
);
expectSameTranscriptQualityFilter(
    'meaningful prefix before issue',
    $trimmedSuffix['segments'][0]['text'] ?? null,
    'Repeated suffix must be removed from otherwise meaningful text'
);

$trimmedPrefix = TranscriptQualityFilter::filterSegments([
    segmentTranscriptQualityFilter(str_repeat('noise ', 8) . 'real customer answer'),
]);
expectSameTranscriptQualityFilter(1, $trimmedPrefix['stats']['submitted_segments'], 'Trimmed prefix segment must remain submitted');
expectSameTranscriptQualityFilter(1, $trimmedPrefix['stats']['trimmed_segments'], 'Trimmed prefix counter mismatch');
expectTrueTranscriptQualityFilter(
    in_array('trimmed_leading_repeated_token', $trimmedPrefix['stats']['reasons'], true),
    'Trimmed prefix must expose the trimmed_leading_repeated_token reason'
);
expectSameTranscriptQualityFilter(
    'real customer answer',
    $trimmedPrefix['segments'][0]['text'] ?? null,
    'Repeated prefix must be removed from otherwise meaningful text'
);

$dedupedReasons = TranscriptQualityFilter::filterSegments([
    segmentTranscriptQualityFilter(' '),
    segmentTranscriptQualityFilter("\t"),
]);
expectSameTranscriptQualityFilter(
    ['empty_text'],
    $dedupedReasons['stats']['reasons'],
    'Repeated rejection reasons must be deduplicated'
);

echo "Transcript quality filter contract OK\n";
