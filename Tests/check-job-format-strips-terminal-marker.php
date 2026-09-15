<?php

declare(strict_types=1);

/**
 * STTJobManagementService::format() (the /jobs REST payload) must never
 * leak the internal STTJob::TERMINAL_ERROR_PREFIX bookkeeping marker in
 * error_msg, and both STTJobManagementService and QueueViewService must
 * share STTJob::displayErrorMessage()/isTerminalErrorMessage() rather than
 * each re-implementing the strip.
 */

namespace Modules\ModuleLocalSpeechToText\Models {
    class STTJob
    {
        public const string TERMINAL_ERROR_PREFIX = '[terminal] ';

        public static function isTerminalErrorMessage(?string $errorMessage): bool
        {
            return str_starts_with(trim((string)$errorMessage), self::TERMINAL_ERROR_PREFIX);
        }

        public static function displayErrorMessage(?string $errorMessage): ?string
        {
            if ($errorMessage === null) {
                return null;
            }
            if (self::isTerminalErrorMessage($errorMessage)) {
                return trim(substr(trim($errorMessage), strlen(self::TERMINAL_ERROR_PREFIX)));
            }

            return $errorMessage;
        }

        public $id = 7;
        public $call_id = 'call-7';
        public $status = 'error';
        public $priority = 5;
        public $worker_id = null;
        public $attempts = 1;
        public $created_at = '0';
        public $updated_at = '0';
        public $started_at = null;
        public $finished_at = '0';
        public $error_msg = null;
    }
}

namespace {
    use Modules\ModuleLocalSpeechToText\Models\STTJob;

    function expectFormatStripsTerminalMarker(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "Assertion failed: {$message}\n");
            exit(1);
        }
    }

    // 1. Static contract: both call sites use the shared STTJob helper, and
    //    QueueViewService no longer carries its own duplicate strip logic.
    $root = __DIR__ . '/..';
    $serviceSource = (string)file_get_contents($root . '/Lib/Services/STTJobManagementService.php');
    $queueViewSource = (string)file_get_contents($root . '/Lib/Services/QueueViewService.php');
    $modelSource = (string)file_get_contents($root . '/Models/STTJob.php');

    expectFormatStripsTerminalMarker(
        str_contains($serviceSource, 'STTJob::displayErrorMessage(') && str_contains($serviceSource, "'error_msg'"),
        'STTJobManagementService::format() must use the shared STTJob::displayErrorMessage() helper'
    );
    expectFormatStripsTerminalMarker(
        str_contains($serviceSource, 'STTJob::isTerminalErrorMessage('),
        'STTJobManagementService::format() must expose a terminal_error flag via the shared helper'
    );
    expectFormatStripsTerminalMarker(
        str_contains($queueViewSource, 'STTJob::displayErrorMessage('),
        'QueueViewService must use the shared STTJob::displayErrorMessage() helper'
    );
    expectFormatStripsTerminalMarker(
        !str_contains($queueViewSource, 'TERMINAL_ERROR_PREFIX'),
        'QueueViewService must not duplicate the TERMINAL_ERROR_PREFIX strip logic itself'
    );
    expectFormatStripsTerminalMarker(
        substr_count($modelSource, 'TERMINAL_ERROR_PREFIX') >= 1
        && str_contains($modelSource, 'function displayErrorMessage')
        && str_contains($modelSource, 'function isTerminalErrorMessage'),
        'STTJob must own the single canonical strip/detect implementation'
    );

    $dataStructureSource = (string)file_get_contents($root . '/Lib/RestAPI/Jobs/DataStructure.php');
    expectFormatStripsTerminalMarker(
        str_contains($dataStructureSource, "'terminal_error'"),
        'Lib/RestAPI/Jobs/DataStructure.php must document the terminal_error response field'
    );

    // 2. Behavioral contract using the stub STTJob above (mirrors real STTJob semantics).
    require_once $root . '/Lib/Services/STTJobManagementService.php';

    $terminalJob = new STTJob();
    $terminalJob->error_msg = STTJob::TERMINAL_ERROR_PREFIX . 'Recording file is not available';
    $formatted = \Modules\ModuleLocalSpeechToText\Lib\Services\STTJobManagementService::format($terminalJob);
    expectFormatStripsTerminalMarker(
        $formatted['error_msg'] === 'Recording file is not available',
        'format() must strip the terminal marker from error_msg: got ' . var_export($formatted['error_msg'], true)
    );
    expectFormatStripsTerminalMarker(
        !str_contains((string)$formatted['error_msg'], STTJob::TERMINAL_ERROR_PREFIX),
        'format() must not leak the raw terminal marker'
    );
    expectFormatStripsTerminalMarker(
        ($formatted['terminal_error'] ?? null) === true,
        'format() must report terminal_error = true for a deterministic terminal failure'
    );

    $transientJob = new STTJob();
    $transientJob->error_msg = 'Job is not assigned for processing';
    $formatted2 = \Modules\ModuleLocalSpeechToText\Lib\Services\STTJobManagementService::format($transientJob);
    expectFormatStripsTerminalMarker(
        $formatted2['error_msg'] === 'Job is not assigned for processing',
        'format() must leave a non-terminal error_msg unchanged'
    );
    expectFormatStripsTerminalMarker(
        ($formatted2['terminal_error'] ?? null) === false,
        'format() must report terminal_error = false for a transient error'
    );

    $noErrorJob = new STTJob();
    $noErrorJob->error_msg = null;
    $formatted3 = \Modules\ModuleLocalSpeechToText\Lib\Services\STTJobManagementService::format($noErrorJob);
    expectFormatStripsTerminalMarker(
        $formatted3['error_msg'] === null,
        'format() must keep error_msg null for a job with no error'
    );
    expectFormatStripsTerminalMarker(
        ($formatted3['terminal_error'] ?? null) === false,
        'format() must report terminal_error = false when there is no error'
    );

    echo "STTJobManagementService::format() strips the terminal marker OK\n";
}
