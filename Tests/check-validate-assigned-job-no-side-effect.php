<?php

declare(strict_types=1);

/**
 * validateAssignedJob() must only report a 409 job_lease_expired conflict
 * for an expired lease - it must not call STTJob::requeueStaleProcessing()
 * as a side effect from inside request validation (that recovery belongs to
 * the scan cycle, and doing it here happens inside a transaction that gets
 * rolled back anyway).
 *
 * validateAssignedJob() was later extracted out of LocalSpeechToTextMain
 * into WorkerAuthorizationValidator (see item 10's shrink-the-monolith
 * cleanup) as a public static method with the same body; this test follows
 * it to its new home with the same behavioral assertion.
 */

function expectNoSideEffect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$source = (string)file_get_contents(__DIR__ . '/../Lib/Services/WorkerAuthorizationValidator.php');
$start = strpos($source, 'function validateAssignedJob');
expectNoSideEffect($start !== false, 'validateAssignedJob method not found');
$end = strpos($source, "\n    public static function", $start + 1);
$body = substr($source, $start, $end === false ? null : $end - $start);

expectNoSideEffect(
    !str_contains($body, 'STTJob::requeueStaleProcessing()'),
    'validateAssignedJob must not call STTJob::requeueStaleProcessing() as a validation side effect'
);
expectNoSideEffect(
    str_contains($body, "job_lease_expired"),
    'validateAssignedJob must still report the job_lease_expired conflict'
);

// LocalSpeechToTextMain must no longer define its own copy of this logic;
// its call sites should delegate to WorkerAuthorizationValidator.
$mainSource = (string)file_get_contents(__DIR__ . '/../Lib/LocalSpeechToTextMain.php');
expectNoSideEffect(
    !str_contains($mainSource, 'private static function validateAssignedJob'),
    'LocalSpeechToTextMain must not keep a duplicate validateAssignedJob implementation'
);
expectNoSideEffect(
    str_contains($mainSource, 'WorkerAuthorizationValidator::validateAssignedJob('),
    'LocalSpeechToTextMain must delegate ownership checks to WorkerAuthorizationValidator'
);

echo "validateAssignedJob no-side-effect contract OK\n";
