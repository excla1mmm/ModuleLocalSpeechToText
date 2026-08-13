<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextJournalFormatMigration;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogFormatter;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogParser;

function expectSTTJournalMigration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeSTTJournalMigrationFixture(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item instanceof SplFileInfo) {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

$root = dirname(__DIR__);
$migrationPath = $root . '/Lib/Services/LocalSpeechToTextJournalFormatMigration.php';
expectSTTJournalMigration(is_file($migrationPath), 'STT journal format migration is missing');

require_once $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php';
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogFormatter.php';
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogParser.php';
require_once $migrationPath;

$directory = sys_get_temp_dir() . '/stt-journal-format-migration-' . bin2hex(random_bytes(6));
$legacy = json_encode([
    'ts' => '2026-07-19T14:25:13+00:00',
    'level' => 'error',
    'component' => 'queue',
    'event' => 'job_failed',
    'message' => 'Job failed',
    'context' => ['job_id' => 145],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$canonical = LocalSpeechToTextSyslogFormatter::format(
    'worker',
    'worker_registered',
    'Worker registered',
    LOG_NOTICE,
    ['worker_id' => 7],
    strtotime('2026-07-19 14:26:00 UTC'),
    88
);

try {
    mkdir($directory, 0750, true);
    file_put_contents($directory . '/module.log.1', $legacy . "\n");
    file_put_contents($directory . '/module.log', $canonical . "\nsecret malformed payload\n");

    $migration = new LocalSpeechToTextJournalFormatMigration($directory);
    $result = $migration->run();
    expectSTTJournalMigration(
        $result === ['converted' => 1, 'invalid' => 1, 'truncated' => 0, 'files' => 2, 'failed' => 0],
        'migration summary must describe converted and invalid records'
    );

    $previous = file($directory . '/module.log.1', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $active = file($directory . '/module.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expectSTTJournalMigration(is_array($previous) && count($previous) === 1, 'previous generation must remain bounded');
    expectSTTJournalMigration(is_array($active) && count($active) === 2, 'active generation must preserve two records');

    $migrated = LocalSpeechToTextSyslogParser::parse($previous[0]);
    expectSTTJournalMigration(
        is_array($migrated)
            && $migrated['severity'] === 'err'
            && $migrated['pid'] === 0
            && $migrated['event'] === 'job_failed'
            && $migrated['context']['job_id'] === 145,
        'legacy JSONL must become a canonical syslog event without losing structured fields'
    );
    expectSTTJournalMigration($active[0] === $canonical, 'canonical syslog records must remain byte-identical');
    $invalid = LocalSpeechToTextSyslogParser::parse($active[1]);
    expectSTTJournalMigration(
        is_array($invalid)
            && $invalid['component'] === 'migration'
            && $invalid['event'] === 'legacy_line_unparsed'
            && str_starts_with((string)$invalid['context']['line_hash'], 'sha256:'),
        'malformed input must become a safe hashed migration event'
    );
    expectSTTJournalMigration(!str_contains($active[1], 'secret malformed payload'), 'malformed source content must not leak');

    $hashes = [hash_file('sha256', $directory . '/module.log.1'), hash_file('sha256', $directory . '/module.log')];
    expectSTTJournalMigration(
        $migration->run() === ['converted' => 0, 'invalid' => 0, 'truncated' => 0, 'files' => 0, 'failed' => 0],
        'second migration run must be a no-op'
    );
    expectSTTJournalMigration(
        $hashes === [hash_file('sha256', $directory . '/module.log.1'), hash_file('sha256', $directory . '/module.log')],
        'idempotent migration must preserve journal bytes'
    );
    file_put_contents($directory . '/module.log', str_repeat('x', 5_242_881));
    $oversizedHash = hash_file('sha256', $directory . '/module.log');
    $failed = $migration->run();
    expectSTTJournalMigration($failed['failed'] === 1, 'oversized input must report an explicit migration failure');
    expectSTTJournalMigration(
        hash_file('sha256', $directory . '/module.log') === $oversizedHash,
        'failed migration must leave the source journal unchanged'
    );
} finally {
    removeSTTJournalMigrationFixture($directory);
}

echo "STT journal format migration OK\n";
