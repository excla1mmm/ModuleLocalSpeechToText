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
    str_contains($worker, 'use Modules\\ModuleLocalSpeechToText\\Lib\\Services\\TranscriptRetentionService;'),
    'worker must import the retention service'
);
$publishPosition = strpos($worker, '(new CallTranscriptRevisionService())->publishReady();');
$purgeCallPosition = strpos($worker, '$this->purgeExpiredTranscripts();');
expectRetentionWorker(
    $publishPosition !== false && $purgeCallPosition !== false && $publishPosition < $purgeCallPosition,
    'retention must run after ready call transcripts are published'
);
$methodStart = strpos($worker, 'private function purgeExpiredTranscripts(): void');
$nextMethod = strpos($worker, "\n    private function ", ($methodStart ?: 0) + 10);
expectRetentionWorker($methodStart !== false && $nextMethod !== false, 'Cannot isolate worker retention method');
$method = substr($worker, $methodStart, $nextMethod - $methodStart);
foreach ([
    'TRANSCRIPT_RETENTION_LAST_CLEANUP_AT',
    'TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS',
    'transcriptRetentionDays()',
    'purgeExpired(',
    "['has_more']",
    "['calls']",
    "['results']",
    "['cutoff']",
] as $needle) {
    expectRetentionWorker(str_contains($method, $needle), "worker retention method is missing {$needle}");
}
expectRetentionWorker(
    str_contains($worker, 'private const int TRANSCRIPT_RETENTION_CLEANUP_INTERVAL_SECONDS = 3600;'),
    'completed retention sweeps must be throttled for one hour'
);
expectRetentionWorker(
    str_contains($method, "['has_more'] ? '0' : (string)\$now"),
    'cleanup marker must remain due while another batch exists'
);
expectRetentionWorker(
    str_contains($method, 'Purged %d call(s) and %d transcript result(s); cutoff=%d'),
    'retention log must contain counts and cutoff only'
);

echo "Transcript retention worker contract OK\n";
