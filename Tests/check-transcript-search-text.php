<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\TranscriptSearchText;

require_once __DIR__ . '/../Lib/Services/TranscriptSearchText.php';

function expectTranscriptSearch(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$transcript = json_encode([
    ['text' => 'Ёлка уже готова'],
    ['text' => 'Проверим заказ № 42'],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

expectTranscriptSearch(
    'елка уже готова проверим заказ № 42',
    TranscriptSearchText::fromTranscript($transcript),
    'Stored search text must contain normalized speech without JSON structure'
);
expectTranscriptSearch(
    '"елка"* AND "заказ"*',
    TranscriptSearchText::query('  ЁЛКА + заказ*  '),
    'FTS query must normalize, tokenize, and safely quote user input'
);
expectTranscriptSearch('', TranscriptSearchText::query(' +-*() '), 'Punctuation-only input must not create an FTS expression');

echo "Transcript search text checks passed\n";
