<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\UsersUIEmployeeNumberResolver;

require_once __DIR__ . '/../Lib/Services/UsersUIEmployeeNumberResolver.php';

function expectEmployeeNumbers(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$resolver = new UsersUIEmployeeNumberResolver(
    extensions: static fn(array $userIds): array => $userIds === [11]
        ? [
            ['userid' => 11, 'number' => '204'],
            ['userid' => 11, 'number' => '205'],
        ]
        : [],
    externalPhones: static fn(array $extensions): array => $extensions === ['204', '205']
        ? ['+7 (812) 555-01-02', '88125550103']
        : [],
);

expectEmployeeNumbers(
    $resolver->resolve([11]) === ['204', '205', '+7 (812) 555-01-02', '78125550102', '88125550103'],
    'Selected employees must expand to every extension and normalized active mobile number'
);
expectEmployeeNumbers($resolver->resolve([]) === [], 'Empty employee list must resolve without broadening access');

echo "ModuleUsersUI STT employee number resolver checks passed\n";
