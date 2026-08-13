<?php

declare(strict_types=1);

use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogFormatter;
use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextSyslogParser;

function expectSTTSyslogFormat(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$formatterPath = $root . '/Lib/Services/LocalSpeechToTextSyslogFormatter.php';
$parserPath = $root . '/Lib/Services/LocalSpeechToTextSyslogParser.php';

expectSTTSyslogFormat(is_file($formatterPath), 'STT syslog formatter is missing');
expectSTTSyslogFormat(is_file($parserPath), 'STT syslog parser is missing');

require_once $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php';
require_once $formatterPath;
require_once $parserPath;

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('UTC');

try {
    $line = LocalSpeechToTextSyslogFormatter::format(
        'Queue API',
        'Job Retry',
        "Worker retry\nrequested",
        LOG_WARNING,
        ['z_count' => 2, 'job_id' => 145],
        1786371913,
        1842
    );

    expectSTTSyslogFormat(
        $line === '2026-08-10 14:25:13 daemon.warning ModuleLocalSpeechToText[1842]: '
            . '[queue_api.job_retry] Worker retry requested context={"job_id":145,"z_count":2}',
        'formatter must emit the canonical MikoPBX-compatible syslog line'
    );

    $entry = LocalSpeechToTextSyslogParser::parse($line);
    expectSTTSyslogFormat(is_array($entry), 'parser must accept a canonical module syslog line');
    expectSTTSyslogFormat(($entry['timestamp'] ?? '') === '2026-08-10 14:25:13', 'timestamp must round-trip');
    expectSTTSyslogFormat(($entry['facility'] ?? '') === 'daemon', 'facility must round-trip');
    expectSTTSyslogFormat(($entry['severity'] ?? '') === 'warning', 'severity must round-trip');
    expectSTTSyslogFormat(($entry['level'] ?? '') === 'warning', 'aggregated level must round-trip');
    expectSTTSyslogFormat(($entry['tag'] ?? '') === 'ModuleLocalSpeechToText', 'module tag must round-trip');
    expectSTTSyslogFormat(($entry['pid'] ?? 0) === 1842, 'PID must round-trip');
    expectSTTSyslogFormat(($entry['component'] ?? '') === 'queue_api', 'component must round-trip');
    expectSTTSyslogFormat(($entry['event'] ?? '') === 'job_retry', 'event must round-trip');
    expectSTTSyslogFormat(($entry['message'] ?? '') === 'Worker retry requested', 'message must round-trip');
    expectSTTSyslogFormat(
        ($entry['context'] ?? []) === ['job_id' => 145, 'z_count' => 2],
        'sorted scalar context must round-trip'
    );

    $critical = LocalSpeechToTextSyslogFormatter::format(
        'storage',
        'database_failed',
        'Database failed',
        LOG_CRIT,
        [],
        1786371913,
        7
    );
    $criticalEntry = LocalSpeechToTextSyslogParser::parse($critical);
    expectSTTSyslogFormat(
        is_array($criticalEntry)
            && ($criticalEntry['severity'] ?? '') === 'crit'
            && ($criticalEntry['level'] ?? '') === 'error',
        'critical severity must remain distinct while mapping to the error UI level'
    );

    expectSTTSyslogFormat(
        LocalSpeechToTextSyslogParser::parse('not a syslog line') === null,
        'parser must reject non-syslog input'
    );

    $literalContextMessage = 'Worker replied context={"state":"busy"}';
    $literalContextEntry = LocalSpeechToTextSyslogParser::parse(LocalSpeechToTextSyslogFormatter::format(
        'worker',
        'reply',
        $literalContextMessage,
        LOG_INFO,
        [],
        1786371913,
        10
    ));
    expectSTTSyslogFormat(
        is_array($literalContextEntry)
            && $literalContextEntry['message'] === $literalContextMessage
            && $literalContextEntry['context'] === [],
        'literal context-like message suffix must not be misclassified as structured context'
    );
    $embeddedDelimiterEntry = LocalSpeechToTextSyslogParser::parse(LocalSpeechToTextSyslogFormatter::format(
        'worker',
        'reply',
        'Worker replied',
        LOG_INFO,
        ['worker_id' => 'evil context={forged}', 'job_id' => 17],
        1786371913,
        10
    ));
    expectSTTSyslogFormat(
        is_array($embeddedDelimiterEntry)
            && $embeddedDelimiterEntry['message'] === 'Worker replied'
            && $embeddedDelimiterEntry['context']['job_id'] === 17
            && $embeddedDelimiterEntry['context']['worker_id'] === 'evil context={forged}',
        'context values containing the delimiter must not suppress structured audit fields'
    );

    $largeContext = [];
    for ($index = 0; $index < 40; $index++) {
        $largeContext['field_' . str_pad((string)$index, 2, '0', STR_PAD_LEFT)] = str_repeat('v', 700);
    }
    $bounded = LocalSpeechToTextSyslogFormatter::format(
        'queue',
        'bounded_record',
        str_repeat('m', 5000),
        LOG_INFO,
        $largeContext,
        1786371913,
        9
    );
    $boundedEntry = LocalSpeechToTextSyslogParser::parse($bounded);
    expectSTTSyslogFormat(strlen($bounded) <= 8192, 'formatted syslog record must not exceed 8192 bytes');
    expectSTTSyslogFormat(is_array($boundedEntry), 'bounded syslog record must remain parseable');
    expectSTTSyslogFormat(count($boundedEntry['context']) <= 32, 'context must contain at most 32 fields');
    foreach ($boundedEntry['context'] as $value) {
        expectSTTSyslogFormat(!is_string($value) || strlen($value) <= 512, 'context strings must not exceed 512 bytes');
    }
    expectSTTSyslogFormat(
        LocalSpeechToTextSyslogParser::parse(
            '2026-08-10 14:25:13 daemon.info ModuleLocalSpeechToText[9]: [queue.oversized] ' . str_repeat('x', 8200)
        ) === null,
        'parser must reject oversized journal records'
    );
} finally {
    date_default_timezone_set($previousTimezone);
}

echo "STT syslog line format OK\n";
