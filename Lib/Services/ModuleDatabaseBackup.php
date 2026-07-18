<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use DirectoryIterator;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use Throwable;

/**
 * Creates self-contained module database snapshots without SQLite sidecars.
 */
final class ModuleDatabaseBackup
{
    private const RUNTIME_PREFIXES = [
        '.module-lifecycle-',
        '.setup-database-snapshot-',
    ];

    public function snapshot(string $sourceDirectory, string $destinationDirectory): void
    {
        if (!is_dir($sourceDirectory) || is_link($sourceDirectory)) {
            throw new RuntimeException('Module database source directory is unavailable');
        }

        $parentDirectory = dirname($destinationDirectory);
        $this->ensureDirectory($parentDirectory);
        $preparedDirectory = $this->temporarySibling($destinationDirectory, 'snapshot');

        try {
            $this->ensureDirectory($preparedDirectory);
            $this->copySnapshotContents($sourceDirectory, $preparedDirectory);
            $this->verifyDirectory($preparedDirectory);
            $this->publishDirectory($preparedDirectory, $destinationDirectory);
        } catch (Throwable $exception) {
            $this->bestEffortRemove($preparedDirectory);
            throw $exception;
        }
    }

    public function restore(string $snapshotDirectory, string $destinationDirectory): void
    {
        if (!is_dir($snapshotDirectory) || is_link($snapshotDirectory)) {
            throw new RuntimeException('Module database snapshot directory is unavailable');
        }

        $preparedDirectory = $this->temporarySibling($destinationDirectory, 'restore');

        try {
            // Re-run SQLite backup while restoring so a corrupt or manually changed
            // snapshot can never replace the current database directory.
            $this->snapshot($snapshotDirectory, $preparedDirectory);
            $this->verifyDirectory($preparedDirectory);
            $this->publishDirectory($preparedDirectory, $destinationDirectory);
        } catch (Throwable $exception) {
            $this->bestEffortRemove($preparedDirectory);
            throw $exception;
        }
    }

    public function discard(string $snapshotDirectory): void
    {
        $this->removeIfPresent($snapshotDirectory);
    }

    public function verify(string $databasePath): void
    {
        if (!is_file($databasePath) || is_link($databasePath)) {
            throw new RuntimeException('SQLite database is unavailable for verification: ' . basename($databasePath));
        }

        $database = new SQLite3($databasePath, SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true);

        try {
            $result = $database->query('PRAGMA integrity_check');
            if (!$result instanceof SQLite3Result) {
                throw new RuntimeException('SQLite integrity check could not be started');
            }

            $rows = [];
            while (($row = $result->fetchArray(SQLITE3_NUM)) !== false) {
                $rows[] = strtolower(trim((string)($row[0] ?? '')));
            }
            $result->finalize();

            if ($rows !== ['ok']) {
                throw new RuntimeException(
                    'SQLite integrity check failed: ' . implode('; ', array_filter($rows))
                );
            }
        } finally {
            $database->close();
        }
    }

    private function copySnapshotContents(string $sourceDirectory, string $destinationDirectory): void
    {
        foreach (new DirectoryIterator($sourceDirectory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $name = $entry->getFilename();
            if ($this->isExcludedRuntimeFile($name)) {
                continue;
            }
            if ($entry->isLink()) {
                throw new RuntimeException('Symbolic links are not allowed in module database snapshots');
            }

            $sourcePath = $entry->getPathname();
            $destinationPath = $destinationDirectory . '/' . $name;
            if ($entry->isDir()) {
                $this->ensureDirectory($destinationPath);
                $this->copySnapshotContents($sourcePath, $destinationPath);
                continue;
            }
            if (!$entry->isFile()) {
                throw new RuntimeException('Unsupported entry in module database directory: ' . $name);
            }

            if (str_ends_with($name, '.db')) {
                $this->backupDatabase($sourcePath, $destinationPath);
                continue;
            }
            if (!copy($sourcePath, $destinationPath)) {
                throw new RuntimeException('Unable to copy module database state: ' . $name);
            }
        }
    }

    private function backupDatabase(string $sourcePath, string $destinationPath): void
    {
        $source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);
        $destination = new SQLite3($destinationPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $source->enableExceptions(true);
        $destination->enableExceptions(true);

        try {
            if (!$source->backup($destination)) {
                throw new RuntimeException('SQLite backup failed: ' . basename($sourcePath));
            }
            $journalMode = strtolower((string)$destination->querySingle('PRAGMA journal_mode=DELETE'));
            if ($journalMode !== 'delete') {
                throw new RuntimeException('SQLite backup could not be normalized to DELETE journal mode');
            }
        } finally {
            $destination->close();
            $source->close();
        }

        $this->verify($destinationPath);
    }

    private function verifyDirectory(string $directory): void
    {
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new RuntimeException('Symbolic links are not allowed in module database snapshots');
            }
            if ($entry->isDir()) {
                $this->verifyDirectory($entry->getPathname());
                continue;
            }
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.db')) {
                $this->verify($entry->getPathname());
            }
        }
    }

    private function publishDirectory(string $preparedDirectory, string $destinationDirectory): void
    {
        if (!file_exists($destinationDirectory) && !is_link($destinationDirectory)) {
            if (!rename($preparedDirectory, $destinationDirectory)) {
                throw new RuntimeException('Unable to publish module database snapshot');
            }
            return;
        }
        if (!is_dir($destinationDirectory) || is_link($destinationDirectory)) {
            throw new RuntimeException('Module database destination is not a safe directory');
        }

        $previousDirectory = $this->temporarySibling($destinationDirectory, 'previous');
        if (!rename($destinationDirectory, $previousDirectory)) {
            throw new RuntimeException('Unable to preserve current module database directory');
        }

        if (!rename($preparedDirectory, $destinationDirectory)) {
            if (!rename($previousDirectory, $destinationDirectory)) {
                throw new RuntimeException('Module database replacement and recovery both failed');
            }
            throw new RuntimeException('Unable to replace module database directory');
        }

        $this->bestEffortRemove($previousDirectory);
    }

    private function isExcludedRuntimeFile(string $name): bool
    {
        if (str_ends_with($name, '-wal')
            || str_ends_with($name, '-shm')
            || str_ends_with($name, '-journal')) {
            return true;
        }

        foreach (self::RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function temporarySibling(string $path, string $purpose): string
    {
        return dirname($path)
            . '/.' . basename($path)
            . '.' . $purpose . '-' . getmypid() . '-' . bin2hex(random_bytes(6));
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory) && !is_link($directory)) {
            return;
        }
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException('Unsafe module database directory path: ' . $directory);
        }
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create module database directory: ' . $directory);
        }
    }

    private function removeIfPresent(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $this->removeDirectory($path);
    }

    private function bestEffortRemove(string $path): void
    {
        try {
            $this->removeIfPresent($path);
        } catch (Throwable) {
            // A stale temporary directory is safer than masking the database result.
        }
    }

    private function removeDirectory(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Unable to remove temporary database file');
            }
            return;
        }

        foreach (new DirectoryIterator($path) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $this->removeDirectory($entry->getPathname());
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove temporary database directory');
        }
    }
}
