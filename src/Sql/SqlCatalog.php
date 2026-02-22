<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Sql;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use TimurTurdyev\Ariadne\Query\Constraint;
use TimurTurdyev\Ariadne\Query\Query;

/**
 * The application's tables for the SQL index; every string goes into queries as is, so none may come from a request.
 */
final readonly class SqlCatalog
{
    /**
     * @param  Closure(): (EloquentBuilder<*>|QueryBuilder)  $documents  visible documents before any scope or selection; their own order is dropped
     * @param  string  $id  id column with its table, as in "items.id"
     * @param  string  $price  price column with its table, in base units
     * @param  array<string, string|(Closure(QueryBuilder, Constraint): mixed)>  $fields  scope field => column for an IN list, or a closure adding where conditions, which are put in brackets
     * @param  array<string, string|(Closure(Query): string)>  $sorts  sort slug => ORDER BY list, the id is appended as its last key; '' is the default order
     * @param  array<string, string>  $groups  grouping key => expression that names the card of a row, never NULL
     */
    public function __construct(
        public Closure $documents,
        public string $id,
        public string $price,
        public array $fields = [],
        public array $sorts = [],
        public array $groups = [],
    ) {}

    /**
     * ORDER BY expression that keeps the order of the given ids, the rest after them; a CASE, since SQLite has no FIELD().
     *
     * @param  list<int>  $ids
     */
    public static function rankedOrder(string $column, array $ids): string
    {
        // A CASE needs at least one branch; with nothing to rank the column itself is the order.
        if ($ids === []) {
            return $column;
        }

        $branches = '';
        $position = 0;

        foreach ($ids as $id) {
            // The ids become SQL text, so they are integers whatever the caller passed.
            $branches .= ' WHEN '.(int) $id.' THEN '.$position++;
        }

        return 'CASE '.$column.$branches.' ELSE '.$position.' END';
    }
}
