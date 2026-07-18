<?php

declare(strict_types=1);

function expectTransactionAdoption(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$main = (string)file_get_contents($root . '/Lib/LocalSpeechToTextMain.php');
$settings = (string)file_get_contents($root . '/Lib/Services/WorkerProcessingSettingsService.php');
$coreApiKeys = (string)file_get_contents($root . '/Lib/Services/WorkerApiKeyService.php');

expectTransactionAdoption(
    str_contains($main, 'use Modules\\ModuleLocalSpeechToText\\Lib\\Services\\ModuleTransaction;')
        && str_contains($main, '(new ModuleTransaction())->run('),
    'submitResult must use ModuleTransaction'
);
expectTransactionAdoption(
    str_contains($settings, '(new ModuleTransaction())->run('),
    'processing settings must use ModuleTransaction'
);

foreach (['BEGIN IMMEDIATE', "execute('COMMIT')", "execute('ROLLBACK')"] as $forbidden) {
    expectTransactionAdoption(
        !str_contains($settings, $forbidden),
        "processing settings still contains raw transaction SQL: {$forbidden}"
    );
}

expectTransactionAdoption(
    !str_contains($main, '$connection->begin()')
        && !str_contains($main, '$connection->commit()')
        && !str_contains($main, '$connection->rollback()'),
    'submitResult still manages the adapter transaction directly'
);

expectTransactionAdoption(
    str_contains($coreApiKeys, 'beginCoreApiKeyTransaction')
        && !str_contains($coreApiKeys, 'ModuleTransaction'),
    'Core API-key compensation transaction must remain separate from the module helper'
);

echo "Module transaction adoption OK\n";
