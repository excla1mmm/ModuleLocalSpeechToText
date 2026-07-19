<?php

declare(strict_types=1);

function expectTrueModuleLogging(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$paths = [
    'sanitizer' => $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php',
    'writer' => $root . '/Lib/Services/LocalSpeechToTextLogWriter.php',
    'logger' => $root . '/Lib/Services/LocalSpeechToTextLogger.php',
    'reader' => $root . '/Lib/Services/ModuleLogReader.php',
    'migration' => $root . '/Lib/Services/LocalSpeechToTextLegacyLogMigration.php',
    'main' => $root . '/Lib/LocalSpeechToTextMain.php',
    'worker' => $root . '/bin/WorkerLocalSpeechToTextJobs.php',
    'baseController' => $root . '/App/Controllers/LocalSpeechToTextBaseController.php',
    'controller' => $root . '/App/Controllers/ModuleLocalSpeechToTextController.php',
    'setup' => $root . '/Setup/PbxExtensionSetup.php',
];
$sources = [];
foreach ($paths as $name => $path) {
    expectTrueModuleLogging(is_file($path), "Structured logging file is missing: {$name}");
    $sources[$name] = (string)file_get_contents($path);
}

$production = '';
foreach (['App', 'Lib', 'Setup', 'bin'] as $directory) {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $production .= (string)file_get_contents($file->getPathname());
        }
    }
}

$withoutLogger = str_replace($sources['logger'], '', $production);
expectTrueModuleLogging(
    str_contains($sources['logger'], 'SystemMessages::sysLogMsg')
        && !str_contains($withoutLogger, 'SystemMessages::sysLogMsg'),
    'only the structured logger may call MikoPBX syslog'
);
expectTrueModuleLogging(
    !str_contains($production, 'LocalSpeechToTextLogger::write(')
        && !str_contains($production, 'LocalSpeechToTextLogger::syslog(')
        && substr_count($production, 'LocalSpeechToTextLogger::event(') >= 20,
    'all operational sites must use explicit structured events'
);

foreach ([
    'worker_registered', 'job_claimed', 'recording_access_failed', 'transcription_completed',
    'transcript_completed_published', 'job_retry', 'job_failed', 'job_released',
    'stale_leases_recovered', 'job_created', 'recording_delayed', 'recording_skipped',
    'call_transcripts_published', 'retention_cleanup_completed', 'module_setup_completed',
    'worker_api_key_created', 'worker_api_key_deleted', 'settings_changed',
] as $event) {
    expectTrueModuleLogging(str_contains($production, "'{$event}'"), 'required useful event is missing: ' . $event);
}

$heartbeatStart = strpos($sources['main'], 'public static function heartbeatJob');
$heartbeatEnd = strpos($sources['main'], 'public static function releaseJob', $heartbeatStart ?: 0);
$heartbeatBody = $heartbeatStart !== false && $heartbeatEnd !== false
    ? substr($sources['main'], $heartbeatStart, $heartbeatEnd - $heartbeatStart)
    : '';
expectTrueModuleLogging(!str_contains($heartbeatBody, 'LocalSpeechToTextLogger'), 'heartbeat success/failure path must remain free of repetitive logging');
expectTrueModuleLogging(!str_contains($sources['controller'], 'Module logs requested:'), 'log retrieval must not log itself');
expectTrueModuleLogging(!str_contains($production, 'empty_poll'), 'empty queue polling must remain silent');
expectTrueModuleLogging(!str_contains($production, 'progress_updated'), 'progress updates must remain silent');

expectTrueModuleLogging(
    str_contains($sources['main'], "'worker_reported_error'")
        && !str_contains($sources['main'], "reported error for job #%d: %s"),
    'worker-controlled error text must be replaced with a stable error code'
);
expectTrueModuleLogging(
    !preg_match('/LocalSpeechToTextLogger::event\([^;]*\$(?:recordingPath|recordingFile)/s', $sources['worker'])
        && !preg_match('/LocalSpeechToTextLogger::event\([^;]*[\'\"](?:recording_path|monitor_path)[\'\"]\s*=>/s', $sources['worker']),
    'scanner log events must not include recording paths'
);
expectTrueModuleLogging(
    str_contains($sources['baseController'], "'changed_fields'")
        && !str_contains($sources['baseController'], 'Settings saved: language='),
    'settings logging must contain field names without values'
);

expectTrueModuleLogging(
    str_contains($sources['reader'], "'/module.log.1'")
        && str_contains($sources['reader'], "'/module.log'")
        && !str_contains($sources['reader'], 'RecursiveDirectoryIterator')
        && !str_contains($sources['reader'], "'/messages")
        && !str_contains($sources['reader'], 'glob('),
    'reader must read only the two canonical journal generations'
);
foreach (['entries', 'logText', 'lineCount', 'fileCount', 'generatedAt', 'period', 'availableFrom', 'truncated', 'sourceStatus', 'malformedCount'] as $field) {
    expectTrueModuleLogging(str_contains($sources['reader'], "'{$field}'"), 'reader compatibility field is missing: ' . $field);
}

expectTrueModuleLogging(
    str_contains($sources['setup'], 'LocalSpeechToTextLegacyLogMigration')
        && str_contains($sources['setup'], 'legacy_logs_migrated'),
    'setup must run and summarize safe legacy migration'
);

echo "Module logging contract OK\n";
