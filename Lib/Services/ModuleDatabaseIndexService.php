<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;
use RuntimeException;

final class ModuleDatabaseIndexService
{
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
        (new TranscriptEventIndexService())->ensure($connection);
        if ($connection->execute(
            'CREATE INDEX IF NOT EXISTS i_m_STTResult_created_at ON m_STTResult (created_at)'
        ) !== true) {
            throw new RuntimeException('Unable to create result processing timestamp index');
        }
        $resultIndexes = $this->indexes($connection, 'm_STTResult');
        if (!isset($resultIndexes['i_m_STTResult_created_at'])
            || !$this->matches($resultIndexes['i_m_STTResult_created_at'], ['created_at'], false)) {
            throw new RuntimeException('Result processing timestamp index has an unexpected definition');
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
}
