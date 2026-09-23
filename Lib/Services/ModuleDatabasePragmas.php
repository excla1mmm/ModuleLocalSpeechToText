<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use JsonException;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\Util;
use PDO;
use RuntimeException;
use SQLite3;
use Throwable;

final class ModuleDatabasePragmas
{
    public const string SAFE_BACKUP_MARKER = '.stt-safe-backup-v1.json';

    private const int BUSY_TIMEOUT_MILLISECONDS = 5000;
    private const int AUTO_VACUUM_INCREMENTAL = 2;
    private const int MAX_RELEASED_PAGES = 50_000;
    private const int VACUUM_SPACE_RESERVE_BYTES = 67_108_864;
    private const string CAPABILITY = 'sqlite-backup-api-v1';
    private const array SAFE_FILESYSTEMS = [
        'ext2/ext3',
        'ext4',
        'xfs',
        'btrfs',
        'apfs',
        'zfs',
        'ufs',
        'tmpfs',
        'overlay',
        'overlayfs',
    ];

    public function __construct(
        private readonly mixed $filesystemTypeResolver = null,
        private readonly mixed $warningLogger = null,
        private readonly mixed $freeSpaceResolver = null
    ) {
        foreach ([$this->filesystemTypeResolver, $this->warningLogger, $this->freeSpaceResolver] as $callback) {
            if ($callback !== null && !is_callable($callback)) {
                throw new RuntimeException('Module database pragma callback must be callable');
            }
        }
    }

    public function configureRuntime(mixed $connection): void
    {
        $this->execute($connection, 'PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MILLISECONDS);
        if ((int)$this->scalar($connection, 'PRAGMA busy_timeout') !== self::BUSY_TIMEOUT_MILLISECONDS) {
            throw new RuntimeException('SQLite busy timeout was not applied');
        }

        if (strtolower((string)$this->scalar($connection, 'PRAGMA journal_mode')) === 'wal') {
            $this->execute($connection, 'PRAGMA synchronous = NORMAL');
            if ((int)$this->scalar($connection, 'PRAGMA synchronous') !== 1) {
                throw new RuntimeException('SQLite WAL synchronous mode was not applied');
            }
        }
    }

    public function enableWalAfterVerifiedSetup(mixed $connection, string $databasePath): bool
    {
        $this->configureRuntime($connection);
        if (!$this->hasSafeBackupCapability(dirname($databasePath))) {
            return false;
        }
        if (!is_file($databasePath) || is_link($databasePath)) {
            $this->warn('WAL was not enabled because the STT database path is unsafe');
            return false;
        }

        $filesystem = strtolower(trim($this->filesystemType($databasePath)));
        if (!in_array($filesystem, self::SAFE_FILESYSTEMS, true)) {
            $this->warn('WAL was not enabled on unsupported filesystem: ' . ($filesystem ?: 'unknown'));
            return false;
        }

        try {
            $journalMode = strtolower((string)$this->scalar($connection, 'PRAGMA journal_mode=WAL'));
            if ($journalMode !== 'wal') {
                throw new RuntimeException('SQLite returned journal mode ' . ($journalMode ?: 'unknown'));
            }
            $this->configureRuntime($connection);

            return true;
        } catch (Throwable $exception) {
            $this->warn('Unable to enable STT WAL mode: ' . $exception->getMessage());
            try {
                $restoredMode = strtolower((string)$this->scalar($connection, 'PRAGMA journal_mode=DELETE'));
                if ($restoredMode !== 'delete') {
                    $this->warn('SQLite DELETE journal mode could not be restored');
                }
            } catch (Throwable $restoreException) {
                $this->warn('Unable to restore SQLite DELETE mode: ' . $restoreException->getMessage());
            }

            return false;
        }
    }

    public function writeSafeBackupCapability(string $databaseDirectory): void
    {
        if (!is_dir($databaseDirectory) || is_link($databaseDirectory)) {
            throw new RuntimeException('STT database directory is unavailable for capability marker');
        }

        $markerPath = $databaseDirectory . '/' . self::SAFE_BACKUP_MARKER;
        if (is_link($markerPath)) {
            throw new RuntimeException('Unsafe STT backup capability marker');
        }
        $temporaryPath = $markerPath . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $contents = json_encode([
            'capability' => self::CAPABILITY,
            'created_at' => time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write STT backup capability marker');
        }
        if (!chmod($temporaryPath, 0640)) {
            unlink($temporaryPath);
            throw new RuntimeException('Unable to set STT backup capability permissions');
        }
        if (!rename($temporaryPath, $markerPath)) {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw new RuntimeException('Unable to publish STT backup capability marker');
        }
    }

    public function checkpoint(mixed $connection): void
    {
        try {
            $rows = $connection->fetchAll('PRAGMA wal_checkpoint(TRUNCATE)', PDO::FETCH_NUM);
            if (isset($rows[0][0]) && (int)$rows[0][0] !== 0) {
                $this->warn('STT WAL checkpoint remained busy');
            }
        } catch (Throwable $exception) {
            $this->warn('Unable to checkpoint STT WAL: ' . $exception->getMessage());
        }
    }

    /**
     * Switches the module database to incremental auto-vacuum once, so pages
     * freed by retention and storage compaction can be returned to the file
     * system. Changing auto_vacuum on an existing database needs a full
     * VACUUM, which temporarily needs about twice the database size on disk;
     * when that space is not available the conversion is skipped (and retried
     * on the next module setup). Freed pages are still reused for new data,
     * so skipping only delays the file shrinking. Never throws.
     */
    public function enableIncrementalAutoVacuum(mixed $connection, string $databasePath): bool
    {
        try {
            if ((int)$this->scalar($connection, 'PRAGMA auto_vacuum') === self::AUTO_VACUUM_INCREMENTAL) {
                $this->releaseFreePages($connection);

                return true;
            }

            $shortage = $this->vacuumSpaceShortage($databasePath, $this->databaseBytes($databasePath));
            if ($shortage !== null) {
                $this->warn('STT database auto-vacuum conversion postponed: ' . $shortage);

                return false;
            }

            $this->execute($connection, 'PRAGMA auto_vacuum = INCREMENTAL');
            $this->execute($connection, 'VACUUM');
            if ((int)$this->scalar($connection, 'PRAGMA auto_vacuum') !== self::AUTO_VACUUM_INCREMENTAL) {
                $this->warn('STT database auto-vacuum mode was not applied');

                return false;
            }
            $this->checkpoint($connection);

            return true;
        } catch (Throwable $exception) {
            $this->warn('Unable to enable STT database auto-vacuum: ' . $exception->getMessage());

            return false;
        }
    }

    /**
     * Returns free pages to the file system when incremental auto-vacuum is
     * enabled. Must run outside a transaction. Never throws.
     *
     * @return int number of free pages released (0 when not applicable)
     */
    public function releaseFreePages(mixed $connection, int $maxPages = self::MAX_RELEASED_PAGES): int
    {
        try {
            if ((int)$this->scalar($connection, 'PRAGMA auto_vacuum') !== self::AUTO_VACUUM_INCREMENTAL) {
                return 0;
            }
            $freePages = (int)$this->scalar($connection, 'PRAGMA freelist_count');
            if ($freePages <= 0) {
                return 0;
            }
            $pages = min($freePages, max(1, $maxPages));
            $connection->fetchAll('PRAGMA incremental_vacuum(' . $pages . ')', PDO::FETCH_NUM);

            return $pages;
        } catch (Throwable $exception) {
            $this->warn('Unable to release STT database free pages: ' . $exception->getMessage());

            return 0;
        }
    }

    private function databaseBytes(string $databasePath): int
    {
        $bytes = 0;
        foreach ([$databasePath, $databasePath . '-wal'] as $path) {
            $size = is_file($path) ? filesize($path) : false;
            $bytes += $size === false ? 0 : $size;
        }

        return $bytes;
    }

    private function vacuumSpaceShortage(string $databasePath, int $databaseBytes): ?string
    {
        // VACUUM writes a temporary copy (temp directory) and the rebuilt
        // database through the WAL (database directory).
        $requirements = [
            dirname($databasePath) => (int)ceil($databaseBytes * 2.2) + self::VACUUM_SPACE_RESERVE_BYTES,
            sys_get_temp_dir() => (int)ceil($databaseBytes * 1.2) + self::VACUUM_SPACE_RESERVE_BYTES,
        ];
        foreach ($requirements as $directory => $required) {
            $free = $this->freeSpaceResolver !== null
                ? ($this->freeSpaceResolver)($directory)
                : @disk_free_space($directory);
            if (!is_numeric($free) || (float)$free < $required) {
                return sprintf(
                    '%s has %s bytes free, %d required',
                    $directory,
                    is_numeric($free) ? (string)(int)$free : 'unknown',
                    $required
                );
            }
        }

        return null;
    }

    public function checkpointAndNormalizeBackup(SQLite3 $database): void
    {
        $database->enableExceptions(true);
        $database->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MILLISECONDS);
        $database->querySingle('PRAGMA wal_checkpoint(TRUNCATE)');
        $journalMode = strtolower((string)$database->querySingle('PRAGMA journal_mode=DELETE'));
        if ($journalMode !== 'delete') {
            throw new RuntimeException('SQLite backup could not enter DELETE journal mode');
        }
        $database->exec('PRAGMA synchronous = FULL');
    }

    private function hasSafeBackupCapability(string $databaseDirectory): bool
    {
        $markerPath = $databaseDirectory . '/' . self::SAFE_BACKUP_MARKER;
        if (!is_file($markerPath) || is_link($markerPath)) {
            return false;
        }
        $contents = file_get_contents($markerPath);
        if ($contents === false) {
            return false;
        }
        try {
            $marker = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_array($marker) && ($marker['capability'] ?? null) === self::CAPABILITY;
    }

    private function filesystemType(string $databasePath): string
    {
        if ($this->filesystemTypeResolver !== null) {
            return (string)($this->filesystemTypeResolver)($databasePath);
        }

        $output = [];
        $status = 0;
        $stat = Util::which('stat');
        Processes::mwExec(
            $stat . ' -f -c %T -- ' . escapeshellarg($databasePath),
            $output,
            $status
        );
        if ($status !== 0 || !isset($output[0])) {
            return 'unknown';
        }

        return (string)$output[0];
    }

    private function scalar(mixed $connection, string $sql): mixed
    {
        $rows = $connection->fetchAll($sql, PDO::FETCH_NUM);

        return $rows[0][0] ?? null;
    }

    private function execute(mixed $connection, string $sql): void
    {
        if ($connection->execute($sql) !== true) {
            throw new RuntimeException('SQLite runtime pragma failed');
        }
    }

    private function warn(string $message): void
    {
        if ($this->warningLogger !== null) {
            ($this->warningLogger)($message);
            return;
        }

        if (class_exists(LocalSpeechToTextLogger::class)) {
            LocalSpeechToTextLogger::event(
                'storage',
                'database_operation_failed',
                'STT database runtime configuration failed',
                LOG_WARNING,
                [
                    'operation' => 'database_runtime_configuration',
                    'error_code' => 'database_pragma_failed',
                ]
            );
            return;
        }

        error_log($message);
    }
}
