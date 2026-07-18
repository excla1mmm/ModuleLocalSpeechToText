<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;
use RuntimeException;

final class ModuleSchemaMigrator
{
    /** @var array<int, ModuleSchemaMigration> */
    private array $migrations = [];

    /**
     * @param array<int, ModuleSchemaMigration> $migrations
     */
    public function __construct(array $migrations, private readonly mixed $clock = null)
    {
        if ($this->clock !== null && !is_callable($this->clock)) {
            throw new RuntimeException('Schema migration clock must be callable');
        }

        foreach ($migrations as $migration) {
            if (!$migration instanceof ModuleSchemaMigration) {
                throw new RuntimeException('Invalid module schema migration');
            }
            if (isset($this->migrations[$migration->version])) {
                throw new RuntimeException('Duplicate schema migration version: ' . $migration->version);
            }
            $this->migrations[$migration->version] = $migration;
        }
        ksort($this->migrations, SORT_NUMERIC);
    }

    /**
     * @return array<int, int> Applied migration versions.
     */
    public function migrate(mixed $connection, callable $freshSchema): array
    {
        if ($this->isFreshDatabase($connection)) {
            $result = $freshSchema($connection);
            if ($result === false) {
                throw new RuntimeException('Fresh module schema bootstrap failed');
            }
        }

        $this->ensureLedger($connection);
        $applied = $this->appliedMigrations($connection);
        $this->validateAppliedMigrations($applied);

        $completed = [];
        $transaction = new ModuleTransaction();
        foreach ($this->migrations as $version => $migration) {
            if (isset($applied[$version])) {
                continue;
            }

            $transaction->run(
                $connection,
                function (mixed $database) use ($migration): void {
                    if ($migration->apply($database) === false) {
                        throw new RuntimeException(
                            'Schema migration operation failed: ' . $migration->name
                        );
                    }
                    if ($database->execute(
                        'INSERT INTO m_STTSchemaMigration(version, name, checksum, applied_at)
                         VALUES (:version, :name, :checksum, :applied_at)',
                        [
                            'version' => $migration->version,
                            'name' => $migration->name,
                            'checksum' => $migration->checksum(),
                            'applied_at' => $this->now(),
                        ]
                    ) !== true) {
                        throw new RuntimeException(
                            'Unable to record schema migration: ' . $migration->name
                        );
                    }
                },
                0
            );
            $completed[] = $version;
        }

        return $completed;
    }

    private function isFreshDatabase(mixed $connection): bool
    {
        $tables = $connection->fetchAll(
            "SELECT name
               FROM sqlite_master
              WHERE type = 'table'
                AND name NOT LIKE 'sqlite_%'
                AND name <> 'm_STTSchemaMigration'
              LIMIT 1",
            PDO::FETCH_ASSOC
        );

        return $tables === [];
    }

    private function ensureLedger(mixed $connection): void
    {
        if ($connection->execute(
            'CREATE TABLE IF NOT EXISTS m_STTSchemaMigration (
                version INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                checksum TEXT NOT NULL,
                applied_at INTEGER NOT NULL
            )'
        ) !== true) {
            throw new RuntimeException('Unable to create schema migration ledger');
        }
    }

    /**
     * @return array<int, array{name: string, checksum: string}>
     */
    private function appliedMigrations(mixed $connection): array
    {
        $rows = $connection->fetchAll(
            'SELECT version, name, checksum FROM m_STTSchemaMigration ORDER BY version',
            PDO::FETCH_ASSOC
        );
        $applied = [];
        foreach ($rows as $row) {
            $version = (int)($row['version'] ?? 0);
            if ($version < 1 || isset($applied[$version])) {
                throw new RuntimeException('Invalid schema migration ledger');
            }
            $applied[$version] = [
                'name' => (string)($row['name'] ?? ''),
                'checksum' => (string)($row['checksum'] ?? ''),
            ];
        }

        return $applied;
    }

    /**
     * @param array<int, array{name: string, checksum: string}> $applied
     */
    private function validateAppliedMigrations(array $applied): void
    {
        foreach ($applied as $version => $stored) {
            $migration = $this->migrations[$version] ?? null;
            if (!$migration instanceof ModuleSchemaMigration) {
                throw new RuntimeException('Unknown applied schema migration version: ' . $version);
            }
            if ($stored['name'] !== $migration->name
                || !hash_equals($stored['checksum'], $migration->checksum())) {
                throw new RuntimeException('Schema migration history drift detected at version ' . $version);
            }
        }
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int)($this->clock)();
    }
}
