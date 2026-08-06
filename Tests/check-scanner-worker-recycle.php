<?php

declare(strict_types=1);

namespace MikoPBX\Common\Models {
    final class ScannerWorkerConnection
    {
        public function isUnderTransaction(): bool
        {
            return false;
        }
    }

    final class CallDetailRecords
    {
        public static int $readConnectionCalls = 0;

        public function getReadConnection(): ScannerWorkerConnection
        {
            self::$readConnectionCalls++;

            return new ScannerWorkerConnection();
        }

        public static function find(array $parameters): object
        {
            throw new \RuntimeException('CDR scan must not run after a fatal scanner failure');
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
        }
    }
}

namespace MikoPBX\Core\System {
    final class Directories
    {
        public const string CORE_TEMP_DIR = 'core_temp';
        public const string AST_MONITOR_DIR = 'ast_monitor';
        public static string $coreTempDirectory = '';

        public static function getDir(string $directory): string
        {
            return self::$coreTempDirectory;
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

namespace Modules\ModuleLocalSpeechToText\Lib\Services {
    final class ScannerWorkerRecycleProbe
    {
        /** @var array<int, array{operation: int, resource_id: int}> */
        public static array $lifecycleLockOperations = [];
        /** @var array<int, int> */
        public static array $lifecycleClosedResources = [];

        public static function resetLifecycle(): void
        {
            self::$lifecycleLockOperations = [];
            self::$lifecycleClosedResources = [];
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
        if (ScannerWorkerRecycleProbe::isLifecycleLease($stream)) {
            ScannerWorkerRecycleProbe::$lifecycleLockOperations[] = [
                'operation' => $operation,
                'resource_id' => get_resource_id($stream),
            ];
        }

        return \flock($stream, $operation, $wouldBlock);
    }

    function fclose(mixed $stream): bool
    {
        if (ScannerWorkerRecycleProbe::isLifecycleLease($stream)) {
            ScannerWorkerRecycleProbe::$lifecycleClosedResources[] = get_resource_id($stream);
        }

        return \fclose($stream);
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
        }
    }

    final class CallTranscriptRevisionService
    {
        public static int $publishReadyCalls = 0;

        /** @return array{published: int, scanned: int, blocked_jobs: int} */
        public function publishReady(): array
        {
            self::$publishReadyCalls++;

            return ['published' => 0, 'scanned' => 0, 'blocked_jobs' => 0];
        }
    }

    final class TranscriptRetentionService
    {
        public function purgeExpired(int $retentionDays, int $now): array
        {
            throw new \RuntimeException('Retention cleanup must not run after a fatal scanner failure');
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

            return 0;
        }
    }

    final class STTSetting
    {
        public const string TRANSCRIPT_RETENTION_LAST_CLEANUP_AT = 'retention_last_cleanup_at';
        public static int $calls = 0;

        public static function transcriptRetentionDays(): int
        {
            self::$calls++;

            return 0;
        }
    }

    final class STTWorker
    {
        public static int $markStaleOfflineCalls = 0;
        public static ?\Throwable $failure = null;

        public static function markStaleOffline(): void
        {
            self::$markStaleOfflineCalls++;
            if (self::$failure !== null) {
                throw self::$failure;
            }
        }
    }
}

namespace Modules\ModuleLocalSpeechToText\bin {
    final class ScannerWorkerSleepProbe
    {
        /** @var array<int, int> */
        public static array $seconds = [];
    }

    function sleep(int $seconds): int
    {
        ScannerWorkerSleepProbe::$seconds[] = $seconds;
        throw new \RuntimeException('Unexpected scanner sleep');
    }

    function pcntl_signal_dispatch(): bool
    {
        return true;
    }
}

namespace {
    use MikoPBX\Common\Models\CallDetailRecords;
    use MikoPBX\Common\Providers\DatabaseProviderBase;
    use MikoPBX\Core\System\Directories;
    use Modules\ModuleLocalSpeechToText\bin\ScannerWorkerSleepProbe;
    use Modules\ModuleLocalSpeechToText\bin\WorkerLocalSpeechToTextJobs;
    use Modules\ModuleLocalSpeechToText\Lib\Services\CallTranscriptRevisionService;
    use Modules\ModuleLocalSpeechToText\Lib\Services\LocalSpeechToTextLogger;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleDatabasePragmas;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ModuleLifecycleGate;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerProcessGuard;
    use Modules\ModuleLocalSpeechToText\Lib\Services\ScannerWorkerRecycleProbe;
    use Modules\ModuleLocalSpeechToText\Models\STTJob;
    use Modules\ModuleLocalSpeechToText\Models\STTSetting;
    use Modules\ModuleLocalSpeechToText\Models\STTWorker;

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
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            removeScannerWorkerTestDirectory($directory . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($directory);
    }

    /** @return array<string, mixed>|null */
    function scannerWorkerEvent(string $event): ?array
    {
        foreach (LocalSpeechToTextLogger::$events as $entry) {
            if (($entry['event'] ?? null) === $event) {
                return $entry;
            }
        }

        return null;
    }

    function resetScannerWorkerProbes(): void
    {
        LocalSpeechToTextLogger::$events = [];
        ModuleDatabasePragmas::$configureRuntimeCalls = 0;
        DatabaseProviderBase::$ensureCdrTablesCalls = 0;
        STTWorker::$markStaleOfflineCalls = 0;
        STTJob::$writeConnectionCalls = 0;
        STTJob::$requeueStaleProcessingCalls = 0;
        CallDetailRecords::$readConnectionCalls = 0;
        CallTranscriptRevisionService::$publishReadyCalls = 0;
        STTSetting::$calls = 0;
        ScannerWorkerSleepProbe::$seconds = [];
        ScannerWorkerRecycleProbe::resetLifecycle();
    }

    $root = dirname(__DIR__);
    $temporaryRoot = sys_get_temp_dir() . '/local-stt-scanner-worker-' . bin2hex(random_bytes(8));
    mkdir($temporaryRoot, 0700, true);
    file_put_contents($temporaryRoot . '/Globals.php', "<?php\n");
    Directories::$coreTempDirectory = $temporaryRoot;

    require_once $root . '/Lib/Services/TransactionRecoveryException.php';
    require_once $root . '/Lib/Services/ModuleTransaction.php';
    require_once $root . '/Lib/Services/ScannerTransactionRecovery.php';
    require_once $root . '/Lib/Services/ScannerDatabaseFailurePolicy.php';
    require_once $root . '/Lib/Services/ScannerIterationFailureHandler.php';
    require_once $root . '/Lib/Services/ScannerProcessGuard.php';
    require_once $root . '/Lib/Services/ModuleLifecycleGate.php';
    require_once $root . '/Lib/Services/LocalSpeechToTextLogSanitizer.php';

    $lifecycleDirectory = $root . '/db';
    $lifecycleDirectoryExisted = is_dir($lifecycleDirectory);
    new ModuleLifecycleGate(null, true);

    register_shutdown_function(
        static function () use ($temporaryRoot, $lifecycleDirectory, $lifecycleDirectoryExisted): void {
            removeScannerWorkerTestDirectory($temporaryRoot);
            if (!$lifecycleDirectoryExisted) {
                removeScannerWorkerTestDirectory($lifecycleDirectory);
            }
        }
    );

    $workingDirectory = getcwd();
    chdir($temporaryRoot);
    require_once $root . '/bin/WorkerLocalSpeechToTextJobs.php';
    chdir($workingDirectory === false ? $root : $workingDirectory);

    $failures = [];

    resetScannerWorkerProbes();
    STTWorker::$failure = new RuntimeException('duplicate scanner must not execute');
    $competingGuard = new ScannerProcessGuard();
    checkScannerWorker($competingGuard->acquire(), 'duplicate setup could not acquire the original scanner lock', $failures);
    try {
        (new WorkerLocalSpeechToTextJobs())->start([]);
    } catch (Throwable $exception) {
        $failures[] = 'duplicate scanner did not return cleanly: ' . get_class($exception);
    }

    checkScannerWorker(
        scannerWorkerEvent('scanner_duplicate_rejected') !== null,
        'duplicate scanner did not emit scanner_duplicate_rejected',
        $failures
    );
    checkScannerWorker(
        ModuleDatabasePragmas::$configureRuntimeCalls === 0
            && DatabaseProviderBase::$ensureCdrTablesCalls === 0
            && STTWorker::$markStaleOfflineCalls === 0
            && STTJob::$requeueStaleProcessingCalls === 0
            && CallTranscriptRevisionService::$publishReadyCalls === 0,
        'duplicate scanner performed database, queue, or publication work',
        $failures
    );
    checkScannerWorker(
        ScannerWorkerRecycleProbe::$lifecycleLockOperations === [],
        'duplicate scanner acquired a lifecycle lease',
        $failures
    );
    $otherGuard = new ScannerProcessGuard();
    checkScannerWorker(!$otherGuard->acquire(), 'duplicate scanner displaced the original lock owner', $failures);
    $otherGuard->release();
    $competingGuard->release();

    resetScannerWorkerProbes();
    $injectedMessage = 'unknown database failure dsn=sqlite:/private/customer.db sql=SELECT secret';
    STTWorker::$failure = new PDOException($injectedMessage, 9999);
    $startedAt = hrtime(true);
    try {
        (new WorkerLocalSpeechToTextJobs())->start([]);
    } catch (Throwable $exception) {
        $failures[] = 'fatal PDO scanner did not recycle cleanly: ' . get_class($exception);
    }
    $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

    $leaseAcquisitions = array_values(array_filter(
        ScannerWorkerRecycleProbe::$lifecycleLockOperations,
        static fn (array $call): bool => in_array(
            $call['operation'],
            [LOCK_SH, LOCK_SH | LOCK_NB],
            true
        )
    ));
    $leaseReleases = array_values(array_filter(
        ScannerWorkerRecycleProbe::$lifecycleLockOperations,
        static fn (array $call): bool => $call['operation'] === LOCK_UN
    ));
    checkScannerWorker(count($leaseAcquisitions) === 1, 'fatal PDO scan did not acquire exactly one write lease', $failures);
    checkScannerWorker(count($leaseReleases) === 1, 'fatal PDO scan did not release exactly one write lease', $failures);
    checkScannerWorker(
        isset($leaseAcquisitions[0], $leaseReleases[0])
            && $leaseAcquisitions[0]['resource_id'] === $leaseReleases[0]['resource_id']
            && ScannerWorkerRecycleProbe::$lifecycleClosedResources === [$leaseAcquisitions[0]['resource_id']],
        'fatal PDO scan did not unlock and close the exact acquired lifecycle lease once',
        $failures
    );

    $operationFailed = scannerWorkerEvent('operation_failed');
    $operationContext = is_array($operationFailed) ? ($operationFailed['context'] ?? []) : [];
    checkScannerWorker(
        ($operationContext['scan_phase'] ?? null) === 'stale_workers'
            && ($operationContext['database_error_kind'] ?? null) === 'unknown_pdo'
            && ($operationContext['consecutive_failures'] ?? null) === 1
            && ($operationContext['recovery_action'] ?? null) === 'recycle'
            && ($operationContext['recovery_succeeded'] ?? null) === true,
        'fatal PDO operation_failed context did not describe the controlled recycle outcome',
        $failures
    );
    checkScannerWorker(
        scannerWorkerEvent('scanner_recycle_requested') !== null,
        'fatal PDO scan did not emit scanner_recycle_requested',
        $failures
    );
    $scannerStopped = scannerWorkerEvent('scanner_stopped');
    checkScannerWorker(
        ($scannerStopped['context']['stop_reason'] ?? null) === 'database_recycle',
        'fatal PDO scan did not stop with database_recycle',
        $failures
    );
    checkScannerWorker(
        STTJob::$requeueStaleProcessingCalls === 0
            && STTSetting::$calls === 0
            && CallTranscriptRevisionService::$publishReadyCalls === 0,
        'fatal PDO scan ran a later queue, scan, publication, or retention collaborator',
        $failures
    );
    checkScannerWorker(
        ScannerWorkerSleepProbe::$seconds === [] && $elapsedSeconds < 2.0,
        'fatal PDO recycle used the normal scanner delay',
        $failures
    );

    $afterRecycle = new ScannerProcessGuard();
    checkScannerWorker($afterRecycle->acquire(), 'fatal PDO recycle did not release the process lock', $failures);
    $afterRecycle->release();

    $requiredContextKeys = [
        'scan_phase',
        'database_error_kind',
        'consecutive_failures',
        'recovery_action',
        'recovery_succeeded',
    ];
    foreach ($requiredContextKeys as $requiredKey) {
        checkScannerWorker(
            array_key_exists($requiredKey, $operationContext),
            'fatal PDO operation_failed context is missing ' . $requiredKey,
            $failures
        );
    }
    checkScannerWorker(
        is_array($scannerStopped) && array_key_exists('stop_reason', $scannerStopped['context'] ?? []),
        'scanner_stopped context is missing stop_reason',
        $failures
    );

    $forbiddenContextKeys = ['exception_message', 'sql', 'dsn', 'database_path', 'trace'];
    foreach (LocalSpeechToTextLogger::$events as $event) {
        $context = is_array($event['context'] ?? null) ? $event['context'] : [];
        foreach ($forbiddenContextKeys as $forbiddenKey) {
            checkScannerWorker(
                !array_key_exists($forbiddenKey, $context),
                'scanner event exposed forbidden context key ' . $forbiddenKey,
                $failures
            );
        }
        foreach ($context as $value) {
            checkScannerWorker(
                !is_string($value) || !str_contains($value, $injectedMessage),
                'scanner event exposed the injected PDO message',
                $failures
            );
        }
    }
    checkScannerWorker(
        ($operationContext['error_code'] ?? null) === 'pdo_exception',
        'fatal PDO failure did not use the bounded sanitizer error code',
        $failures
    );

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
