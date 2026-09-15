<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    final class ScannerWorkerConnection
    {
        public static bool $recoveryMustFail = false;

        public function isUnderTransaction(): bool
        {
            return self::$recoveryMustFail;
        }

        public function rollback(): bool
        {
            return !self::$recoveryMustFail;
        }
    }

    final class ScannerRecordSet
    {
        /** @return array<int, array<string, mixed>> */
        public function toArray(): array
        {
            return [];
        }
    }

    final class CallDetailRecords
    {
        public static int $readConnectionCalls = 0;
        public static int $findCalls = 0;

        public function getReadConnection(): ScannerWorkerConnection
        {
            self::$readConnectionCalls++;
            return new ScannerWorkerConnection();
        }

        public static function find(array $parameters): ScannerRecordSet
        {
            self::$findCalls++;
            \Modules\ModuleLocalSpeechToText\Lib\Services\ScannerWorkerProbe::reachPhase('cdr_scan');
            return new ScannerRecordSet();
        }

        /** @param array<string, mixed> $parameters */
        public static function maximum(array $parameters): int
        {
            return 0;
        }
    }
}

namespace MikoPBX\Common\Providers {
    final class DatabaseProviderBase
    {
        public static int $ensureCdrTablesCalls = 0;

        public static function ensureCdrTables(): void
        {
            self::$ensureCdrTablesCalls++;
            \Modules\ModuleLocalSpeechToText\Lib\Services\ScannerWorkerProbe::reachPhase('cdr_schema');
        }
    }
}

namespace MikoPBX\Core\System {
    final class Directories
    {
        public const string CORE_TEMP_DIR = 'core_temp';
        public const string AST_MONITOR_DIR = 'ast_monitor';
        public static string $runtimeDirectory = '';

        public static function getDir(string $directory): string
        {
            return self::$runtimeDirectory;
        }
    }

    final class Processes
    {
        public static string $lockFileDirectory = '';

        public static function getLockFileDir(): string
        {
            return self::$lockFileDirectory;
        }
    }
}

namespace MikoPBX\Core\Workers {
    abstract class WorkerBase
    {
        protected bool $needRestart = false;

        public static function startWorker(array $arguments): void
        {
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Exceptions {
    final class ModuleDatabaseWriterBusyException extends \RuntimeException
    {
    }
}

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    final class ScannerWorkerProbe
    {
        /** @var array<int, array{operation: int, resource_id: int}> */
        public static array $lifecycleLockOperations = [];
        /** @var array<int, int> */
        public static array $lifecycleClosedResources = [];
        public static ?string $fopenFailureNeedle = null;
        public static string $filesystemWarning = '';
        public static mixed $renameHandler = null;
        public static ?string $injectedPhase = null;
        public static string $injectedPhaseMessage = '';
        /** @var array<int, string> */
        public static array $visitedPhases = [];

        public static function reset(): void
        {
            self::$lifecycleLockOperations = [];
            self::$lifecycleClosedResources = [];
            self::$fopenFailureNeedle = null;
            self::$filesystemWarning = '';
            self::$renameHandler = null;
            self::$injectedPhase = null;
            self::$injectedPhaseMessage = '';
            self::$visitedPhases = [];
        }

        public static function reachPhase(string $phase): void
        {
            $lastPhase = self::$visitedPhases === []
                ? null
                : self::$visitedPhases[count(self::$visitedPhases) - 1];
            if ($lastPhase !== $phase) {
                self::$visitedPhases[] = $phase;
            }
            if (self::$injectedPhase !== $phase) {
                return;
            }

            throw new \PDOException(self::$injectedPhaseMessage, 9999);
        }

        public static function isLifecycleLease(mixed $stream): bool
        {
            if (!is_resource($stream)) {
                return false;
            }
            $metadata = stream_get_meta_data($stream);
            return str_ends_with((string)($metadata['uri'] ?? ''), '/.module-lifecycle-v2.lock');
        }
    }

    function flock(mixed $stream, int $operation, ?int &$wouldBlock = null): bool
    {
        if (ScannerWorkerProbe::isLifecycleLease($stream)) {
            ScannerWorkerProbe::$lifecycleLockOperations[] = [
                'operation' => $operation,
                'resource_id' => get_resource_id($stream),
            ];
        }
        return \flock($stream, $operation, $wouldBlock);
    }

    function fclose(mixed $stream): bool
    {
        if (ScannerWorkerProbe::isLifecycleLease($stream)) {
            ScannerWorkerProbe::$lifecycleClosedResources[] = get_resource_id($stream);
        }
        return \fclose($stream);
    }

    function fopen(
        string $filename,
        string $mode,
        bool $useIncludePath = false,
        mixed $context = null
    ): mixed {
        $needle = ScannerWorkerProbe::$fopenFailureNeedle;
        if ($needle !== null && str_contains($filename, $needle)) {
            trigger_error(ScannerWorkerProbe::$filesystemWarning, E_USER_WARNING);
            return false;
        }
        return $context === null
            ? \fopen($filename, $mode, $useIncludePath)
            : \fopen($filename, $mode, $useIncludePath, $context);
    }

    function rename(string $from, string $to, mixed $context = null): bool
    {
        if (is_callable(ScannerWorkerProbe::$renameHandler)) {
            return (bool)(ScannerWorkerProbe::$renameHandler)($from, $to);
        }
        return $context === null ? \rename($from, $to) : \rename($from, $to, $context);
    }

    final class LocalSpeechToTextLogger
    {
        /** @var array<int, array<string, mixed>> */
        public static array $events = [];

        public static function event(
            string $component,
            string $event,
            string $message,
            int $level = LOG_INFO,
            array $context = []
        ): void {
            self::$events[] = compact('component', 'event', 'message', 'level', 'context');
        }
    }

    final class ModuleDatabasePragmas
    {
        public static int $configureRuntimeCalls = 0;

        public function configureRuntime(mixed $connection): void
        {
            self::$configureRuntimeCalls++;
            ScannerWorkerProbe::reachPhase('runtime_pragmas');
        }
    }

    final class ModuleDatabaseWriterMutex
    {
        /** @var array<int, ?\Throwable> */
        public static array $failures = [];

        public function synchronized(callable $operation): mixed
        {
            $failure = array_shift(self::$failures);
            if ($failure instanceof \Throwable) {
                throw $failure;
            }
            return $operation();
        }
    }

    final class RetryStateStore
    {
        public const string TYPE_DELAYED = 'delayed';
        public const string TYPE_SKIPPED = 'skipped';

        public function __construct(mixed $connection)
        {
        }

        /** @return array<int, array<string, mixed>> */
        public function due(string $type, int $limit): array
        {
            ScannerWorkerProbe::reachPhase(
                $type === self::TYPE_DELAYED ? 'delayed_recordings' : 'skipped_recordings'
            );
            return [];
        }
    }

    final class CallTranscriptRevisionService
    {
        public static int $publishReadyCalls = 0;

        /** @return array{published: int, scanned: int, blocked_jobs: int} */
        public function publishReady(): array
        {
            self::$publishReadyCalls++;
            ScannerWorkerProbe::reachPhase('call_transcript_publish');
            return ['published' => 0, 'scanned' => 0, 'blocked_jobs' => 0];
        }
    }

    final class TranscriptRetentionService
    {
        /** @return array{calls: int, results: int, has_more: bool} */
        public function purgeExpired(int $retentionDays, int $now): array
        {
            return ['calls' => 0, 'results' => 0, 'has_more' => false];
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\Models {
    use MikoPBX\Common\Models\ScannerWorkerConnection;

    final class STTJob
    {
        public static int $writeConnectionCalls = 0;
        public static int $requeueStaleProcessingCalls = 0;

        public function getWriteConnection(): ScannerWorkerConnection
        {
            self::$writeConnectionCalls++;
            return new ScannerWorkerConnection();
        }

        public static function requeueStaleProcessing(): int
        {
            self::$requeueStaleProcessingCalls++;
            \Modules\ModuleLocalSpeechToText\Lib\Services\ScannerWorkerProbe::reachPhase('stale_leases');
            return 0;
        }
    }

    final class STTSetting
    {
        public const string DELAYED_RECORDINGS = 'delayed_recordings';
        public const string SKIPPED_RECORDINGS = 'skipped_recordings';
        public const string CDR_SCAN_LIMIT = 'cdr_scan_limit';
        public const string CDR_SCAN_CURSOR_ENDTIME = 'cdr_scan_cursor_endtime';
        public const string CDR_SCAN_CURSOR_ID = 'cdr_scan_cursor_id';
        public const string CDR_SCAN_CURSOR_MODE = 'cdr_scan_cursor_mode';
        public const string CDR_SCAN_CURSOR_MODE_ID = 'id';
        public const string CDR_BACKFILL_CURSOR_ENDTIME = 'cdr_backfill_cursor_endtime';
        public const string CDR_BACKFILL_CURSOR_ID = 'cdr_backfill_cursor_id';
        public const string CDR_BACKFILL_DONE = 'cdr_backfill_done';
        public const string CDR_BACKFILL_DONE_HISTORY_AT = 'cdr_backfill_done_history_at';
        public const string TRANSCRIPT_RETENTION_LAST_CLEANUP_AT = 'retention_last_cleanup_at';
        /** @var array<string, string> */
        public static array $values = [];
        public static int $calls = 0;

        public static function reset(): void
        {
            self::$values = [
                self::DELAYED_RECORDINGS => '[]',
                self::SKIPPED_RECORDINGS => '[]',
                self::CDR_SCAN_LIMIT => '20',
                self::CDR_BACKFILL_DONE => '0',
            ];
            self::$calls = 0;
        }

        public static function getValue(string $key, string $default = ''): string
        {
            self::$calls++;
            return self::$values[$key] ?? $default;
        }

        public static function setValue(string $key, string $value): void
        {
            self::$calls++;
            self::$values[$key] = $value;
        }

        public static function cdrScanLimit(): int
        {
            return max(1, min(1000, (int)self::getValue(self::CDR_SCAN_LIMIT, '50')));
        }

        public static function cdrHistoryStartDateTime(): string
        {
            return '';
        }

        public static function transcriptRetentionDays(): int
        {
            \Modules\ModuleLocalSpeechToText\Lib\Services\ScannerWorkerProbe::reachPhase('retention_cleanup');
            return 0;
        }
    }

    final class STTWorker
    {
        public static int $markStaleOfflineCalls = 0;
        /** @var array<int, ?\Throwable> */
        public static array $failures = [];

        public static function markStaleOffline(): void
        {
            self::$markStaleOfflineCalls++;
            \Modules\ModuleLocalSpeechToText\Lib\Services\ScannerWorkerProbe::reachPhase('stale_workers');
            $failure = array_shift(self::$failures);
            if ($failure instanceof \Throwable) {
                throw $failure;
            }
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\bin {
    final class ScannerWorkerSleepProbe
    {
        /** @var array<int, int> */
        public static array $seconds = [];
        public static mixed $handler = null;
    }

    function sleep(int $seconds): int
    {
        ScannerWorkerSleepProbe::$seconds[] = $seconds;
        if (is_callable(ScannerWorkerSleepProbe::$handler)) {
            (ScannerWorkerSleepProbe::$handler)($seconds);
            return 0;
        }
        throw new \ScannerWorkerTestStop('scanner worker test stop');
    }

    function pcntl_signal_dispatch(): bool
    {
        return true;
    }
}

namespace {
    use MikoPBX\Common\Models\CallDetailRecords;
    use MikoPBX\Common\Models\ScannerWorkerConnection;
    use MikoPBX\Common\Providers\DatabaseProviderBase;
    use MikoPBX\Core\System\Directories;
    use MikoPBX\Core\System\Processes;
    use Modules\ModuleLocalSpeechToText\bin\ScannerWorkerSleepProbe;
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerProcessGuard;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerWorkerProbe;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

    final class ScannerWorkerTestStop extends RuntimeException
    {
    }

    /** @param array<int, string> $failures */
    function checkScannerWorker(bool $condition, string $message, array &$failures): void
    {
        if (!$condition) {
            $failures[] = $message;
        }
    }

    function removeScannerWorkerTestDirectory(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeScannerWorkerTestDirectory($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($directory);
    }

    /** @return array<int, array<string, mixed>> */
    function scannerWorkerEvents(string $event): array
    {
        return array_values(array_filter(
            LocalSpeechToTextLogger::$events,
            static fn (array $entry): bool => ($entry['event'] ?? null) === $event
        ));
    }

    /** @return array<string, mixed>|null */
    function scannerWorkerEvent(string $event): ?array
    {
        return scannerWorkerEvents($event)[0] ?? null;
    }

    function scannerBusyFailure(): PDOException
    {
        $exception = new PDOException('database is busy at /private/customer.db', 5);
        $exception->errorInfo = ['HY000', 5, 'database is busy'];
        return $exception;
    }

    function resetScannerWorkerProbes(string $runtimeRoot): void
    {
        Directories::$runtimeDirectory = $runtimeRoot;
        Processes::$lockFileDirectory = $runtimeRoot;
        ScannerProcessGuard::overrideDefaultRuntimeDirectory($runtimeRoot . '/ModuleLocalSpeechToText/scanner');
        LocalSpeechToTextLogger::$events = [];
        ModuleDatabasePragmas::$configureRuntimeCalls = 0;
        \Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex::$failures = [];
        DatabaseProviderBase::$ensureCdrTablesCalls = 0;
        STTWorker::$markStaleOfflineCalls = 0;
        STTWorker::$failures = [];
        STTJob::$writeConnectionCalls = 0;
        STTJob::$requeueStaleProcessingCalls = 0;
        CallDetailRecords::$readConnectionCalls = 0;
        CallDetailRecords::$findCalls = 0;
        CallTranscriptRevisionService::$publishReadyCalls = 0;
        ScannerWorkerConnection::$recoveryMustFail = false;
        STTSetting::reset();
        ScannerWorkerSleepProbe::$seconds = [];
        ScannerWorkerSleepProbe::$handler = null;
        ScannerWorkerProbe::reset();
    }

    /** @param array<int, string> $failures */
    function runScannerWorker(bool $expectCleanReturn, string $label, array &$failures): void
    {
        try {
            (new WorkerLocalSpeechToTextJobs())->start([]);
            if (!$expectCleanReturn) {
                $failures[] = $label . ' did not reach the configured test stop';
            }
        } catch (ScannerWorkerTestStop) {
            if ($expectCleanReturn) {
                $failures[] = $label . ' used a normal sleep after it should have stopped';
            }
        } catch (Throwable $exception) {
            $failures[] = $label . ' escaped ' . get_class($exception);
        }
    }

    /** @param array<int, string> $failures */
    function checkLifecycleLeasePairs(int $expected, string $label, array &$failures): void
    {
        $acquisitions = array_values(array_filter(
            ScannerWorkerProbe::$lifecycleLockOperations,
            static fn (array $call): bool => in_array($call['operation'], [LOCK_SH, LOCK_SH | LOCK_NB], true)
        ));
        $releases = array_values(array_filter(
            ScannerWorkerProbe::$lifecycleLockOperations,
            static fn (array $call): bool => $call['operation'] === LOCK_UN
        ));
        checkScannerWorker(count($acquisitions) === $expected, $label . ' lifecycle acquisition count mismatch', $failures);
        checkScannerWorker(count($releases) === $expected, $label . ' lifecycle release count mismatch', $failures);
        $acquiredIds = array_column($acquisitions, 'resource_id');
        $releasedIds = array_column($releases, 'resource_id');
        checkScannerWorker(
            $acquiredIds === $releasedIds && $acquiredIds === ScannerWorkerProbe::$lifecycleClosedResources,
            $label . ' did not release and close every acquired lifecycle lease exactly once',
            $failures
        );
    }

    $root = dirname(__DIR__);
    $temporaryRoot = sys_get_temp_dir() . '/local-stt-scanner-worker-' . bin2hex(random_bytes(8));
    $lifecycleDirectory = $root . '/db';
    $lifecycleLock = $lifecycleDirectory . '/.module-lifecycle-v2.lock';
    $lifecycleMarker = $lifecycleDirectory . '/.module-lifecycle-state-v2.json';
    $lifecycleDirectoryExisted = is_dir($lifecycleDirectory);
    $lifecycleLockExisted = is_file($lifecycleLock);
    $lifecycleMarkerExisted = is_file($lifecycleMarker);
    register_shutdown_function(
        static function () use (
            $temporaryRoot,
            $lifecycleDirectory,
            $lifecycleLock,
            $lifecycleMarker,
            $lifecycleDirectoryExisted,
            $lifecycleLockExisted,
            $lifecycleMarkerExisted
        ): void {
            removeScannerWorkerTestDirectory($temporaryRoot);
            if (!$lifecycleLockExisted && is_file($lifecycleLock)) {
                unlink($lifecycleLock);
            }
            if (!$lifecycleMarkerExisted && is_file($lifecycleMarker)) {
                unlink($lifecycleMarker);
            }
            if (!$lifecycleDirectoryExisted && is_dir($lifecycleDirectory)) {
                $entries = array_values(array_diff(scandir($lifecycleDirectory) ?: [], ['.', '..']));
                if ($entries === []) {
                    rmdir($lifecycleDirectory);
                }
            }
        }
    );
    mkdir($temporaryRoot, 0700, true);
    file_put_contents($temporaryRoot . '/Globals.php', "<?php\n");

    require_once $root . '/Lib/Services/TransactionRecoveryException.php';
    require_once $root . '/Lib/Services/ModuleTransaction.php';
    require_once $root . '/Lib/Services/ScannerTransactionRecovery.php';
    require_once $root . '/Lib/Services/ScannerDatabaseFailurePolicy.php';
    require_once $root . '/Lib/Services/ScannerIterationFailureHandler.php';
    require_once $root . '/Lib/Services/ScannerProcessGuard.php';
    require_once $root . '/Lib/Services/ModuleLifecycleGate.php';
    require_once $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php';
    require_once $root . '/Lib/Services/CdrDiscoveryBackoffPolicy.php';
    new ModuleLifecycleGate(null, true);

    $workingDirectory = getcwd();
    chdir($temporaryRoot);
    require_once $root . '/bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? $root : $workingDirectory);

    $failures = [];

    $runtimeRoot = $temporaryRoot . '/duplicate';
    resetScannerWorkerProbes($runtimeRoot);
    STTWorker::$failures = [new RuntimeException('duplicate scanner must not execute')];
    $competingGuard = new ScannerProcessGuard();
    checkScannerWorker($competingGuard->acquire(), 'duplicate setup could not acquire singleton', $failures);
    runScannerWorker(true, 'duplicate scanner', $failures);
    checkScannerWorker(scannerWorkerEvent('scanner_duplicate_rejected') !== null, 'duplicate event missing', $failures);
    checkScannerWorker(STTWorker::$markStaleOfflineCalls === 0, 'duplicate scanner performed database work', $failures);
    checkLifecycleLeasePairs(0, 'duplicate scanner', $failures);
    $competingGuard->release();

    $runtimeRoot = $temporaryRoot . '/fatal-pdo';
    resetScannerWorkerProbes($runtimeRoot);
    $injectedPdoMessage = 'unknown database failure dsn=sqlite:/private/customer.db sql=SELECT secret';
    STTWorker::$failures = [new PDOException($injectedPdoMessage, 9999)];
    runScannerWorker(true, 'fatal PDO recycle', $failures);
    $fatalPdo = scannerWorkerEvent('operation_failed');
    checkScannerWorker(
        ($fatalPdo['context']['scan_phase'] ?? null) === 'stale_workers'
            && ($fatalPdo['context']['database_error_kind'] ?? null) === 'unknown_pdo'
            && ($fatalPdo['context']['consecutive_failures'] ?? null) === 1
            && ($fatalPdo['context']['recovery_action'] ?? null) === 'recycle'
            && ($fatalPdo['context']['recovery_succeeded'] ?? null) === true
            && ($fatalPdo['context']['error_code'] ?? null) === 'pdo_exception',
        'fatal PDO recycle lost its bounded recovery outcome',
        $failures
    );
    checkScannerWorker(
        (scannerWorkerEvent('scanner_stopped')['context']['stop_reason'] ?? null) === 'database_recycle',
        'fatal PDO recycle did not stop with database_recycle',
        $failures
    );
    checkScannerWorker(
        STTJob::$requeueStaleProcessingCalls === 0
            && CallDetailRecords::$findCalls === 0
            && CallTranscriptRevisionService::$publishReadyCalls === 0,
        'fatal PDO recycle ran a later scanner collaborator',
        $failures
    );
    checkScannerWorker(ScannerWorkerSleepProbe::$seconds === [], 'fatal PDO recycle used normal sleep', $failures);
    checkLifecycleLeasePairs(1, 'fatal PDO recycle', $failures);
    foreach (LocalSpeechToTextLogger::$events as $event) {
        checkScannerWorker(
            !str_contains(json_encode($event, JSON_THROW_ON_ERROR), $injectedPdoMessage),
            'fatal PDO recycle leaked the injected exception message',
            $failures
        );
    }
    $afterRecycle = new ScannerProcessGuard();
    checkScannerWorker($afterRecycle->acquire(), 'fatal PDO recycle did not release process guard', $failures);
    $afterRecycle->release();

    $documentedPhases = [
        'runtime_pragmas',
        'cdr_schema',
        'stale_workers',
        'stale_leases',
        'delayed_recordings',
        'skipped_recordings',
        'cdr_scan',
        'call_transcript_publish',
        'retention_cleanup',
    ];
    $forbiddenContextKeys = ['exception_message', 'sql', 'dsn', 'database_path', 'trace'];
    foreach ($documentedPhases as $phaseIndex => $phase) {
        $runtimeRoot = $temporaryRoot . '/phase-' . str_replace('_', '-', $phase);
        resetScannerWorkerProbes($runtimeRoot);
        $injectedPhaseMessage = 'injected ' . $phase . ' failure dsn=sqlite:/private/customer/' . $phase . '.db';
        ScannerWorkerProbe::$injectedPhase = $phase;
        ScannerWorkerProbe::$injectedPhaseMessage = $injectedPhaseMessage;
        runScannerWorker(true, $phase . ' phase recycle', $failures);

        $phaseFailure = scannerWorkerEvent('operation_failed');
        $phaseContext = is_array($phaseFailure) ? ($phaseFailure['context'] ?? []) : [];
        checkScannerWorker(
            ($phaseContext['scan_phase'] ?? null) === $phase
                && ($phaseContext['database_error_kind'] ?? null) === 'unknown_pdo'
                && ($phaseContext['consecutive_failures'] ?? null) === 1
                && ($phaseContext['recovery_action'] ?? null) === 'recycle'
                && ($phaseContext['recovery_succeeded'] ?? null) === true
                && ($phaseContext['error_code'] ?? null) === 'pdo_exception',
            $phase . ' did not emit its bounded controlled-recycle context',
            $failures
        );
        foreach ($forbiddenContextKeys as $forbiddenContextKey) {
            checkScannerWorker(
                !array_key_exists($forbiddenContextKey, $phaseContext),
                $phase . ' exposed prohibited context key ' . $forbiddenContextKey,
                $failures
            );
        }
        foreach (LocalSpeechToTextLogger::$events as $event) {
            $encoded = json_encode($event, JSON_THROW_ON_ERROR);
            checkScannerWorker(
                !str_contains($encoded, $injectedPhaseMessage)
                    && !str_contains($encoded, '/private/customer/'),
                $phase . ' leaked injected exception text or path',
                $failures
            );
        }
        checkScannerWorker(
            ScannerWorkerProbe::$visitedPhases === array_slice($documentedPhases, 0, $phaseIndex + 1),
            $phase . ' ran a later collaborator or did not reach the injected phase in order',
            $failures
        );
        checkScannerWorker(
            scannerWorkerEvent('scanner_recycle_requested') !== null,
            $phase . ' did not emit scanner_recycle_requested',
            $failures
        );
        checkScannerWorker(
            (scannerWorkerEvent('scanner_stopped')['context']['stop_reason'] ?? null) === 'database_recycle',
            $phase . ' did not stop with database_recycle',
            $failures
        );
        checkScannerWorker(ScannerWorkerSleepProbe::$seconds === [], $phase . ' used normal scan sleep', $failures);
        checkLifecycleLeasePairs(1, $phase . ' phase recycle', $failures);
    }

    $runtimeRoot = $temporaryRoot . '/guard-acquire-warning';
    resetScannerWorkerProbes($runtimeRoot);
    $injectedWarning = 'injected fopen failure /private/customer/guard.lock';
    ScannerWorkerProbe::$fopenFailureNeedle = 'guard-acquire-warning';
    ScannerWorkerProbe::$filesystemWarning = $injectedWarning;
    $escapedWarnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$escapedWarnings): bool {
        $escapedWarnings[] = [$severity, $message];
        return true;
    });
    try {
        runScannerWorker(true, 'guard acquisition warning', $failures);
    } finally {
        restore_error_handler();
    }
    checkScannerWorker($escapedWarnings === [], 'guard acquisition warning escaped the worker boundary', $failures);
    $guardFailure = scannerWorkerEvent('operation_failed');
    checkScannerWorker(
        ($guardFailure['context']['operation'] ?? null) === 'scanner_process_guard'
            && ($guardFailure['context']['error_code'] ?? null) === 'runtime_exception',
        'guard acquisition warning did not produce a sanitized startup failure',
        $failures
    );
    foreach (LocalSpeechToTextLogger::$events as $event) {
        checkScannerWorker(
            !str_contains(json_encode($event, JSON_THROW_ON_ERROR), $injectedWarning)
                && !str_contains(json_encode($event, JSON_THROW_ON_ERROR), $runtimeRoot),
            'guard acquisition failure leaked filesystem details',
            $failures
        );
    }

    $runtimeRoot = $temporaryRoot . '/guard-state-warning';
    resetScannerWorkerProbes($runtimeRoot);
    $setupGuard = new ScannerProcessGuard();
    checkScannerWorker($setupGuard->acquire(), 'state warning setup guard could not acquire', $failures);
    $setupGuard->recordRecycle();
    $setupGuard->release();
    $injectedWarning = 'injected state read failure /private/customer/recovery-state.json';
    ScannerWorkerProbe::$fopenFailureNeedle = '.scanner-recovery-state.json';
    ScannerWorkerProbe::$filesystemWarning = $injectedWarning;
    $escapedWarnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$escapedWarnings): bool {
        $escapedWarnings[] = [$severity, $message];
        return true;
    });
    try {
        runScannerWorker(true, 'guard state warning', $failures);
    } finally {
        restore_error_handler();
    }
    checkScannerWorker($escapedWarnings === [], 'guard state warning escaped the worker boundary', $failures);
    $guardFailure = scannerWorkerEvent('operation_failed');
    checkScannerWorker(
        ($guardFailure['context']['operation'] ?? null) === 'scanner_process_guard'
            && ($guardFailure['context']['error_code'] ?? null) === 'runtime_exception',
        'guard state warning did not produce a sanitized startup failure',
        $failures
    );
    checkScannerWorker(
        scannerWorkerEvent('scanner_stopped') === null,
        'guard state warning was incorrectly reported as singleton ownership loss',
        $failures
    );
    foreach (LocalSpeechToTextLogger::$events as $event) {
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        checkScannerWorker(
            !str_contains($encoded, $injectedWarning) && !str_contains($encoded, $runtimeRoot),
            'guard state failure leaked filesystem details',
            $failures
        );
    }

    $runtimeRoot = $temporaryRoot . '/backoff-loss';
    resetScannerWorkerProbes($runtimeRoot);
    $setupGuard = new ScannerProcessGuard();
    checkScannerWorker($setupGuard->acquire(), 'backoff setup guard could not acquire', $failures);
    $setupGuard->recordRecycle();
    $setupGuard->release();
    $scannerRuntime = $runtimeRoot . '/ModuleLocalSpeechToText/scanner';
    ScannerWorkerSleepProbe::$handler = static function (int $seconds) use ($scannerRuntime): void {
        if ($seconds === 5) {
            rename($scannerRuntime, $scannerRuntime . '-retained');
            mkdir($scannerRuntime, 0750, true);
            return;
        }
        throw new ScannerWorkerTestStop('unexpected post-backoff work');
    };
    runScannerWorker(true, 'backoff ownership loss', $failures);
    checkScannerWorker(STTWorker::$markStaleOfflineCalls === 0, 'scanner touched DB after backoff ownership loss', $failures);
    checkLifecycleLeasePairs(0, 'backoff ownership loss', $failures);
    checkScannerWorker(
        (scannerWorkerEvent('scanner_stopped')['context']['stop_reason'] ?? null) === 'process_guard_lost',
        'backoff ownership loss did not use process_guard_lost',
        $failures
    );

    $runtimeRoot = $temporaryRoot . '/between-iterations-loss';
    resetScannerWorkerProbes($runtimeRoot);
    STTWorker::$failures = [new RuntimeException('first non-PDO failure'), new RuntimeException('must not run')];
    $scannerRuntime = $runtimeRoot . '/ModuleLocalSpeechToText/scanner';
    $lockPath = $scannerRuntime . '/.scanner-process.lock';
    ScannerWorkerSleepProbe::$handler = static function (int $seconds) use ($lockPath): void {
        if (count(ScannerWorkerSleepProbe::$seconds) === 1) {
            rename($lockPath, $lockPath . '.retained');
            file_put_contents($lockPath, 'replacement');
            chmod($lockPath, 0640);
            return;
        }
        throw new ScannerWorkerTestStop('scanner reached a second normal sleep');
    };
    runScannerWorker(true, 'between-iterations ownership loss', $failures);
    checkScannerWorker(STTWorker::$markStaleOfflineCalls === 1, 'scanner ran another DB iteration after lock loss', $failures);
    checkLifecycleLeasePairs(1, 'between-iterations ownership loss', $failures);
    checkScannerWorker(
        (scannerWorkerEvent('scanner_stopped')['context']['stop_reason'] ?? null) === 'process_guard_lost',
        'between-iterations lock loss did not use process_guard_lost',
        $failures
    );

    $runtimeRoot = $temporaryRoot . '/healthy-state-loss';
    resetScannerWorkerProbes($runtimeRoot);
    $setupGuard = new ScannerProcessGuard();
    checkScannerWorker($setupGuard->acquire(), 'healthy-state setup guard could not acquire', $failures);
    $setupGuard->recordRecycle();
    $setupGuard->release();
    STTWorker::$failures = [null];
    $scannerRuntime = $runtimeRoot . '/ModuleLocalSpeechToText/scanner';
    $lockPath = $scannerRuntime . '/.scanner-process.lock';
    $statePath = $scannerRuntime . '/.scanner-recovery-state.json';
    $injectedWarning = 'injected state publish failure /private/customer/recovery-state.json';
    ScannerWorkerProbe::$renameHandler = static function (string $from, string $to) use (
        $lockPath,
        $statePath,
        $injectedWarning
    ): bool {
        if ($to !== $statePath) {
            return \rename($from, $to);
        }
        \rename($lockPath, $lockPath . '.retained');
        file_put_contents($lockPath, 'replacement');
        chmod($lockPath, 0640);
        trigger_error($injectedWarning, E_USER_WARNING);
        return false;
    };
    ScannerWorkerSleepProbe::$handler = static function (int $seconds): void {
        if ($seconds !== 5) {
            throw new ScannerWorkerTestStop('normal sleep followed healthy-state ownership loss');
        }
    };
    $escapedWarnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$escapedWarnings): bool {
        $escapedWarnings[] = [$severity, $message];
        return true;
    });
    try {
        runScannerWorker(true, 'healthy-state ownership loss', $failures);
    } finally {
        restore_error_handler();
    }
    checkScannerWorker($escapedWarnings === [], 'healthy-state filesystem warning escaped guard', $failures);
    checkScannerWorker(STTWorker::$markStaleOfflineCalls === 1, 'healthy-state ownership test did not complete one iteration', $failures);
    checkScannerWorker(ScannerWorkerSleepProbe::$seconds === [5], 'healthy-state ownership loss used normal sleep', $failures);
    checkLifecycleLeasePairs(1, 'healthy-state ownership loss', $failures);
    checkScannerWorker(
        (scannerWorkerEvent('scanner_stopped')['context']['stop_reason'] ?? null) === 'process_guard_lost',
        'healthy-state ownership loss did not stop with process_guard_lost',
        $failures
    );
    foreach (LocalSpeechToTextLogger::$events as $event) {
        checkScannerWorker(
            !str_contains(json_encode($event, JSON_THROW_ON_ERROR), $injectedWarning),
            'healthy-state ownership loss leaked filesystem warning text',
            $failures
        );
    }

    $runtimeRoot = $temporaryRoot . '/writer-busy';
    resetScannerWorkerProbes($runtimeRoot);
    \Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabaseWriterMutex::$failures = [
        new \Modules\ModuleLocalSpeechToText\Lib\Exceptions\ModuleDatabaseWriterBusyException('busy'),
    ];
    runScannerWorker(false, 'writer mutex contention', $failures);
    checkScannerWorker(count(scannerWorkerEvents('database_writer_busy')) === 1, 'writer contention was not logged as deferred', $failures);
    checkScannerWorker(scannerWorkerEvents('operation_failed') === [], 'writer contention entered database failure recovery', $failures);
    checkScannerWorker(STTWorker::$markStaleOfflineCalls === 0, 'writer contention entered scanner write phases', $failures);
    checkLifecycleLeasePairs(1, 'writer mutex contention', $failures);

    $runtimeRoot = $temporaryRoot . '/busy-threshold';
    resetScannerWorkerProbes($runtimeRoot);
    STTWorker::$failures = [scannerBusyFailure(), scannerBusyFailure(), scannerBusyFailure()];
    ScannerWorkerSleepProbe::$handler = static function (int $seconds): void {
        if ($seconds !== 15 || count(ScannerWorkerSleepProbe::$seconds) > 2) {
            throw new ScannerWorkerTestStop('unexpected busy-threshold sleep');
        }
    };
    runScannerWorker(true, 'BUSY threshold recycle', $failures);
    $busyEvents = scannerWorkerEvents('operation_failed');
    checkScannerWorker(
        array_map(static fn (array $event): mixed => $event['context']['consecutive_failures'] ?? null, $busyEvents)
            === [1, 2, 3],
        'BUSY/BUSY/third failure did not preserve consecutive counts',
        $failures
    );
    checkScannerWorker(ScannerWorkerSleepProbe::$seconds === [15, 15], 'BUSY recycle slept after third failure', $failures);
    checkLifecycleLeasePairs(3, 'BUSY threshold recycle', $failures);

    $runtimeRoot = $temporaryRoot . '/busy-reset';
    resetScannerWorkerProbes($runtimeRoot);
    STTWorker::$failures = [scannerBusyFailure(), null, scannerBusyFailure()];
    ScannerWorkerSleepProbe::$handler = static function (int $seconds): void {
        if (count(ScannerWorkerSleepProbe::$seconds) >= 3) {
            throw new ScannerWorkerTestStop('busy reset observed');
        }
    };
    runScannerWorker(false, 'BUSY success reset', $failures);
    $busyEvents = scannerWorkerEvents('operation_failed');
    checkScannerWorker(
        array_map(static fn (array $event): mixed => $event['context']['consecutive_failures'] ?? null, $busyEvents)
            === [1, 1],
        'successful iteration did not reset the BUSY series',
        $failures
    );
    checkLifecycleLeasePairs(3, 'BUSY success reset', $failures);

    $runtimeRoot = $temporaryRoot . '/non-pdo';
    resetScannerWorkerProbes($runtimeRoot);
    STTWorker::$failures = [new RuntimeException('ordinary scanner failure')];
    runScannerWorker(false, 'non-PDO continue', $failures);
    $nonPdo = scannerWorkerEvent('operation_failed');
    checkScannerWorker(
        ($nonPdo['context']['database_error_kind'] ?? null) === 'not_pdo'
            && ($nonPdo['context']['recovery_action'] ?? null) === 'continue',
        'non-PDO worker failure did not continue after recovery',
        $failures
    );
    checkLifecycleLeasePairs(1, 'non-PDO continue', $failures);

    $runtimeRoot = $temporaryRoot . '/recovery-failure';
    resetScannerWorkerProbes($runtimeRoot);
    STTWorker::$failures = [scannerBusyFailure()];
    ScannerWorkerConnection::$recoveryMustFail = true;
    runScannerWorker(true, 'recovery failure recycle', $failures);
    $recoveryFailure = scannerWorkerEvent('operation_failed');
    checkScannerWorker(
        ($recoveryFailure['context']['recovery_action'] ?? null) === 'recycle'
            && ($recoveryFailure['context']['recovery_succeeded'] ?? null) === false,
        'recovery failure did not request recycle',
        $failures
    );
    checkScannerWorker(ScannerWorkerSleepProbe::$seconds === [], 'recovery failure recycle used normal sleep', $failures);
    checkLifecycleLeasePairs(1, 'recovery failure recycle', $failures);

    $workerSource = (string)file_get_contents($root . '/bin/WorkerLocalSpeechToTextJobs.php');
    checkScannerWorker(
        !str_contains($workerSource, 'ModulesDBConnectionsProvider')
            && !str_contains($workerSource, 'recreateModulesDBConnections'),
        'worker crossed the module boundary to recreate framework connections',
        $failures
    );

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, "FAIL: {$failure}\n");
        }
        exit(1);
    }

    echo "Scanner worker recycle behavior OK\n";
}
