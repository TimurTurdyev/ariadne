<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Closure;
use Illuminate\Database\Connection;
use TimurTurdyev\Ariadne\Catalog\CatalogSpec;

/**
 * Writes the rows of changed documents into a generation: the code of a sync and of the replay before a flip.
 *
 * Each portion is one short transaction: root rows locked in id order, documents read after the lock,
 * their rows deleted by document and written anew. A document that is gone only loses its rows.
 *
 * @internal
 */
final readonly class Applier
{
    public const DOCUMENTS = 100;

    /** Seconds a sync waits for a row lock before it leaves the marks for the next pass. */
    private const LOCK_SECONDS = 2;

    private const INSERT_BATCH = 1000;

    public function __construct(
        private CatalogSpec $spec,
        private Tables $tables,
    ) {}

    /**
     * @param  list<int>  $documents
     * @return list<int> ids of the values written
     */
    public function apply(Projector $projector, int $generation, array $documents): array
    {
        $documents = array_values(array_unique($documents));
        sort($documents);
        $written = [];

        foreach (array_chunk($documents, self::DOCUMENTS) as $chunk) {
            array_push($written, ...$this->withShortLockWait(fn (): array => $this->portion($projector, $generation, $chunk)));
        }

        return $written;
    }

    /**
     * The eager loads of every facet in the form Eloquent takes.
     *
     * @return array<int|string, string|(Closure(mixed): mixed)>
     */
    public function relations(): array
    {
        $with = [];

        foreach ($this->spec->relations as $path => $constraint) {
            if ($constraint === null) {
                $with[] = $path;
            } else {
                $with[$path] = $constraint;
            }
        }

        return $with;
    }

    /**
     * @param  list<int>  $documents
     * @return list<int>
     */
    private function portion(Projector $projector, int $generation, array $documents): array
    {
        $db = $this->tables->db;

        return $db->transaction(function () use ($db, $projector, $generation, $documents): array {
            if ($db->getDriverName() === 'pgsql') {
                $db->statement("SET LOCAL lock_timeout = '".self::LOCK_SECONDS."s'");
            }

            $model = $this->spec->model;
            $key = $model->getKeyName();
            $model->newQueryWithoutScopes()->whereKey($documents)->orderBy($key)->lockForUpdate()->get([$key]);
            // Inside the transaction Laravel reads from the write connection, eager loads too.
            $found = $model->newQuery()->with($this->relations())->whereKey($documents)->get();
            $rows = $projector->rows($found);
            $table = $this->tables->rows($this->spec->name, $generation);

            $this->tables->table($table)->whereIn('document_id', $documents)->delete();

            foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
                $this->tables->table($table)->insert($batch);
            }

            return array_column($rows, 'value_id');
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function withShortLockWait(Closure $work): mixed
    {
        $db = $this->tables->db;

        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $work();
        }

        $previous = $this->mysqlLockWait($db);
        $db->statement('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_SECONDS);

        try {
            return $work();
        } finally {
            $db->statement('SET SESSION innodb_lock_wait_timeout = '.$previous);
        }
    }

    private function mysqlLockWait(Connection $db): int
    {
        $row = $db->selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds');

        return is_object($row) && isset($row->seconds) && is_numeric($row->seconds) ? (int) $row->seconds : 50;
    }
}
