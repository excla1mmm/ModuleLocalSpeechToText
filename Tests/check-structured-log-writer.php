<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogSanitizer;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogWriter;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogParser;

function expectStructuredWriter(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeStructuredWriterFixture(string $directory): void
{
    if (is_link($directory)) {
        unlink($directory);
        return;
    }
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }
        if ($item->isDir() && !$item->isLink()) {
            removeStructuredWriterFixture($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

$root = dirname(__DIR__);
$sanitizerPath = $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php';
$writerPath = $root . '/Lib/Services/LocalSpeechToTextLogWriter.php';
$loggerPath = $root . '/Lib/Services/LocalSpeechToTextLogger.php';

expectStructuredWriter(is_file($sanitizerPath), 'Structured log sanitizer is missing');
expectStructuredWriter(is_file($writerPath), 'Structured log writer is missing');
expectStructuredWriter(is_file($loggerPath), 'Structured logger is missing');
expectStructuredWriter(
    str_contains((string)file_get_contents($writerPath), 'chgrp('),
    'journal writer must align journal files with the directory group'
);

require_once $sanitizerPath;
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogFormatter.php';
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogParser.php';
require_once $writerPath;
require_once $loggerPath;

$fixtureRoot = sys_get_temp_dir() . '/local-stt-log-writer-' . bin2hex(random_bytes(6));
$journalDir = $fixtureRoot . '/journal';
$rotationDir = $fixtureRoot . '/rotation';
$groupAlignedDir = $fixtureRoot . '/group-aligned';
$groupAlignedRotationDir = $fixtureRoot . '/group-aligned-rotation';
$concurrentDir = $fixtureRoot . '/concurrent';
$failureDir = $fixtureRoot . '/failure';
$unsafeDir = $fixtureRoot . '/unsafe';
$outsideDir = $fixtureRoot . '/outside';
$linkedDir = $fixtureRoot . '/linked-directory';
$outsideFile = $fixtureRoot . '/outside-target.log';

try {
    mkdir($fixtureRoot, 0750, true);
    $mirrored = [];
    $writer = new LocalSpeechToTextLogWriter($journalDir, 50_000);
    $logger = new LocalSpeechToTextLogger(
        $writer,
        static function (string $ident, string $message, int $level) use (&$mirrored): void {
            $mirrored[] = compact('ident', 'message', 'level');
        }
    );

    $logger->writeEvent('setup', 'module_setup_completed', 'Module setup completed', LOG_NOTICE);
    $logger->writeEvent('queue', 'job_created', 'Transcription job created', LOG_INFO, ['job_id' => 42]);
    $logger->writeEvent(
        'worker',
        'job_retry',
        "Authorization: Bearer bearer-secret Authorization=Basic basic-secret "
            . "token=plain-token api_key=api-secret worker_api_key=worker-secret client_secret=client-secret "
            . 'password=hunter2 {"credential":"json-secret","prompt":"private prompt"} '
            . "transcript: private transcript\n/Users/operator/records/call.wav C:\\Recordings\\call.wav "
            . '+7 (999) 123-45-67',
        LOG_WARNING,
        [
            'job_id' => 42,
            'api_key_id' => 17,
            'lease_token' => 'secret-lease',
            'authorization' => 'Basic context-secret',
            'prompt_text' => 'context prompt',
            'transcript' => 'context transcript',
            'payload' => 'raw handler payload',
            'recording_path' => '/var/spool/asterisk/monitor/call.wav',
            'worker_uid' => "worker-01\nforged",
            'nested' => ['must' => 'drop'],
        ]
    );
    $logger->writeEvent('storage', 'database_operation_failed', 'Database operation failed', LOG_ERR, [
        'error_code' => 'database_locked',
    ]);

    $activeLines = file($journalDir . '/module.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expectStructuredWriter(is_array($activeLines) && count($activeLines) === 4, 'each event must be one syslog record');
    foreach ($activeLines as $line) {
        $entry = LocalSpeechToTextSyslogParser::parse($line);
        expectStructuredWriter(
            is_array($entry),
            'module journal record must follow the canonical syslog grammar'
        );
        expectStructuredWriter(strtotime((string)$entry['timestamp']) !== false, 'event timestamp must be parseable');
        expectStructuredWriter(
            in_array($entry['level'], ['debug', 'info', 'notice', 'warning', 'error'], true),
            'event level must belong to the allowed set'
        );
        expectStructuredWriter(is_array($entry['context']), 'event context must always be an object-like array');
    }
    expectStructuredWriter(
        str_contains($activeLines[0], ' daemon.notice ModuleLocalSpeechToText['),
        'record must expose the MikoPBX syslog header'
    );
    expectStructuredWriter(!str_contains($activeLines[0], ' context='), 'empty context must be omitted');
    expectStructuredWriter(
        LocalSpeechToTextLogSanitizer::errorCode(new RuntimeException('secret detail')) === 'runtime_exception',
        'exception class names must become stable snake-case error codes'
    );
    $unicodeProbe = LocalSpeechToTextLogSanitizer::message("trusted\xFF\u{202E}spoof");
    expectStructuredWriter(
        preg_match('//u', $unicodeProbe) === 1 && !str_contains($unicodeProbe, "\u{202E}"),
        'invalid UTF-8 and Unicode formatting controls must not survive sanitization'
    );
    expectStructuredWriter(str_contains($combined = implode("\n", $activeLines), '"api_key_id":17'), 'non-secret key identifiers must remain observable');
    expectStructuredWriter(count($mirrored) === 2, 'only warning and error events may mirror to syslog');

    $combined .= "\n" . implode("\n", array_column($mirrored, 'message'));
    foreach ([
        'bearer-secret', 'basic-secret', 'plain-token', 'api-secret', 'worker-secret', 'client-secret',
        'hunter2', 'json-secret', 'private prompt', 'private transcript', 'secret-lease', 'context-secret',
        'context prompt', 'context transcript', 'raw handler payload', '/Users/operator', '/var/spool',
        'C:\\Recordings', '999) 123', "\nforged",
    ] as $forbidden) {
        expectStructuredWriter(!str_contains($combined, $forbidden), 'sensitive log data leaked: ' . $forbidden);
    }
    expectStructuredWriter(!str_contains($combined, '"nested"'), 'nested context must be omitted');

    expectStructuredWriter((fileperms($journalDir) & 0777) === 0750, 'journal directory mode must be 0750');
    expectStructuredWriter((fileperms($journalDir . '/module.log') & 0777) === 0640, 'journal file mode must be 0640');
    expectStructuredWriter((fileperms($journalDir . '/.module-log.lock') & 0777) === 0640, 'lock file mode must be 0640');

    $primaryGroup = function_exists('posix_getegid') ? posix_getegid() : null;
    $alternateGroups = function_exists('posix_getgroups') ? posix_getgroups() : [];
    $directoryGroup = is_int($primaryGroup)
        ? array_values(array_filter($alternateGroups, static fn (int $group): bool => $group !== $primaryGroup))[0] ?? null
        : null;
    if (is_int($directoryGroup)) {
        mkdir($groupAlignedDir, 0750, true);
        expectStructuredWriter(chgrp($groupAlignedDir, $directoryGroup), 'group-aligned journal fixture must use a non-primary group');
        $groupAlignedWriter = new LocalSpeechToTextLogWriter($groupAlignedDir, 900);
        expectStructuredWriter($groupAlignedWriter->append('{"probe":"active"}'), 'group-aligned active journal append must succeed');
        expectStructuredWriter(chgrp($groupAlignedDir . '/module.log', $primaryGroup), 'active journal fixture must accept an incorrect group');
        expectStructuredWriter($groupAlignedWriter->append('{"probe":"realign"}'), 'group-aligned active journal realignment append must succeed');
        expectStructuredWriter(
            filegroup($groupAlignedDir . '/module.log') === filegroup($groupAlignedDir),
            'active journal group must match its directory group'
        );

        mkdir($groupAlignedRotationDir, 0750, true);
        expectStructuredWriter(chgrp($groupAlignedRotationDir, $directoryGroup), 'group-aligned rotation fixture must use a non-primary group');
        $groupAlignedRotationWriter = new LocalSpeechToTextLogWriter($groupAlignedRotationDir, 80);
        expectStructuredWriter($groupAlignedRotationWriter->append(str_repeat('a', 50)), 'group-aligned rotation first append must succeed');
        expectStructuredWriter(chgrp($groupAlignedRotationDir . '/module.log', $primaryGroup), 'rotation journal fixture must accept an incorrect group');
        expectStructuredWriter($groupAlignedRotationWriter->append(str_repeat('b', 50)), 'group-aligned rotation second append must succeed');
        foreach (['module.log', 'module.log.1'] as $journalFile) {
            expectStructuredWriter(
                filegroup($groupAlignedRotationDir . '/' . $journalFile) === filegroup($groupAlignedRotationDir),
                "rotated journal {$journalFile} group must match its directory group"
            );
        }
    }

    $rotationLogger = new LocalSpeechToTextLogger(new LocalSpeechToTextLogWriter($rotationDir, 900));
    for ($index = 0; $index < 30; $index++) {
        $rotationLogger->writeEvent('queue', 'rotation_probe', str_repeat((string)($index % 10), 80), LOG_INFO, [
            'sequence' => $index,
        ]);
    }
    $journalFiles = glob($rotationDir . '/module.log*') ?: [];
    sort($journalFiles);
    expectStructuredWriter(
        array_map('basename', $journalFiles) === ['module.log', 'module.log.1'],
        'writer must retain exactly module.log and module.log.1'
    );
    expectStructuredWriter(filesize($rotationDir . '/module.log') <= 900, 'active journal exceeds configured limit');
    expectStructuredWriter(filesize($rotationDir . '/module.log.1') <= 900, 'previous journal exceeds configured limit');
    expectStructuredWriter(!is_file($rotationDir . '/module.log.2'), 'second numbered rotation must never be created');
    expectStructuredWriter((glob($rotationDir . '/*.gz') ?: []) === [], 'compressed rotations must never be created');

    mkdir($failureDir, 0750, true);
    mkdir($failureDir . '/.module-log.lock', 0750);
    $failureWriter = new LocalSpeechToTextLogWriter($failureDir);
    $writerThrew = false;
    $loggerThrew = false;
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });
    try {
        try {
            $appendResult = $failureWriter->append('{"probe":true}');
        } catch (Throwable) {
            $writerThrew = true;
            $appendResult = null;
        }
        try {
            (new LocalSpeechToTextLogger($failureWriter))->writeEvent(
                'queue',
                'journal_failure_probe',
                'Journal failure probe'
            );
        } catch (Throwable) {
            $loggerThrew = true;
        }
    } finally {
        restore_error_handler();
    }
    expectStructuredWriter(!$writerThrew && $appendResult === false, 'writer failures must become false without throwing');
    expectStructuredWriter(!$loggerThrew, 'logging failures must never escape into queue or API control flow');

    if (function_exists('pcntl_fork') && function_exists('pcntl_waitpid')) {
        $children = [];
        for ($child = 0; $child < 4; $child++) {
            $pid = pcntl_fork();
            expectStructuredWriter($pid !== -1, 'unable to fork concurrent writer fixture');
            if ($pid === 0) {
                $childLogger = new LocalSpeechToTextLogger(new LocalSpeechToTextLogWriter($concurrentDir, 1_000_000));
                for ($sequence = 0; $sequence < 40; $sequence++) {
                    $childLogger->writeEvent('queue', 'concurrent_probe', 'Concurrent record', LOG_INFO, [
                        'process' => $child,
                        'sequence' => $sequence,
                    ]);
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expectStructuredWriter(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'concurrent writer child failed');
        }
        $concurrentLines = file($concurrentDir . '/module.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expectStructuredWriter(is_array($concurrentLines) && count($concurrentLines) === 160, 'concurrent append lost or merged records');
        foreach ($concurrentLines as $line) {
            expectStructuredWriter(
                LocalSpeechToTextSyslogParser::parse($line) !== null,
                'concurrent append produced a malformed syslog record'
            );
        }
    }

    mkdir($unsafeDir, 0750, true);
    file_put_contents($outsideFile, "outside\n");
    symlink($outsideFile, $unsafeDir . '/module.log');
    $unsafeWriter = new LocalSpeechToTextLogWriter($unsafeDir);
    expectStructuredWriter(!$unsafeWriter->append('{"safe":true}'), 'writer must reject an active-journal symlink');
    expectStructuredWriter(file_get_contents($outsideFile) === "outside\n", 'writer followed an active-journal symlink');

    mkdir($outsideDir, 0750, true);
    symlink($outsideDir, $linkedDir);
    expectStructuredWriter(
        !(new LocalSpeechToTextLogWriter($linkedDir))->append('{"safe":true}'),
        'writer must reject a symlinked journal directory'
    );
    expectStructuredWriter((glob($outsideDir . '/*') ?: []) === [], 'writer followed a symlinked journal directory');
} finally {
    removeStructuredWriterFixture($linkedDir);
    removeStructuredWriterFixture($fixtureRoot);
}

echo "Structured log writer OK\n";
