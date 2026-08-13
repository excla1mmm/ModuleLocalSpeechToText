<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLegacyLogMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogWriter;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogParser;

function expectLegacyLogMigration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeLegacyLogMigrationFixture(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item instanceof SplFileInfo && $item->isDir() && !$item->isLink()) {
            removeLegacyLogMigrationFixture($item->getPathname());
        } elseif ($item instanceof SplFileInfo) {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

$root = dirname(__DIR__);
$sanitizerPath = $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php';
$writerPath = $root . '/Lib/Services/LocalSpeechToTextLogWriter.php';
$migrationPath = $root . '/Lib/Services/LocalSpeechToTextLegacyLogMigration.php';
expectLegacyLogMigration(is_file($sanitizerPath), 'Structured sanitizer is missing');
expectLegacyLogMigration(is_file($writerPath), 'Structured writer is missing');
expectLegacyLogMigration(is_file($migrationPath), 'Legacy log migration is missing');
require_once $sanitizerPath;
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogFormatter.php';
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogParser.php';
require_once $writerPath;
require_once $migrationPath;

$fixtureRoot = sys_get_temp_dir() . '/local-stt-log-migration-' . bin2hex(random_bytes(6));
$journalDir = $fixtureRoot . '/journal';
$failedDir = $fixtureRoot . '/failed';
$oversizedDir = $fixtureRoot . '/oversized';
$outsideTarget = $fixtureRoot . '/outside.log';

try {
    mkdir($journalDir, 0750, true);
    $active = json_encode([
        'ts' => date(DATE_RFC3339),
        'level' => 'info',
        'component' => 'setup',
        'event' => 'active_probe',
        'message' => 'Active journal remains',
        'context' => [],
    ], JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($journalDir . '/module.log', $active);
    $existingPrevious = \Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogFormatter::format(
        'queue',
        'existing_history',
        'Existing history',
        LOG_INFO,
        ['sequence' => 1],
        strtotime('2026-07-19 07:59:00 UTC'),
        44
    );
    file_put_contents($journalDir . '/module.log.1', $existingPrevious . "\n" . $existingPrevious . "\n");

    $knownSources = [
        'api.log',
        'web-ui.log',
        'setup.log',
        'workerlocalspeechtotextjobs.log',
        'localspeechtotextmain.log.2',
        'logger.log.7',
    ];
    foreach ($knownSources as $index => $source) {
        file_put_contents(
            $journalDir . '/' . $source,
            sprintf(
                "2026-07-19 08:%02d:00 %s source=%s token=legacy-secret /Users/operator/call.wav +79991234567 "
                    . 'password="two word secret"; secret: two word value; '
                    . '{"credential":"escaped \\"quote\\" secret"}; src_num=1234; '
                    . "/srv/private/call.wav; /Library/Application Support/private call.wav; "
                    . "\\\\server\\share\\private call.wav; file=/srv/assigned/private.wav; "
                    . 'filename="/Library/Application Support/assigned private.wav"; '
                    . 'open(/srv/open-call/private.wav); {"payload":{"text":"nested private transcript"}}; '
                    . "worker=trusted\u{202E}spoof\n",
                $index,
                $index === 0 ? 'ALERT' : 'WARNING',
                $source
            )
        );
        touch($journalDir . '/' . $source, time() - 100 + $index);
    }
    file_put_contents($journalDir . '/administrator.log', "administrator diagnostic must stay\n");
    file_put_contents($journalDir . '/api-debug.log', "unknown lookalike must stay\n");
    file_put_contents($journalDir . '/notes.txt', "notes must stay\n");
    mkdir($journalDir . '/nested', 0750, true);
    file_put_contents($journalDir . '/nested/api.log', "nested must stay\n");
    file_put_contents($outsideTarget, "outside target\n");
    symlink($outsideTarget, $journalDir . '/linked.log');

    $migration = new LocalSpeechToTextLegacyLogMigration($journalDir, new LocalSpeechToTextLogWriter($journalDir));
    $result = $migration->run();
    expectLegacyLogMigration($result === ['imported' => 6, 'removed' => 6, 'skipped' => 0], 'all and only known logs must migrate');
    expectLegacyLogMigration(file_get_contents($journalDir . '/module.log') === $active, 'migration must not change active journal');
    foreach ($knownSources as $source) {
        expectLegacyLogMigration(!file_exists($journalDir . '/' . $source), 'fully migrated source was not removed: ' . $source);
    }
    expectLegacyLogMigration(
        file_get_contents($journalDir . '/administrator.log') === "administrator diagnostic must stay\n",
        'unknown administrator log must remain byte-identical'
    );
    expectLegacyLogMigration(is_file($journalDir . '/api-debug.log'), 'unknown lookalike log must remain');
    expectLegacyLogMigration(is_file($journalDir . '/nested/api.log'), 'nested legacy-looking file must remain');
    expectLegacyLogMigration(is_link($journalDir . '/linked.log'), 'legacy migration must leave symlinks untouched');
    expectLegacyLogMigration(file_get_contents($outsideTarget) === "outside target\n", 'migration followed a source symlink');

    $previousLines = file($journalDir . '/module.log.1', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expectLegacyLogMigration(is_array($previousLines) && count($previousLines) === 8, 'previous generation must merge existing and migrated events');
    $events = [];
    $severities = [];
    foreach ($previousLines as $line) {
        $entry = LocalSpeechToTextSyslogParser::parse($line);
        expectLegacyLogMigration(is_array($entry), 'legacy channel line must migrate directly to syslog format');
        $events[] = $entry['event'];
        $severities[] = $entry['severity'];
        if ($entry['event'] === 'legacy_log_imported') {
            expectLegacyLogMigration(in_array($entry['context']['source'] ?? '', $knownSources, true), 'source must be a known basename');
            expectLegacyLogMigration(!str_contains((string)$entry['context']['source'], '/'), 'source context leaked a path');
        }
        expectLegacyLogMigration(!str_contains($line, 'legacy-secret'), 'migration leaked a legacy token');
        expectLegacyLogMigration(!str_contains($line, '/Users/operator'), 'migration leaked a legacy path');
        expectLegacyLogMigration(!str_contains($line, '79991234567'), 'migration leaked a full phone number');
        foreach ([
            'two word secret', 'two word value', 'escaped', '1234', '/srv/private',
            '/srv/assigned', '/srv/open-call', '/Library/Application Support', '\\\\server\\share',
            'nested private transcript', "\u{202E}",
        ] as $forbidden) {
            expectLegacyLogMigration(!str_contains($line, $forbidden), 'migration leaked adversarial legacy data: ' . $forbidden);
        }
    }
    expectLegacyLogMigration(
        count(array_filter($events, static fn (string $event): bool => $event === 'existing_history')) === 2,
        'legacy import must preserve repeated existing audit events'
    );
    expectLegacyLogMigration(count(array_filter($events, static fn (string $event): bool => $event === 'legacy_log_imported')) === 6, 'legacy event count must remain stable');
    expectLegacyLogMigration(in_array('alert', $severities, true), 'legacy ALERT severity must remain alert');
    expectLegacyLogMigration(
        $migration->run() === ['imported' => 0, 'removed' => 0, 'skipped' => 0],
        'successful migration must be idempotent'
    );

    mkdir($failedDir, 0750, true);
    file_put_contents($failedDir . '/api.log', "2026-07-19 09:00:00 legacy source\n");
    symlink($outsideTarget, $failedDir . '/module.log.1');
    $failed = (new LocalSpeechToTextLegacyLogMigration($failedDir, new LocalSpeechToTextLogWriter($failedDir)))->run();
    expectLegacyLogMigration($failed['removed'] === 0, 'failed journal replacement must not remove a source');
    expectLegacyLogMigration(is_file($failedDir . '/api.log'), 'source must remain after replacement failure');
    expectLegacyLogMigration(file_get_contents($outsideTarget) === "outside target\n", 'replacement followed module.log.1 symlink');

    mkdir($oversizedDir, 0750, true);
    $oversizedLine = "2026-07-19 10:00:00 INFO " . str_repeat('x', 5000) . "\n";
    file_put_contents($oversizedDir . '/api.log', str_repeat($oversizedLine, 1400));
    $oversizedMigration = new LocalSpeechToTextLegacyLogMigration(
        $oversizedDir,
        new LocalSpeechToTextLogWriter($oversizedDir)
    );
    $oversizedResult = $oversizedMigration->run();
    expectLegacyLogMigration(
        $oversizedResult['imported'] > 0 && $oversizedResult['removed'] === 1 && $oversizedResult['skipped'] === 0,
        'oversized legacy sources must be fully consumed and removed after retaining the newest bounded events'
    );
    expectLegacyLogMigration(!is_file($oversizedDir . '/api.log'), 'oversized fully-read source must not be re-imported');
    expectLegacyLogMigration(filesize($oversizedDir . '/module.log.1') <= 5_242_880, 'migrated previous journal exceeds 5 MiB');
    $oversizedHash = hash_file('sha256', $oversizedDir . '/module.log.1');
    expectLegacyLogMigration(
        $oversizedMigration->run() === ['imported' => 0, 'removed' => 0, 'skipped' => 0]
            && hash_file('sha256', $oversizedDir . '/module.log.1') === $oversizedHash,
        'oversized migration must be idempotent and preserve the migrated generation'
    );

    $migrationSource = file_get_contents($migrationPath);
    foreach (['dev', 'ino', 'size', 'mtime', 'is_link', 'realpath', 'basename'] as $guard) {
        expectLegacyLogMigration(str_contains((string)$migrationSource, $guard), 'migration deletion guard is missing: ' . $guard);
    }
    expectLegacyLogMigration(!str_contains((string)$migrationSource, "glob("), 'migration must not delete through a broad glob');
    expectLegacyLogMigration(!str_contains((string)$migrationSource, 'RecursiveDirectoryIterator'), 'migration must not recurse');
} finally {
    removeLegacyLogMigrationFixture($fixtureRoot);
}

echo "Legacy log migration OK\n";
