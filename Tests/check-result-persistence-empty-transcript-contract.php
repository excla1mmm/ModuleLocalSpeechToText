<?php

declare(strict_types=1);

/**
 * Static contract checks for the "short call produces an empty transcript"
 * incident: 105 api.operation_failed 500s on 7 short-recording jobs, all
 * caused by STTResult::search_text being nullable=false without
 * allowEmptyStringValues(), so Phalcon's automatic not-null validation
 * rejected the legitimate empty search_text of a zero-segment transcript.
 *
 * Phalcon is not available in this offline test environment (see
 * Tests/run-all.php notes / AGENTS.md), so this check verifies the fix at
 * the source level: the exact allowEmptyStringValues() calls exist for the
 * columns that legitimately hold '', and the deterministic-failure/logging
 * wiring described in Lib/LocalSpeechToTextMain.php is present. The dynamic
 * behavior of the deterministic-failure path (job -> terminal error,
 * queue.job_failed event, non-retryable response, receipt persisted) is
 * exercised end-to-end in Tests/check-job-terminal-production-callsite.php.
 * The SQLite FTS trigger's handling of an empty search_text is exercised in
 * Tests/check-transcript-search-index-sqlite.php.
 */

function expectResultPersistence(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$sttResult = (string)file_get_contents($root . '/Models/STTResult.php');
$sttCallTranscript = (string)file_get_contents($root . '/Models/STTCallTranscript.php');
$sttTranscriptEvent = (string)file_get_contents($root . '/Models/STTTranscriptEvent.php');
$revisionService = (string)file_get_contents($root . '/Lib/Services/CallTranscriptRevisionService.php');
$main = (string)file_get_contents($root . '/Lib/LocalSpeechToTextMain.php');
$exceptionFile = $root . '/Lib/Exceptions/ResultPersistenceValidationException.php';

expectResultPersistence(is_file($exceptionFile), 'ResultPersistenceValidationException must exist as a dedicated deterministic-failure marker');

// --- Root cause fix: empty transcript must be a valid, persistable outcome ---

$searchTextDeclaration = strpos($sttResult, 'public ?string $search_text');
expectResultPersistence($searchTextDeclaration !== false, 'search_text property not found');
$searchTextDocblock = substr($sttResult, max(0, $searchTextDeclaration - 120), 120);
expectResultPersistence(
    str_contains($searchTextDocblock, '@Column(type="string", nullable=false)'),
    'search_text must remain nullable=false at the schema level (it is a real NOT NULL column)'
);
expectResultPersistence(
    preg_match('/function initialize\(\):\s*void\s*\{.*allowEmptyStringValues\(\s*\[\s*\'search_text\'\s*\]\s*\)/s', $sttResult) === 1,
    'STTResult::initialize() must call allowEmptyStringValues([\'search_text\']) so a zero-segment transcript (empty search_text) saves successfully instead of failing Phalcon automatic not-null validation'
);

foreach ([
    'current_content_hash', 'current_parts_hash', 'pending_content_hash', 'pending_parts_hash', 'pending_payload',
] as $column) {
    expectResultPersistence(
        str_contains($sttCallTranscript, "'{$column}'"),
        "STTCallTranscript::initialize() must allow the legitimately-empty column {$column}"
    );
}
expectResultPersistence(
    preg_match('/function initialize\(\):\s*void\s*\{.*allowEmptyStringValues\(\s*\[/s', $sttCallTranscript) === 1,
    'STTCallTranscript::initialize() must call allowEmptyStringValues() for its empty-by-default hash/payload columns'
);

// --- Deterministic vs transient failure classification ---

expectResultPersistence(
    str_contains($sttResult, 'throw new ResultPersistenceValidationException(')
        && !str_contains($sttResult, 'throw new RuntimeException('),
    'STTResult::saveForJob() must signal a failed save() as a deterministic, non-retryable failure, not a generic RuntimeException that a 500 handler cannot distinguish from a transient error'
);
expectResultPersistence(
    substr_count($sttTranscriptEvent, 'throw new ResultPersistenceValidationException(') === 2,
    'STTTranscriptEvent save() failures must also be classified as deterministic persistence failures'
);
expectResultPersistence(
    str_contains($revisionService, 'throw new ResultPersistenceValidationException('),
    'CallTranscriptRevisionService save() failures must also be classified as deterministic persistence failures'
);

// --- LocalSpeechToTextMain wiring: logging detail, job-terminal handling, event ---

expectResultPersistence(
    str_contains($main, "'error_code' => LocalSpeechToTextLogSanitizer::errorCode(\$exception),")
        && str_contains($main, "'message' => \$exception->getMessage(),"),
    'logException() must include the sanitized exception message (previously only the exception class name was logged, making production incidents undiagnosable)'
);
expectResultPersistence(
    preg_match('/private static function logException\(string \$context, Throwable \$exception, array \$extraContext = \[\]\)/', $main) === 1,
    'logException() must accept extra context (e.g. job_id) to thread through call sites'
);
foreach (['submitResult', 'submitError', 'heartbeatJob', 'releaseJob', 'downloadJobFile'] as $method) {
    $start = strpos($main, "public static function {$method}(array \$data): PBXApiResult");
    expectResultPersistence($start !== false, "method not found: {$method}");
    $nextMethod = strpos($main, 'public static function ', $start + 1);
    $body = substr($main, $start, ($nextMethod !== false ? $nextMethod : strlen($main)) - $start);
    expectResultPersistence(
        str_contains($body, "logException(__METHOD__, \$exception, ['job_id' => \$jobId])"),
        "{$method}() must thread job_id into its top-level exception log so failures are diagnosable per job"
    );
}

expectResultPersistence(
    str_contains($main, 'catch (ResultPersistenceValidationException $exception)')
        && str_contains($main, 'handleDeterministicResultPersistenceFailure('),
    'submitResult() must handle a deterministic result-persistence failure distinctly from a transient Throwable'
);

$handlerStart = strpos($main, 'private static function handleDeterministicResultPersistenceFailure(');
expectResultPersistence($handlerStart !== false, 'handleDeterministicResultPersistenceFailure() must exist');
$handlerEnd = strpos($main, 'public static function submitError', $handlerStart);
$handlerBody = substr($main, $handlerStart, ($handlerEnd !== false ? $handlerEnd : strlen($main)) - $handlerStart);

expectResultPersistence(
    str_contains($handlerBody, 'markTerminalErrorForWorker('),
    'a deterministic result-persistence failure must move the job to a terminal error state via markTerminalErrorForWorker(), not leave it "processing" for the worker to retry identically'
);
expectResultPersistence(
    !str_contains($handlerBody, 'lease timeout') && str_contains($handlerBody, 'could not be persisted'),
    'the terminal error_msg must describe the actual submission failure, not reuse the unrelated lease-timeout wording'
);
expectResultPersistence(
    preg_match("/'job_failed'/", $handlerBody) === 1 && str_contains($handlerBody, "'error_code' => 'result_persistence_failed'"),
    'a queue.job_failed event with a stable error_code must be emitted for the deterministic failure'
);
expectResultPersistence(
    preg_match('/return JobApiResponseBuilder::success\(\s*\[/', $handlerBody) === 1,
    'the response must be success=true (not a 4xx/5xx that Lib/Services/JobSubmissionReceiptService would treat as JobSubmissionMutationRejected, rolling back the terminal-state write together with everything else in the ambient submission transaction)'
);

echo "Result persistence / empty-transcript contract OK\n";
