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
        private readonly mixed $warningLogger = null
    ) {
        foreach ([$this->filesystemTypeResolver, $this->warningLogger] as $callback) {
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
            LocalSpeechToTextLogger::write('setup', $message, LOG_WARNING);
            return;
        }

        error_log($message);
    }
}
