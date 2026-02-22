<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support;

use LogicException;
use PDOException;
use PDOStatement;
use TimurTurdyev\Ariadne\Manticore\Connection;

/**
 * Engine double: records every statement and answers the reads of a publication from canned tables.
 */
final class FakeEngine extends Connection
{
    /** @var list<string> every statement, in the order it arrived */
    public array $statements = [];

    /** @var array<string, int> tables listed by SHOW TABLES: name => document count */
    public array $tables = [];

    /** @var array<string, string> answers of SHOW CREATE TABLE: name => statement */
    public array $created = [];

    private ?string $failing = null;

    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * Makes every statement that starts with the given text fail.
     */
    public function failOn(string $start): void
    {
        $this->failing = $start;
    }

    public function query(string $sql, array $bindings = []): PDOStatement
    {
        throw new LogicException('FakeEngine has no PDO behind it: use select() or execute().');
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->record($sql);

        if ($sql === 'SHOW TABLES') {
            $rows = [];

            // Like the engine, which keeps names in lower case.
            foreach (array_keys($this->tables) as $table) {
                $rows[] = ['Table' => strtolower($table), 'Type' => 'rt'];
            }

            return $rows;
        }

        if (str_starts_with($sql, 'SELECT COUNT(*) FROM ')) {
            $table = substr($sql, 21);
            $count = array_change_key_case($this->tables)[strtolower($table)]
                ?? throw new PDOException("unknown local table(s) '{$table}' in search request");

            // A string, as a driver without native types returns it.
            return [['count(*)' => (string) $count]];
        }

        if (str_starts_with($sql, 'SHOW CREATE TABLE ')) {
            $table = substr($sql, 18);

            return [['Table' => $table, 'Create Table' => $this->created[$table] ?? "CREATE TABLE {$table} (\nid bigint\n)"]];
        }

        throw new LogicException("FakeEngine has no canned answer for: {$sql}");
    }

    public function execute(string $sql): void
    {
        $this->record($sql);
    }

    public function quote(string $value): string
    {
        throw new LogicException('FakeEngine has no PDO behind it and cannot quote.');
    }

    private function record(string $sql): void
    {
        $this->statements[] = $sql;

        if ($this->failing !== null && str_starts_with($sql, $this->failing)) {
            throw new PDOException("FakeEngine was told to fail on: {$sql}");
        }
    }
}
