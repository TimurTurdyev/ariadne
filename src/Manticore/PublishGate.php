<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Manticore;

/**
 * The checks both publications make before switching readers.
 *
 * @internal
 */
final class PublishGate
{
    public function __construct(
        private readonly Connection $engine,
        private readonly int $lockSeconds,
    ) {}

    /**
     * @param  non-empty-list<Table>  $tables
     * @return int documents of the rebuilt main table
     *
     * @throws PublishRefused
     */
    public function check(array $tables, string $built, string $live, int $reported): int
    {
        $existing = null;
        $documents = 0;

        foreach ($tables as $index => $table) {
            $count = $this->count($table->name($built));
            $existing ??= $this->tables();
            $before = isset($existing[strtolower($table->name($live))]) ? $this->count($table->name($live)) : 0;

            $mismatch = $index === 0 && $count !== $reported;

            if ($mismatch || ($count === 0 && $before > 0)) {
                throw new PublishRefused($table->name($built), $reported, $count, $before);
            }

            if ($index === 0) {
                $documents = $count;
            }
        }

        return $documents;
    }

    /**
     * Refuses the switch once the lock may have passed to another rebuild.
     *
     * @param  int|float  $since  hrtime(true) right after the lock was taken
     *
     * @throws PublishRefused
     */
    public function ensureLockHeld(int|float $since, string $built, string $live, int $documents): void
    {
        if ($this->lockSeconds <= 0 || hrtime(true) - $since < $this->lockSeconds * 1_000_000_000) {
            return;
        }

        $before = isset($this->tables()[strtolower($live)]) ? $this->count($live) : 0;

        throw new PublishRefused(
            $built,
            $documents,
            $documents,
            $before,
            "the lock of {$this->lockSeconds} s expired before the switch, another rebuild may have replaced the table",
        );
    }

    private function count(string $table): int
    {
        // The engine names the column "count(*)", so the first field is read.
        $row = $this->engine->select('SELECT COUNT(*) FROM '.$table)[0] ?? [];
        $count = reset($row);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return array<string, true> lowercased names: the engine lowercases them
     */
    private function tables(): array
    {
        $tables = [];

        // Not SHOW TABLES LIKE: "_" in a pattern is a wildcard.
        foreach ($this->engine->select('SHOW TABLES') as $row) {
            $name = reset($row);

            if (is_string($name)) {
                $tables[strtolower($name)] = true;
            }
        }

        return $tables;
    }
}
