<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use LogicException;
use TimurTurdyev\Ariadne\Catalog\CatalogSpec;
use TimurTurdyev\Ariadne\Catalog\Coverage;
use TimurTurdyev\Ariadne\Catalog\FacetIndex;
use TimurTurdyev\Ariadne\Catalog\Sort;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Query\IntentKind;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Query\QueryPlan;
use TimurTurdyev\Ariadne\Results\Hit;
use TimurTurdyev\Ariadne\Results\IndexResponse;
use TimurTurdyev\Ariadne\Results\PriceBounds;

/**
 * The catalog counted over one generation of integer rows: counts are a semi-join with COUNT(*) per value.
 *
 * Price, scope fields, sorts and visibility are read live from the model table on every screen;
 * only the facet values come from the generation. Identical counts run once per screen.
 *
 * @internal
 */
final class Index implements FacetIndex
{
    /** @var array<string, array{counts: array<int, int>, covers: array<int, int>}> base signature => counts and covers sums of every value */
    private array $memo = [];

    /** @var array<int, int> term id => group id, as the counts found them */
    private array $termGroups = [];

    /** @var array<string, int> base signature => documents */
    private array $totals = [];

    /**
     * @param  bool  $rows  hits carry their table rows, so cards need no second read; false when the screen is cached
     */
    public function __construct(
        private readonly CatalogSpec $spec,
        private readonly Tables $tables,
        private readonly Dictionary $dictionary,
        private readonly bool $rows = true,
    ) {}

    public function execute(QueryPlan $plan): IndexResponse
    {
        $hits = [];
        $total = 0;
        $counts = [];
        $bounds = new PriceBounds;
        $counted = $this->dictionary->generation > 0;

        foreach ($plan->intents as $intent) {
            $query = $intent->query;

            if ($intent->kind === IntentKind::PriceBounds) {
                $bounds = $this->bounds($query);

                continue;
            }

            if ($intent->kind === IntentKind::Main) {
                $total = $this->total($query);
                $hits = $intent->isAggregateOnly() ? [] : $this->hits($query);
            }

            if ($counted && $query->need->wantsPanel()) {
                $counts[$intent->key()] = $intent->facet === null ? $this->counts($query)['counts'] : $this->countsOf($query, $intent->facet);
            }
        }

        return new IndexResponse($hits, $total, $counts, $bounds);
    }

    public function coverage(Query $query, bool $total): Coverage
    {
        if ($this->dictionary->generation === 0) {
            return new Coverage([], $total ? 0 : null);
        }

        $bare = $query->structureOnly();
        $covered = [];

        foreach ($this->counts($bare)['covers'] as $term => $sum) {
            $slug = $this->groupOf($term);

            if ($slug !== null) {
                $covered[$slug] = ($covered[$slug] ?? 0) + $sum;
            }
        }

        return new Coverage($covered, $total ? $this->documents($bare) : null);
    }

    public function groupOf(int $term): ?string
    {
        $group = $this->termGroups[$term] ?? null;

        return $group === null ? null : $this->dictionary->groupById($group)?->slug;
    }

    /**
     * The documents of a query: visible ones, the scope, the selection by term ids and the price.
     */
    private function base(Query $query): Builder
    {
        $model = $this->spec->model;
        $base = clone $this->spec->definition->documents()->toBase();
        $base->reorder();
        $key = $model->qualifyColumn($model->getKeyName());

        foreach ($query->scope->constraints as $constraint) {
            if ($constraint->isEmpty()) {
                $base->whereRaw('1 = 0');

                continue;
            }

            if ($constraint->field === 'id') {
                $base->whereIn($key, $constraint->values);

                continue;
            }

            $field = $this->spec->fields[$constraint->field] ?? throw new LogicException("Scope field [{$constraint->field}] is not declared in #[Fields].");

            if ($field['kind'] === 'belongs-to-many') {
                $base->whereIn($key, $base->newQuery()
                    ->from((string) ($field['pivot'] ?? ''))
                    ->whereIn((string) ($field['related'] ?? ''), $constraint->values)
                    ->select((string) ($field['foreign'] ?? '')));
            } else {
                $base->whereIn($model->qualifyColumn($field['column']), $constraint->values);
            }
        }

        $rows = $this->tables->rows($this->spec->name, $this->dictionary->generation);

        foreach ($query->selection->all() as $choice) {
            $terms = $this->dictionary->generation === 0 ? [] : $this->dictionary->termIdsFor($choice->facet, $choice->values);

            if ($terms === []) {
                $base->whereRaw('1 = 0');

                continue;
            }

            // "All" wants a document that carries every value: a subquery per value.
            foreach ($choice->isCombined() ? array_chunk($terms, 1) : [$terms] as $any) {
                $base->whereIn($key, $base->newQuery()->from($rows)->whereIn('value_id', $any)->select('document_id'));
            }
        }

        if ($this->spec->priceColumn !== null) {
            $price = $model->qualifyColumn($this->spec->priceColumn);

            if ($query->price->min !== null) {
                $base->where($price, '>=', $this->number($query->price->min));
            }

            if ($query->price->max !== null) {
                $base->where($price, '<=', $this->number($query->price->max));
            }
        }

        return $base;
    }

    /**
     * @return array{counts: array<int, int>, covers: array<int, int>}
     */
    private function counts(Query $query): array
    {
        return $this->memo[$this->signature($query)] ??= $this->countRows($query, null);
    }

    /**
     * Counts of one facet; an equal base counted before is reused instead of a second query.
     *
     * @return array<int, int>
     */
    private function countsOf(Query $query, FacetId $facet): array
    {
        $group = $this->dictionary->group($facet->value);

        if ($group === null) {
            return [];
        }

        $memo = $this->memo[$this->signature($query)] ?? null;

        if ($memo !== null) {
            return array_filter($memo['counts'], fn (int $term): bool => ($this->termGroups[$term] ?? null) === $group->id, ARRAY_FILTER_USE_KEY);
        }

        return $this->countRows($query, $group->id)['counts'];
    }

    /**
     * @return array{counts: array<int, int>, covers: array<int, int>}
     */
    private function countRows(Query $query, ?int $group): array
    {
        $base = $this->base($query);
        $key = $this->spec->model->qualifyColumn($this->spec->model->getKeyName());
        $rows = $this->tables->rows($this->spec->name, $this->dictionary->generation);

        if ($this->spec->cardColumn === null) {
            $counts = $base->newQuery()->from($rows)
                ->whereIn('document_id', (clone $base)->select($key))
                ->select(['value_id', $this->raw('MIN(group_id) AS group_id'), $this->raw('COUNT(*) AS n'), $this->raw('SUM(covers) AS c')])
                ->groupBy('value_id');

            if ($group !== null) {
                $counts->where('group_id', $group);
            }
        } else {
            $cards = (clone $base)->select([$this->raw($this->wrap($key).' AS doc'), $this->raw($this->card().' AS card')]);
            $counts = $base->newQuery()->from($rows.' as r')
                ->joinSub($cards, 'b', 'b.doc', '=', 'r.document_id')
                ->select(['r.value_id', $this->raw('MIN(r.group_id) AS group_id'), $this->raw('COUNT(DISTINCT b.card) AS n'), $this->raw('SUM(r.covers) AS c')])
                ->groupBy('r.value_id');

            if ($group !== null) {
                $counts->where('r.group_id', $group);
            }
        }

        // MIN(group_id) rather than a second GROUP BY key: with two keys PostgreSQL gave up its parallel aggregate.
        $found = ['counts' => [], 'covers' => []];

        foreach ($counts->get() as $one) {
            $row = (array) $one;
            $term = self::int($row['value_id'] ?? null);
            $found['counts'][$term] = self::int($row['n'] ?? null);
            $found['covers'][$term] = self::int($row['c'] ?? null);
            $this->termGroups[$term] = self::int($row['group_id'] ?? null);
        }

        return $found;
    }

    private function total(Query $query): int
    {
        $signature = $this->signature($query).($this->spec->cardColumn === null ? '' : '|cards');

        if (isset($this->totals[$signature])) {
            return $this->totals[$signature];
        }

        $expression = $this->spec->cardColumn === null ? 'COUNT(*)' : 'COUNT(DISTINCT '.$this->card().')';
        $row = (array) $this->base($query)->select($this->raw($expression.' AS total'))->first();

        return $this->totals[$signature] = self::int($row['total'] ?? null);
    }

    /**
     * Documents of the bare scope, never cards: coverage counts documents.
     */
    private function documents(Query $bare): int
    {
        $signature = $this->signature($bare);

        if ($this->spec->cardColumn === null && isset($this->totals[$signature])) {
            return $this->totals[$signature];
        }

        $row = (array) $this->base($bare)->select($this->raw('COUNT(*) AS total'))->first();

        return self::int($row['total'] ?? null);
    }

    /**
     * @return list<Hit>
     */
    private function hits(Query $query): array
    {
        $model = $this->spec->model;
        $key = $model->qualifyColumn($model->getKeyName());
        $order = $this->order($query);
        $base = $this->base($query);
        $listing = clone $base;

        if ($this->spec->cardColumn !== null) {
            $leaders = (clone $base)->select([
                $this->raw($this->wrap($key).' AS leader_id'),
                $this->raw('ROW_NUMBER() OVER (PARTITION BY '.$this->card().' ORDER BY '.$order.') AS leader_rank'),
            ]);
            $listing->whereIn($key, $base->newQuery()->fromSub($leaders, 'leaders')->where('leader_rank', 1)->select('leader_id'));
        }

        $listing->select($this->rows ? [$model->getTable().'.*'] : [$key])
            ->orderBy($this->raw($order))
            ->limit($query->page->perPage)
            ->offset($query->page->offset());

        $hits = [];

        foreach ($listing->get() as $one) {
            $attributes = [];

            foreach ((array) $one as $column => $value) {
                $attributes[(string) $column] = $value;
            }

            $hits[] = new Hit(self::int($attributes[$model->getKeyName()] ?? null), $this->rows ? $attributes : []);
        }

        return $hits;
    }

    private function bounds(Query $query): PriceBounds
    {
        if ($this->spec->priceColumn === null) {
            return new PriceBounds;
        }

        $price = $this->wrap($this->spec->model->qualifyColumn($this->spec->priceColumn));
        $row = (array) $this->base($query)->select($this->raw("MIN({$price}) AS price_min, MAX({$price}) AS price_max"))->first();
        $min = $row['price_min'] ?? null;
        $max = $row['price_max'] ?? null;

        return new PriceBounds(is_numeric($min) ? (float) $min : null, is_numeric($max) ? (float) $max : null);
    }

    /**
     * ORDER BY of the query, the key always last; NULLs of a sort column go last unless the sort says otherwise.
     */
    private function order(Query $query): string
    {
        $model = $this->spec->model;
        $key = $this->wrap($model->qualifyColumn($model->getKeyName()));
        $ranked = $query->scope->constraint('id');
        $slug = $query->sort;

        if ($slug === '' || $slug === Sort::RELEVANCE) {
            if ($ranked !== null && $ranked->ranked) {
                return $this->rankedOrder($key, array_map(intval(...), $ranked->values)).', '.$key;
            }

            $slug = $this->spec->defaultSort;
        }

        $sort = $slug === '' ? null : $this->spec->sort($slug);

        if ($sort === null || $sort->relevance) {
            return $key;
        }

        if ($sort->columns === null) {
            return $sort->orderBy.', '.$key;
        }

        $parts = [];

        foreach ($sort->columns as [$column, $direction]) {
            $wrapped = $this->wrap($model->qualifyColumn($column));
            $parts[] = "({$wrapped} IS NULL) ".($sort->nullsFirst ? 'DESC' : 'ASC');
            $parts[] = $wrapped.' '.strtoupper($direction);
        }

        return implode(', ', $parts).', '.$key;
    }

    /**
     * @param  list<int>  $ids
     */
    private function rankedOrder(string $key, array $ids): string
    {
        if ($ids === []) {
            return $key;
        }

        $branches = '';

        foreach ($ids as $position => $id) {
            $branches .= ' WHEN '.$id.' THEN '.$position;
        }

        return 'CASE '.$key.$branches.' ELSE '.count($ids).' END';
    }

    /**
     * A literal: PDO binds a float as text, which PostgreSQL cannot compare with an integer column.
     */
    private function number(float $bound): Expression
    {
        if (! is_finite($bound)) {
            throw new LogicException('A price bound has to be a finite number.');
        }

        return $this->raw(sprintf('%.4F', $bound));
    }

    private function card(): string
    {
        $model = $this->spec->model;

        return 'COALESCE('.$this->wrap($model->qualifyColumn((string) $this->spec->cardColumn)).', -'.$this->wrap($model->qualifyColumn($model->getKeyName())).')';
    }

    private function signature(Query $query): string
    {
        $choices = [];

        foreach ($query->selection->all() as $choice) {
            $choices[] = $choice->facet->value.':'.$choice->operator->value.':'.implode(',', $choice->valueSlugs());
        }

        return implode('|', [$query->scope->signature(), implode(';', $choices), $query->price->min.'-'.$query->price->max]);
    }

    private function wrap(string $column): string
    {
        return $this->tables->db->getQueryGrammar()->wrap($column);
    }

    private function raw(string $sql): Expression
    {
        return new Sql($sql);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
