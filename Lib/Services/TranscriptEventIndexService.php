<?php

declare(strict_types=1);

namespace Modules\ModuleLocalSpeechToText\Lib\Services;

use PDO;
use RuntimeException;

final class TranscriptEventIndexService
{
    private const string TABLE = 'm_STTTranscriptEvent';
    private const string EVENT_KEY_INDEX = 'm_STTTranscriptEvent_event_key';
    private const string CURSOR_INDEX = 'm_STTTranscriptEvent_event_cursor';
    private const string RESULT_INDEX = 'm_STTTranscriptEvent_result_id';

    public function ensure(mixed $connection): void
    {
        if (!method_exists($connection, 'isUnderTransaction')) {
            throw new RuntimeException('Transcript index connection has no transaction state');
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
        $this->assertTableColumns($connection);
        $indexes = $this->indexes($connection);
        $this->assertCanonicalName($indexes, self::EVENT_KEY_INDEX, ['event_key'], true);
        $this->assertCanonicalName($indexes, self::CURSOR_INDEX, ['created_at', 'id'], false);
        $this->assertCanonicalName($indexes, self::RESULT_INDEX, ['result_id'], false);

        if ($this->matching($indexes, ['event_key'], true) === []) {
            $this->execute(
                $connection,
                'CREATE UNIQUE INDEX ' . self::EVENT_KEY_INDEX . ' ON ' . self::TABLE . ' (event_key)'
            );
        } elseif (!isset($indexes[self::EVENT_KEY_INDEX])
            && $this->autoIndexes($this->matching($indexes, ['event_key'], true)) === []) {
            $this->execute(
                $connection,
                'CREATE UNIQUE INDEX ' . self::EVENT_KEY_INDEX . ' ON ' . self::TABLE . ' (event_key)'
            );
        }
        if (!isset($indexes[self::CURSOR_INDEX])) {
            $this->execute(
                $connection,
                'CREATE INDEX ' . self::CURSOR_INDEX . ' ON ' . self::TABLE . ' (created_at, id)'
            );
        }
        if (!isset($indexes[self::RESULT_INDEX])) {
            $this->execute(
                $connection,
                'CREATE INDEX ' . self::RESULT_INDEX . ' ON ' . self::TABLE . ' (result_id)'
            );
        }

        $indexes = $this->indexes($connection);
        $eventKeyMatches = $this->matching($indexes, ['event_key'], true);
        $autoEventKeys = $this->autoIndexes($eventKeyMatches);
        $eventKeyOwner = $autoEventKeys !== []
            ? array_key_first($autoEventKeys)
            : self::EVENT_KEY_INDEX;

        foreach ($eventKeyMatches as $name => $index) {
            if ($name !== $eventKeyOwner && $index['origin'] === 'c') {
                $this->drop($connection, $name);
            }
        }
        foreach ($this->matching($indexes, ['created_at', 'id'], false) as $name => $index) {
            if ($name !== self::CURSOR_INDEX && $index['origin'] === 'c') {
                $this->drop($connection, $name);
            }
        }
        foreach ($this->matching($indexes, ['result_id'], false) as $name => $index) {
            if ($name !== self::RESULT_INDEX && $index['origin'] === 'c') {
                $this->drop($connection, $name);
            }
        }

        $finalIndexes = $this->indexes($connection);
        if (count($this->matching($finalIndexes, ['event_key'], true)) !== 1
            || count($this->matching($finalIndexes, ['created_at', 'id'], false)) !== 1
            || count($this->matching($finalIndexes, ['result_id'], false)) !== 1) {
            throw new RuntimeException('Transcript event index normalization did not converge');
        }

        return null;
    }

    private function assertTableColumns(mixed $connection): void
    {
        $columns = [];
        foreach ($connection->fetchAll("PRAGMA table_info('" . self::TABLE . "')", PDO::FETCH_ASSOC) as $row) {
            $columns[] = (string)($row['name'] ?? '');
        }
        foreach (['event_key', 'result_id', 'created_at', 'id'] as $required) {
            if (!in_array($required, $columns, true)) {
                throw new RuntimeException('Transcript event table is missing column: ' . $required);
            }
        }
    }

    /**
     * @param array<string, array{unique: bool, origin: string, partial: bool, columns: array<int, string>}> $indexes
     * @param array<int, string> $columns
     */
    private function assertCanonicalName(array $indexes, string $name, array $columns, bool $unique): void
    {
        if (isset($indexes[$name]) && !$this->matches($indexes[$name], $columns, $unique)) {
            throw new RuntimeException('Canonical transcript event index has an unexpected definition: ' . $name);
        }
    }

    /**
     * @return array<string, array{unique: bool, origin: string, partial: bool, columns: array<int, string>}>
     */
    private function indexes(mixed $connection): array
    {
        $indexes = [];
        foreach ($connection->fetchAll("PRAGMA index_list('" . self::TABLE . "')", PDO::FETCH_ASSOC) as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Transcript event index has no name');
            }
            $quotedName = str_replace("'", "''", $name);
            $columns = [];
            foreach ($connection->fetchAll("PRAGMA index_info('{$quotedName}')", PDO::FETCH_ASSOC) as $column) {
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
     * @param array<string, array{unique: bool, origin: string, partial: bool, columns: array<int, string>}> $indexes
     * @param array<int, string> $columns
     * @return array<string, array{unique: bool, origin: string, partial: bool, columns: array<int, string>}>
     */
    private function matching(array $indexes, array $columns, bool $unique): array
    {
        return array_filter(
            $indexes,
            fn (array $index): bool => $this->matches($index, $columns, $unique)
        );
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

    /**
     * @param array<string, array{unique: bool, origin: string, partial: bool, columns: array<int, string>}> $indexes
     * @return array<string, array{unique: bool, origin: string, partial: bool, columns: array<int, string>}>
     */
    private function autoIndexes(array $indexes): array
    {
        return array_filter(
            $indexes,
            static fn (array $index): bool => $index['origin'] !== 'c'
        );
    }

    private function drop(mixed $connection, string $name): void
    {
        $this->execute($connection, 'DROP INDEX ' . $this->quoteIdentifier($name));
    }

    private function execute(mixed $connection, string $sql): void
    {
        if ($connection->execute($sql) !== true) {
            throw new RuntimeException('Transcript event index operation failed');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
