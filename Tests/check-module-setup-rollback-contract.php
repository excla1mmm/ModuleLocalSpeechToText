<?php

declare(strict_types=1);

function expectModuleSetupRollback(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$setup = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');
$package = file_get_contents(__DIR__ . '/../scripts/package-module.sh');

expectModuleSetupRollback($setup !== false, 'Cannot inspect module setup');
expectModuleSetupRollback($package !== false, 'Cannot inspect module package script');
expectModuleSetupRollback(
    str_contains($setup, 'use Modules\\ModuleLocalSpeechToText\\Lib\\Services\\ModuleDatabaseBackup;'),
    'Setup must use the verified SQLite backup service'
);
expectModuleSetupRollback(
    preg_match('/function\s+unInstallFiles\s*\(bool\s+\$keepSettings\s*=\s*false\)/', $setup) === 1,
    'STT setup must override unInstallFiles for safe subsequent upgrades'
);

$uninstallStart = strpos($setup, 'public function unInstallFiles');
$installStart = strpos($setup, 'public function installDB');
expectModuleSetupRollback($uninstallStart !== false && $installStart !== false, 'Setup lifecycle methods are missing');
$uninstallBody = substr($setup, $uninstallStart, $installStart - $uninstallStart);
expectModuleSetupRollback(
    strpos($uninstallBody, '$backup->snapshot(') < strpos($uninstallBody, 'parent::unInstallFiles(true)'),
    'Verified snapshot must be created before Core removes installed module files'
);
expectModuleSetupRollback(
    strpos($uninstallBody, 'parent::unInstallFiles(true)') < strpos($uninstallBody, '$backup->restore('),
    'Verified snapshot must replace the raw Core backup after normal Core cleanup'
);

$installBody = substr($setup, $installStart);
expectModuleSetupRollback(
    str_contains($installBody, 'createSetupDatabaseSnapshot')
        && str_contains($installBody, 'restoreSetupDatabaseSnapshot')
        && str_contains($installBody, 'recoverSetupDatabaseConnection')
        && str_contains($installBody, 'SttSourceInstance())->ensure'),
    'installDB must snapshot before migration and recover/restore on failure'
);
expectModuleSetupRollback(
    preg_match('/catch\s*\(Throwable[^)]*\).*?recoverSetupDatabaseConnection.*?restoreSetupDatabaseSnapshot/s', $installBody) === 1,
    'Setup failure must close poisoned transactions before restoring its snapshot'
);
expectModuleSetupRollback(
    str_contains($package, ".setup-database-snapshot-")
        && str_contains($package, ".module-lifecycle-")
        && str_contains($package, "-wal")
        && str_contains($package, "-shm")
        && str_contains($package, "-journal"),
    'Packages must exclude setup snapshots, lifecycle state, and SQLite sidecars'
);

echo "Module setup rollback contract OK\n";
