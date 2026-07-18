<?php

declare(strict_types=1);

function expectModuleUpgradeIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(__DIR__ . '/Integration/check-module-upgrade-sqlite.php');
expectModuleUpgradeIntegration($source !== false, 'Production-shaped upgrade rehearsal is missing');
expectModuleUpgradeIntegration(
    str_contains($source, 'STT_UPGRADE_DATABASE')
        && str_contains($source, 'system temporary directory')
        && !str_contains($source, '/storage/usbdisk'),
    'Upgrade rehearsal must require an explicit temporary database copy and never target the live module path'
);
foreach ([
    'productionSttMigrations()',
    'same-version migration was not a no-op',
    'injected migration failure',
    'ModuleDatabaseBackup',
    'enableWalAfterVerifiedSetup',
    'unexpected row count for',
    "\$table === 'm_STTSetting' ? \$count + 2 : \$count",
    'transcript retention settings were not installed and normalized',
    'existing {$name} data changed',
    'redundant index remains',
    'verified snapshot restore did not reproduce upgraded state',
] as $contract) {
    expectModuleUpgradeIntegration(
        str_contains($source, $contract),
        'Upgrade rehearsal is missing contract: ' . $contract
    );
}

echo "Module upgrade integration contract OK\n";
