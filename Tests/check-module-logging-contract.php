<?php

declare(strict_types=1);

function expectTrueModuleLogging(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$logger = file_get_contents(__DIR__ . '/../Lib/Services/LocalSpeechToTextLogger.php');
$main = file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php');
$worker = file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
$baseController = file_get_contents(__DIR__ . '/../App/Controllers/LocalSpeechToTextBaseController.php');
$controller = file_get_contents(__DIR__ . '/../App/Controllers/ModuleLocalSpeechToTextController.php');
$setup = file_get_contents(__DIR__ . '/../Setup/PbxExtensionSetup.php');

expectTrueModuleLogging($logger !== false, 'Cannot read LocalSpeechToTextLogger.php');
expectTrueModuleLogging($main !== false, 'Cannot read LocalSpeechToTextMain.php');
expectTrueModuleLogging($worker !== false, 'Cannot read WorkerLocalSpeechToTextJobs.php');
expectTrueModuleLogging($baseController !== false, 'Cannot read LocalSpeechToTextBaseController.php');
expectTrueModuleLogging($controller !== false, 'Cannot read ModuleLocalSpeechToTextController.php');
expectTrueModuleLogging($setup !== false, 'Cannot read PbxExtensionSetup.php');

expectTrueModuleLogging(
    str_contains($logger, "MODULE_UNIQUE_ID = 'ModuleLocalSpeechToText'")
        && str_contains($logger, "Directories::getDir(Directories::CORE_LOGS_DIR)")
        && str_contains($logger, "self::MODULE_UNIQUE_ID")
        && str_contains($logger, "'.log'"),
    'Logger must write .log files under the module log directory'
);
expectTrueModuleLogging(
    str_contains($logger, "date('Y-m-d H:i:s')")
        && str_contains($logger, 'levelName')
        && str_contains($logger, 'normalizeMessage'),
    'Logger lines must use the timestamp format parsed by the log viewer'
);
expectTrueModuleLogging(
    str_contains($logger, 'SystemMessages::sysLogMsg')
        && str_contains($logger, 'public static function syslog'),
    'Logger must keep syslog mirroring for operational events'
);
expectTrueModuleLogging(
    !str_contains($main, 'SystemMessages::sysLogMsg')
        && !str_contains($worker, 'SystemMessages::sysLogMsg')
        && str_contains($main, 'LocalSpeechToTextLogger')
        && str_contains($worker, 'LocalSpeechToTextLogger::syslog'),
    'API and worker events must go through the module logger'
);
expectTrueModuleLogging(
    str_contains($baseController, 'Settings saved:')
        && str_contains($controller, 'Module logs requested:')
        && str_contains($controller, 'Worker API key created:')
        && str_contains($setup, 'Database setup completed'),
    'Web UI and setup actions must write module log entries'
);

echo "Module logging contract OK\n";
