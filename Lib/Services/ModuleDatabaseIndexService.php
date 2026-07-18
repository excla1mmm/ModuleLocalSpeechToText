<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;
use RuntimeException;

final class ModuleDatabaseIndexService
{
    /**
     * @var array<int, array{table: string, index: string, columns: array<int, string>}>
     */
    private const array REDUNDANT_UNIQUE_INDEXES = [
        [
            'table' => 'm_STTJob',
            'index' => 'i_m_STTJob_file_path',
            'columns' => ['file_path'],
        ],
        [
            'table' => 'm_STTResult',
            'index' => 'i_m_STTResult_job_id',
            'columns' => ['job_id'],
        ],
        [
            'table' => 'm_STTSetting',
            'index' => 'i_m_STTSetting_key',
            'columns' => ['key'],
        ],
        [
            'table' => 'm_STTWorker',
            'index' => 'i_m_STTWorker_worker_uid',
            'columns' => ['worker_uid'],
        ],
    ];

    public function ensure(mixed $connection): void
    {
        if (!method_exists($connection, 'isUnderTransaction')) {
            throw new RuntimeException('Module index connection has no transaction state');
        }
        if ($connection->isUnderTransaction()) {
            $this->apply($connection);
            return;
        }

        (new ModuleTransaction())->run(
            $connection,
            fn (mixed $database): null => $this->apply($database),
            0
        );
    }

    private function apply(mixed $connection): null
    {
        $drops = [];
        foreach (self::REDUNDANT_UNIQUE_INDEXES as $definition) {
            $indexes = $this->indexes($connection, $definition['table']);
            $explicit = $indexes[$definition['index']] ?? null;
            if ($explicit === null) {
                continue;
            }
            if (!$this->matches($explicit, $definition['columns'], true)) {
                throw new RuntimeException(
                    'Known module index has an unexpected definition: ' . $definition['index']
                );
            }

            $hasConstraintCoverage = false;
            foreach ($indexes as $candidate) {
                if ($candidate['origin'] !== 'c'
                    && $this->matches($candidate, $definition['columns'], true)) {
                    $hasConstraintCoverage = true;
                    break;
                }
            }
            if ($hasConstraintCoverage) {
                $drops[] = $definition;
            }
        }

        // Transcript service performs its own full preflight. It sees the
        // surrounding transaction and therefore cannot partially publish.
        (new TranscriptEventIndexService())->ensure($connection);

        foreach ($drops as $definition) {
            if ($connection->execute('DROP INDEX ' . $this->quoteIdentifier($definition['index'])) !== true) {
                throw new RuntimeException('Unable to remove redundant module index: ' . $definition['index']);
            }
        }

        foreach ($drops as $definition) {
            $indexes = $this->indexes($connection, $definition['table']);
            if (isset($indexes[$definition['index']])) {
                throw new RuntimeException('Redundant module index remains: ' . $definition['index']);
            }
            $covered = false;
            foreach ($indexes as $candidate) {
                if ($candidate['origin'] !== 'c'
                    && $this->matches($candidate, $definition['columns'], true)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                throw new RuntimeException('Unique module constraint coverage was lost');
            }
        }

        return null;
    }

    /**
     * @return array<string, array{unique: bool, origin: string, partial: bool, columns: array<int, string>}>
     */
    private function indexes(mixed $connection, string $table): array
    {
        $escapedTable = str_replace("'", "''", $table);
        $tableRows = $connection->fetchAll(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table",
            PDO::FETCH_ASSOC,
            ['table' => $table]
        );
        if ($tableRows === []) {
            throw new RuntimeException('Module index table is missing: ' . $table);
        }

        $indexes = [];
        foreach ($connection->fetchAll("PRAGMA index_list('{$escapedTable}')", PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['name'] ?? '');
            $escapedName = str_replace("'", "''", $name);
            $columns = [];
            foreach ($connection->fetchAll("PRAGMA index_info('{$escapedName}')", PDO::FETCH_ASSOC) as $column) {
                $columns[] = (string)($column['name'] ?? '');
            }
            $indexes[$name] = [
                'unique' => (int)($row['unique'] ?? 0) === 1,
                'origin' => (string)($row['origin'] ?? 'c'),
                'partial' => (int)($row['partial'] ?? 0) === 1,
                'columns' => $columns,
            ];
        }

        return $indexes;
    }

    /**
     * @param array{unique: bool, origin: string, partial: bool, columns: array<int, string>} $index
     * @param array<int, string> $columns
     */
    private function matches(array $index, array $columns, bool $unique): bool
    {
        return $index['unique'] === $unique
            && $index['partial'] === false
            && $index['columns'] === $columns;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
