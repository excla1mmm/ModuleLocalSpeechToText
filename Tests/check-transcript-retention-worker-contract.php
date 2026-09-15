<?php

declare(strict_types=1);

function expectRetentionWorker(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$worker = (string)file_get_contents(__DIR__ . '/../bin/WorkerLocalSpeechToTextJobs.php');
expectRetentionWorker(
    str_contains($worker, 'use Modules\\ModuleLocalSpeechToText\\Lib\\Services\\TranscriptRetentionCoordinator;'),
    'worker must import the retention coordinator'
);
$publishPosition = strpos($worker, '(new CallTranscriptRevisionService())->publishReady();');
$purgeCallPosition = strpos($worker, '$this->purgeExpiredTranscripts();');
expectRetentionWorker(
    $publishPosition !== false && $purgeCallPosition !== false && $publishPosition < $purgeCallPosition,
    'retention must run after ready call transcripts are published'
);
$forwarderStart = strpos($worker, 'private function purgeExpiredTranscripts(): void');
$forwarderNextMethod = strpos($worker, "\n    private function ", ($forwarderStart ?: 0) + 10);
expectRetentionWorker($forwarderStart !== false && $forwarderNextMethod !== false, 'Cannot isolate worker retention forwarder');
$forwarder = substr($worker, $forwarderStart, $forwarderNextMethod - $forwarderStart);
expectRetentionWorker(
    str_contains($forwarder, '$this->transcriptRetentionCoordinator()->purgeExpiredTranscripts();'),
    'worker retention forwarder must delegate to TranscriptRetentionCoordinator'
);

// The actual sweep logic now lives in Lib/Services/TranscriptRetentionCoordinator.php,
// moved verbatim out of the worker (see AGENTS.md monolithic-worker split).
$coordinator = (string)file_get_contents(__DIR__ . '/../Lib/Services/TranscriptRetentionCoordinator.php');
$methodStart = strpos($coordinator, 'public function purgeExpiredTranscripts(): void');
$methodClose = strpos($coordinator, "\n    }\n", ($methodStart ?: 0) + 10);
expectRetentionWorker($methodStart !== false && $methodClose !== false, 'Cannot isolate retention coordinator method');
$method = substr($coordinator, $methodStart, $methodClose - $methodStart);
foreach ([
    'TRANSCRIPT_RETENTION_LAST_CLEANUP_AT',
    'TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS',
    'transcriptRetentionDays()',
    'purgeExpired(',
    "['has_more']",
    "['calls']",
    "['results']",
] as $needle) {
    expectRetentionWorker(str_contains($method, $needle), "retention coordinator method is missing {$needle}");
}
expectRetentionWorker(
    str_contains($coordinator, 'private const int TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS = 3600;'),
    'completed retention sweeps must be throttled for one hour'
);
expectRetentionWorker(
    str_contains($method, "['has_more'] ? '0' : (string)\$now"),
    'cleanup marker must remain due while another batch exists'
);
expectRetentionWorker(
    str_contains($method, "'retention_cleanup_completed'")
        && str_contains($method, "'calls_count' => \$summary['calls']")
        && str_contains($method, "'results_count' => \$summary['results']")
        && str_contains($method, "'has_more' => \$summary['has_more']"),
    'retention log must expose only the structured cleanup summary'
);

echo "Transcript retention worker contract OK\n";
