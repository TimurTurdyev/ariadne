<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

use LogicException;

/**
 * Writes a stream of documents to a table in multi-row statements.
 */
final class Writer
{
    public function __construct(private readonly Connection $engine) {}

    /**
     * A string column needs a string: a number written to it is stored as an empty string.
     *
     * @param  list<string>  $columns  column order of the statement, id included
     * @param  iterable<array<string, int|float|string|list<int>>>  $documents
     * @return int documents written
     */
    public function insert(string $table, array $columns, iterable $documents, int $chunk = 1000): int
    {
        return $this->write('INSERT', $table, $columns, $documents, $chunk);
    }

    /**
     * A string column needs a string: a number written to it is stored as an empty string.
     *
     * @param  list<string>  $columns  column order of the statement, id included
     * @param  iterable<array<string, int|float|string|list<int>>>  $documents
     * @return int documents written
     */
    public function replace(string $table, array $columns, iterable $documents, int $chunk = 1000): int
    {
        return $this->write('REPLACE', $table, $columns, $documents, $chunk);
    }

    /**
     * @param  int|float|string|list<int>  $value
     *
     * @throws LogicException on NAN and INF, which the engine would store as 0
     */
    public static function literal(int|float|string|array $value): string
    {
        if (is_array($value)) {
            return '('.($value === [] ? '' : implode(',', array_map('intval', $value))).')';
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new LogicException('The engine stores NAN and INF as 0: write a finite number.');
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // The engine cuts a string at NUL, so the byte is dropped.
        return "'".str_replace(['\\', "'", "\0"], ['\\\\', "\\'", ''], $value)."'";
    }

    /**
     * @param  list<string>  $columns
     * @param  iterable<array<string, int|float|string|list<int>>>  $documents
     */
    private function write(string $verb, string $table, array $columns, iterable $documents, int $chunk): int
    {
        $head = "{$verb} INTO {$table} (".implode(', ', $columns).') VALUES ';
        $rows = [];
        $written = 0;

        foreach ($documents as $document) {
            $values = [];

            foreach ($columns as $column) {
                $values[] = self::literal(
                    $document[$column] ?? throw new LogicException("Document has no value for column {$column}."),
                );
            }

            $rows[] = '('.implode(', ', $values).')';

            if (count($rows) >= $chunk) {
                $this->engine->execute($head.implode(', ', $rows));
                $written += count($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->engine->execute($head.implode(', ', $rows));
            $written += count($rows);
        }

        return $written;
    }
}
