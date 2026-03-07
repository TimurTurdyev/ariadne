<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * The facet tables: their names and the DDL of the generation tables, in one place.
 *
 * Groups and values keep stable ids shared by every generation; rows are built per generation
 * and published by moving the pointer in the catalogs table.
 *
 * @internal
 */
final readonly class Tables
{
    /** Seconds a DROP waits for readers before it gives up until the next pass. */
    private const DROP_LOCK_SECONDS = 2;

    public function __construct(
        public Connection $db,
        public string $prefix,
    ) {}

    public function catalogs(): string
    {
        return $this->prefix.'catalogs';
    }

    public function groups(): string
    {
        return $this->prefix.'groups';
    }

    public function values(): string
    {
        return $this->prefix.'values';
    }

    public function changes(): string
    {
        return $this->prefix.'changes';
    }

    public function rows(string $catalog, int $generation): string
    {
        return $this->prefix.'rows_'.$catalog.'_'.$generation;
    }

    public function table(string $name): Builder
    {
        return $this->db->table($name);
    }

    /**
     * The state of a catalog; a catalog never built reads as generation 0.
     *
     * @param  bool  $write  read from the write connection: a lagging replica would give the previous pointer
     */
    public function state(string $catalog, bool $write = false): State
    {
        $query = $this->db->table($this->catalogs())->where('catalog', $catalog);

        if ($write) {
            $query->useWritePdo();
        }

        return State::fromRow($query->first());
    }

    public function ensureState(string $catalog): void
    {
        $this->db->table($this->catalogs())->insertOrIgnore(['catalog' => $catalog]);
    }

    /**
     * Generations that have a table in the current schema, published or not.
     *
     * @return list<int>
     */
    public function generations(string $catalog): array
    {
        $own = $this->prefix.'rows_'.$catalog.'_';
        $found = [];
        $schema = $this->db->getSchemaBuilder();

        // Without a schema Laravel lists the tables of every database of a MariaDB server.
        foreach ($schema->getTableListing($schema->getCurrentSchemaListing(), false) as $table) {
            if (str_starts_with($table, $own) && ctype_digit(substr($table, strlen($own)))) {
                $found[] = (int) substr($table, strlen($own));
            }
        }

        sort($found);

        return $found;
    }

    /**
     * A generation table with its primary key only; the secondary index waits for the fill.
     */
    public function create(string $catalog, int $generation): void
    {
        $rows = $this->quote($this->rows($catalog, $generation));
        $pk = $this->quote($this->rows($catalog, $generation).'_pk');

        $this->db->statement(match ($this->db->getDriverName()) {
            'sqlite' => "CREATE TABLE {$rows} (document_id INTEGER NOT NULL, group_id INTEGER NOT NULL, value_id INTEGER NOT NULL, covers INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (document_id, group_id, value_id)) WITHOUT ROWID",
            'mysql', 'mariadb' => "CREATE TABLE {$rows} (document_id BIGINT UNSIGNED NOT NULL, group_id INT UNSIGNED NOT NULL, value_id BIGINT NOT NULL, covers TINYINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (document_id, group_id, value_id)) ENGINE=InnoDB",
            default => "CREATE TABLE {$rows} (document_id BIGINT NOT NULL, group_id INTEGER NOT NULL, value_id BIGINT NOT NULL, covers SMALLINT NOT NULL DEFAULT 0, CONSTRAINT {$pk} PRIMARY KEY (document_id, group_id, value_id) INCLUDE (covers))",
        });
    }

    /**
     * The value index goes on after the fill: one sort instead of a million inserts into a tree.
     */
    public function index(string $catalog, int $generation): void
    {
        $rows = $this->rows($catalog, $generation);

        $this->db->statement('CREATE INDEX '.$this->quote($rows.'_value').' ON '.$this->quote($rows).' (value_id, document_id)');
    }

    /**
     * Planner statistics; on PostgreSQL also the visibility map, or index-only scans visit the heap.
     *
     * @return string|null a warning when the full maintenance could not run
     */
    public function analyze(string $catalog, int $generation): ?string
    {
        $rows = $this->quote($this->rows($catalog, $generation));

        switch ($this->db->getDriverName()) {
            case 'mysql':
            case 'mariadb':
                $this->db->statement("ANALYZE TABLE {$rows}");

                return null;
            case 'pgsql':
                if ($this->db->transactionLevel() > 0) {
                    $this->db->statement("ANALYZE {$rows}");

                    return 'VACUUM cannot run inside a transaction: ANALYZE only, index-only scans may visit the heap until autovacuum';
                }

                $this->db->statement("VACUUM (ANALYZE) {$rows}");

                return null;
            default:
                $this->db->statement('PRAGMA analysis_limit = 1000');
                $this->db->statement("ANALYZE {$rows}");

                return null;
        }
    }

    /**
     * Drops a table, waiting briefly for readers; false when they hold it, so the next pass retries.
     */
    public function drop(string $table): bool
    {
        $quoted = $this->quote($table);

        try {
            match ($this->db->getDriverName()) {
                'mysql', 'mariadb' => $this->dropOnMysql($quoted),
                'pgsql' => $this->db->transaction(function () use ($quoted): void {
                    $this->db->statement("SET LOCAL lock_timeout = '".self::DROP_LOCK_SECONDS."s'");
                    $this->db->statement("DROP TABLE IF EXISTS {$quoted}");
                }),
                default => $this->db->statement("DROP TABLE IF EXISTS {$quoted}"),
            };
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function quote(string $name): string
    {
        return $this->db->getQueryGrammar()->wrapTable($name);
    }

    private function dropOnMysql(string $quoted): void
    {
        $previous = $this->db->selectOne('SELECT @@SESSION.lock_wait_timeout AS seconds');
        $seconds = is_object($previous) && isset($previous->seconds) && is_numeric($previous->seconds) ? (int) $previous->seconds : 31536000;
        $this->db->statement('SET SESSION lock_wait_timeout = '.self::DROP_LOCK_SECONDS);

        try {
            $this->db->statement("DROP TABLE IF EXISTS {$quoted}");
        } finally {
            $this->db->statement('SET SESSION lock_wait_timeout = '.$seconds);
        }
    }
}
