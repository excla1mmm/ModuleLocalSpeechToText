<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLogReader;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogFormatter;

function expectStructuredReader(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function structuredReaderLine(int $sequence, int $timestamp): string
{
    if ($sequence === 1200) {
        return date('Y-m-d H:i:s', $timestamp)
            . ' daemon.warning ModuleLocalSpeechToText[2200]: [queue.sequence_1200] Event 1200 '
            . 'context={"api_key":"plain-secret","sequence":1200}' . "\n";
    }
    if ($sequence > 600) {
        return LocalSpeechToTextSyslogFormatter::format(
            $sequence % 2 === 0 ? 'queue' : 'worker',
            'sequence_' . $sequence,
            'Event ' . $sequence,
            $sequence % 10 === 0 ? LOG_WARNING : LOG_INFO,
            ['sequence' => $sequence],
            $timestamp,
            1000 + $sequence
        ) . "\n";
    }

    // Retain legacy JSONL coverage for direct upgrades from older versions.
    return json_encode([
        'ts' => date(DATE_RFC3339, $timestamp),
        'level' => $sequence % 10 === 0 ? 'warning' : 'info',
        'component' => $sequence % 2 === 0 ? 'queue' : 'worker',
        'event' => 'sequence_' . $sequence,
        'message' => 'Event ' . $sequence,
        'context' => ['sequence' => $sequence],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
}

function removeStructuredReaderFixture(string $directory): void
{
    if (is_link($directory)) {
        unlink($directory);
        return;
    }
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item instanceof SplFileInfo && $item->isDir() && !$item->isLink()) {
            removeStructuredReaderFixture($item->getPathname());
        } elseif ($item instanceof SplFileInfo) {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

$root = dirname(__DIR__);
$readerPath = $root . '/Lib/Services/ModuleLogReader.php';
expectStructuredReader(is_file($readerPath), 'ModuleLogReader is missing');
require_once $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php';
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogFormatter.php';
require_once $root . '/Lib/Services/LocalSpeechToTextSyslogParser.php';
require_once $readerPath;

$fixtureRoot = sys_get_temp_dir() . '/local-stt-log-reader-' . bin2hex(random_bytes(6));
$journalDir = $fixtureRoot . '/journal';
$missingDir = $fixtureRoot . '/missing';
$emptyDir = $fixtureRoot . '/empty';
$corruptDir = $fixtureRoot . '/corrupt';
$unsafeDir = $fixtureRoot . '/unsafe';
$outsideFile = $fixtureRoot . '/outside.log';
$linkedDir = $fixtureRoot . '/linked-directory';
$now = time();

try {
    mkdir($journalDir . '/nested', 0750, true);
    $previous = '';
    $active = '';
    for ($sequence = 1; $sequence <= 600; $sequence++) {
        $previous .= structuredReaderLine($sequence, $now - (1201 - $sequence));
    }
    for ($sequence = 601; $sequence <= 1200; $sequence++) {
        $active .= structuredReaderLine($sequence, $now - (1201 - $sequence));
    }
    $active .= "not-json\n";
    file_put_contents($journalDir . '/module.log.1', $previous);
    file_put_contents($journalDir . '/module.log', $active);
    file_put_contents($journalDir . '/module.log.2', structuredReaderLine(9998, $now));
    file_put_contents($journalDir . '/administrator.log', structuredReaderLine(9999, $now));
    file_put_contents($journalDir . '/nested/module.log', structuredReaderLine(10000, $now));

    $payload = (new ModuleLogReader($journalDir))->recentForPeriod(0);
    expectStructuredReader($payload['lineCount'] === 1000, 'reader must cap valid events at 1000');
    expectStructuredReader(count($payload['entries']) === 1000, 'entries and lineCount must match');
    expectStructuredReader($payload['entries'][0]['context']['sequence'] === 201, 'reader must keep the newest 1000 events');
    expectStructuredReader($payload['entries'][999]['context']['sequence'] === 1200, 'newest event must be last chronologically');
    expectStructuredReader($payload['truncated'] === true, 'entry limit must mark the result truncated');
    expectStructuredReader($payload['invalidLineCount'] === 1, 'malformed journal lines must be counted');
    expectStructuredReader($payload['malformedCount'] === 1, 'legacy malformedCount alias must remain compatible');
    expectStructuredReader($payload['sourceStatus'] === 'partial', 'mixed valid and corrupt data must be partial');
    expectStructuredReader($payload['fileCount'] === 2, 'only the two journal generations may be counted');
    expectStructuredReader($payload['period'] === 0, 'period=0 must mean all stored data');
    expectStructuredReader($payload['availableFrom'] === $payload['entries'][0]['timestamp'], 'availableFrom must match oldest returned event');
    expectStructuredReader(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload['entries'][0]['timestamp']) === 1,
        'entry timestamps must retain RFC 3339 timezone semantics'
    );
    expectStructuredReader(
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload['generatedAt']) === 1,
        'generatedAt must retain RFC 3339 timezone semantics'
    );
    expectStructuredReader(
        $payload['entries'][999]['context']['api_key'] === '[REDACTED]',
        'canonical journal context must be sanitized before returning REST entries'
    );
    expectStructuredReader(
        $payload['entries'][0]['ts'] === $payload['entries'][0]['timestamp'],
        'legacy ts alias must remain compatible during the transition'
    );
    expectStructuredReader(str_contains($payload['logText'], 'Event 1200'), 'compatibility logText must remain available');
    expectStructuredReader(!str_contains($payload['logText'], 'Event 9999'), 'reader scanned an administrator log');
    expectStructuredReader(!str_contains($payload['logText'], 'Event 9998'), 'reader scanned module.log.2');
    expectStructuredReader(!str_contains($payload['logText'], 'Event 10000'), 'reader scanned a nested journal');
    expectStructuredReader(
        str_contains($payload['logText'], ' daemon.warning ModuleLocalSpeechToText[2200]:'),
        'compatibility logText must expose canonical syslog lines'
    );

    $oneHour = (new ModuleLogReader($journalDir))->recentForPeriod(3600);
    expectStructuredReader($oneHour['period'] === 3600, 'one-hour period must be accepted');
    $invalid = (new ModuleLogReader($journalDir))->recentForPeriod(604800);
    expectStructuredReader($invalid['period'] === 3600, 'unsupported periods must fall back to one hour');

    mkdir($missingDir, 0750, true);
    $missing = (new ModuleLogReader($missingDir))->recentForPeriod(0);
    expectStructuredReader($missing['sourceStatus'] === 'missing', 'existing directory without journals must be missing');

    mkdir($emptyDir, 0750, true);
    touch($emptyDir . '/module.log');
    $empty = (new ModuleLogReader($emptyDir))->recentForPeriod(0);
    expectStructuredReader($empty['sourceStatus'] === 'empty', 'readable empty journal must be empty');

    mkdir($corruptDir, 0750, true);
    file_put_contents(
        $corruptDir . '/module.log',
        "broken\n"
        . json_encode([
            'ts' => date(DATE_RFC3339),
            'level' => 'verbose',
            'component' => 'queue',
            'event' => 'bad_level',
            'message' => 'bad',
            'context' => [],
        ], JSON_THROW_ON_ERROR) . "\n"
        . json_encode([
            'ts' => date(DATE_RFC3339),
            'level' => 'info',
            'component' => 'queue',
            'event' => 'nested_context',
            'message' => 'bad',
            'context' => ['nested' => ['bad']],
        ], JSON_THROW_ON_ERROR) . "\n"
    );
    $corrupt = (new ModuleLogReader($corruptDir))->recentForPeriod(0);
    expectStructuredReader($corrupt['sourceStatus'] === 'corrupt', 'all malformed records must produce corrupt state');
    expectStructuredReader($corrupt['malformedCount'] === 3, 'every malformed record must be counted');

    mkdir($unsafeDir, 0750, true);
    file_put_contents($outsideFile, structuredReaderLine(1, $now));
    symlink($outsideFile, $unsafeDir . '/module.log');
    $unsafe = (new ModuleLogReader($unsafeDir))->recentForPeriod(0);
    expectStructuredReader($unsafe['sourceStatus'] === 'unavailable', 'symlink-only source must be unavailable');
    expectStructuredReader($unsafe['entries'] === [], 'reader must not follow a journal symlink');

    symlink($journalDir, $linkedDir);
    $linked = (new ModuleLogReader($linkedDir))->recentForPeriod(0);
    expectStructuredReader($linked['sourceStatus'] === 'unavailable', 'reader must reject a symlinked journal directory');

    $absent = (new ModuleLogReader($fixtureRoot . '/absent-directory'))->recentForPeriod(0);
    expectStructuredReader($absent['sourceStatus'] === 'unavailable', 'absent directory must be unavailable');

    $readerSource = file_get_contents($readerPath);
    expectStructuredReader(!str_contains((string)$readerSource, 'RecursiveDirectoryIterator'), 'reader must not recurse');
    expectStructuredReader(!str_contains((string)$readerSource, "'/messages"), 'reader must not scan shared messages');
    expectStructuredReader(!str_contains((string)$readerSource, 'glob('), 'reader must not glob arbitrary logs');
    expectStructuredReader(str_contains((string)$readerSource, '65_536'), 'reader must use approximately 64 KiB chunks');
} finally {
    removeStructuredReaderFixture($linkedDir);
    removeStructuredReaderFixture($fixtureRoot);
}

echo "Structured log reader OK\n";
