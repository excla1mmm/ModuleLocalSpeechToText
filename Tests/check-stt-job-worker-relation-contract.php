<?php

declare(strict_types=1);

/**
 * STTJob::worker_id -> STTWorker::id is a many-jobs-to-one-worker relation,
 * so it must be declared with belongsTo(), not hasOne().
 */

function expectWorkerRelation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$source = (string)file_get_contents(__DIR__ . '/../Models/STTJob.php');
$start = strpos($source, "'worker_id',");
expectWorkerRelation($start !== false, 'worker_id relation declaration not found');
$snippet = substr($source, max(0, $start - 40), 40);

expectWorkerRelation(
    str_contains($snippet, '$this->belongsTo('),
    'STTJob->STTWorker must be declared with belongsTo(), not hasOne()'
);
expectWorkerRelation(
    !str_contains($snippet, '$this->hasOne('),
    'STTJob->STTWorker must not remain a hasOne() relation'
);

echo "STTJob worker relation contract OK\n";
