<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Sql;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use LogicException;
use TimurTurdyev\SimpleSearch\Facets\FacetId;
use TimurTurdyev\SimpleSearch\Index\CatalogIndex;
use TimurTurdyev\SimpleSearch\Query\IntentKind;
use TimurTurdyev\SimpleSearch\Query\Query;
use TimurTurdyev\SimpleSearch\Query\QueryPlan;
use TimurTurdyev\SimpleSearch\Results\Hit;
use TimurTurdyev\SimpleSearch\Results\IndexResponse;
use TimurTurdyev\SimpleSearch\Results\PriceBounds;

/**
 * The catalog index on plain SQL over a flat facet table; the query text is not run here.
 */
final class SqlCatalogIndex implements CatalogIndex
{
    public function __construct(
        private readonly SqlCatalog $catalog,
        private readonly FacetTable $table,
        private readonly FlatFacetDictionary $dictionary,
    ) {}

    public function execute(QueryPlan $plan): IndexResponse
    {
        $hits = [];
        $total = 0;
        $counts = [];
        $bounds = new PriceBounds;

        foreach ($plan->intents as $intent) {
            $query = $intent->query;
            $base = $this->base($query);

            if ($intent->kind === IntentKind::PriceBounds) {
                $bounds = $this->bounds($base);

                continue;
            }

            if ($intent->kind === IntentKind::Main) {
                $total = $this->total($base, $query);
                $hits = $intent->isAggregateOnly() ? [] : $this->hits($base, $query);
            }

            $counts[$intent->key()] = $query->need->wantsPanel()
                ? $this->counts($base, $query, $intent->facet)
                : [];
        }

        return new IndexResponse($hits, $total, $counts, $bounds);
    }

    private function base(Query $query): QueryBuilder
    {
        $documents = ($this->catalog->documents)();
        $base = clone ($documents instanceof EloquentBuilder ? $documents->toBase() : $documents);

        // The order is the sort of the query alone; one left on the builder would also break the aggregates.
        $base->reorder();

        foreach ($query->scope->constraints as $constraint) {
            $field = $this->catalog->fields[$constraint->field]
                ?? throw new LogicException("Scope field [{$constraint->field}] is not mapped in the SQL catalog.");

            if ($constraint->isEmpty()) {
                $base->whereRaw('1 = 0');
            } elseif ($field instanceof Closure) {
                // Nested, so an OR of the closure stays inside its own brackets.
                $base->where(static function (QueryBuilder $nested) use ($field, $constraint): void {
                    $field($nested, $constraint);
                });
            } else {
                $base->whereIn($this->raw($field), $constraint->values);
            }
        }

        foreach ($query->selection->all() as $choice) {
            $facet = $this->table->keys->name($choice->facet);
            $values = $choice->valueSlugs();

            // "All" wants a document that carries every value: a subquery per value.
            foreach ($choice->isCombined() ? array_chunk($values, 1) : [$values] as $any) {
                $base->whereIn(
                    $this->raw($this->catalog->id),
                    $base->newQuery()
                        ->from($this->table->table)
                        ->where('facet', $facet)
                        ->whereIn('value', $any)
                        ->select($this->table->document),
                );
            }
        }

        if ($query->price->min !== null) {
            $base->where($this->raw($this->catalog->price), '>=', $this->number($query->price->min));
        }

        if ($query->price->max !== null) {
            $base->where($this->raw($this->catalog->price), '<=', $this->number($query->price->max));
        }

        return $base;
    }

    private function total(QueryBuilder $base, Query $query): int
    {
        $group = $this->group($query);
        $count = $group === null ? 'COUNT(*)' : "COUNT(DISTINCT {$group})";

        $row = $this->rows((clone $base)->select($this->raw("{$count} AS total")))[0] ?? [];

        return self::int($row['total'] ?? null);
    }

    /**
     * @return list<Hit>
     */
    private function hits(QueryBuilder $base, Query $query): array
    {
        $id = $this->catalog->id;
        $order = $this->order($query);
        $group = $this->group($query);
        $listing = clone $base;

        if ($group !== null) {
            // One card per group: the row that comes first under the current order and selection.
            $ranked = (clone $base)->select([
                $this->raw("{$id} AS id"),
                $this->raw("ROW_NUMBER() OVER (PARTITION BY {$group} ORDER BY {$order}) AS rn"),
            ]);

            $listing->whereIn(
                $this->raw($id),
                $base->newQuery()->fromSub($ranked, 'ranked')->where('rn', 1)->select('id'),
            );
        }

        $key = Str::afterLast($id, '.');
        $window = [$query->page->perPage, $query->page->offset()];
        $hits = [];

        foreach ($this->rows($listing, " order by {$order} limit ? offset ?", $window) as $row) {
            $document = $row[$key] ?? null;

            if (! is_numeric($document)) {
                throw new LogicException("Document rows carry no [{$key}] column: the documents query has to select the id.");
            }

            $hits[] = new Hit((int) $document, $row);
        }

        return $hits;
    }

    /**
     * @param  FacetId|null  $only  count the values of this facet alone
     * @return array<int, int> term id => count of documents, or of groups when the scope is grouped
     */
    private function counts(QueryBuilder $base, Query $query, ?FacetId $only): array
    {
        $table = $this->table->table;
        // DISTINCT, so a value stored twice for a document is still counted once.
        $counted = $this->group($query) ?? $this->catalog->id;

        $values = (clone $base)
            ->join($table, "{$table}.{$this->table->document}", '=', $this->raw($this->catalog->id))
            ->select(["{$table}.facet as facet", "{$table}.value as value", $this->raw("COUNT(DISTINCT {$counted}) AS total")])
            ->groupBy("{$table}.facet", "{$table}.value");

        if ($only !== null) {
            $values->where("{$table}.facet", $this->table->keys->name($only));
        }

        $counts = [];

        foreach ($this->rows($values) as $row) {
            $term = $this->dictionary->idOf(self::text($row['facet'] ?? null), self::text($row['value'] ?? null));

            if ($term !== null) {
                $counts[$term] = self::int($row['total'] ?? null);
            }
        }

        return $counts;
    }

    private function bounds(QueryBuilder $base): PriceBounds
    {
        $price = $this->catalog->price;

        $row = $this->rows((clone $base)->select($this->raw("MIN({$price}) AS price_min, MAX({$price}) AS price_max")))[0] ?? [];

        $min = $row['price_min'] ?? null;
        $max = $row['price_max'] ?? null;

        return new PriceBounds(
            is_numeric($min) ? (float) $min : null,
            is_numeric($max) ? (float) $max : null,
        );
    }

    private function order(Query $query): string
    {
        $sort = $this->catalog->sorts[$query->sort] ?? $this->catalog->sorts[''] ?? null;

        if ($sort === null) {
            return $this->catalog->id;
        }

        // The id last: with equal sort values the pages and the card of a group would depend on the query plan.
        return ($sort instanceof Closure ? $sort($query) : $sort).', '.$this->catalog->id;
    }

    private function group(Query $query): ?string
    {
        $key = $query->scope->groupBy;

        if ($key === null) {
            return null;
        }

        return $this->catalog->groups[$key]
            ?? throw new LogicException("Grouping key [{$key}] is not mapped in the SQL catalog.");
    }

    /**
     * @param  list<int>  $bindings  values for the placeholders of the tail
     * @return list<array<string, mixed>>
     */
    private function rows(QueryBuilder $query, string $tail = '', array $bindings = []): array
    {
        // The tail is appended as text: orderByRaw() takes a literal string, and the sort comes from the catalog.
        $found = $query->getConnection()->select(
            $query->toSql().$tail,
            [...$query->getBindings(), ...$bindings],
            ! $query->useWritePdo,
        );

        $rows = [];

        foreach ($found as $row) {
            $attributes = [];

            foreach ((array) $row as $column => $value) {
                $attributes[(string) $column] = $value;
            }

            $rows[] = $attributes;
        }

        return $rows;
    }

    private function raw(string $sql): Expression
    {
        return new class($sql) implements Expression
        {
            public function __construct(private readonly string $sql) {}

            public function getValue(Grammar $grammar): string
            {
                return $this->sql;
            }
        };
    }

    private function number(float $bound): Expression
    {
        if (! is_finite($bound)) {
            throw new LogicException('A price bound has to be a finite number.');
        }

        // A literal: Laravel binds a float as text, and SQLite compares a price expression with text as text.
        return $this->raw(sprintf('%.4F', $bound));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
