<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Sql;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueId;

/**
 * The dictionary read from the flat facet table, one query per instance and no cache; term ids mean something only within the instance.
 */
final class FlatFacetDictionary implements FacetDictionary
{
    /** @var array<string, non-empty-list<FacetTerm>>|null facet slug => terms in display order */
    private ?array $terms = null;

    /** @var array<int, FacetTerm> */
    private array $byId = [];

    /** @var array<string, array<string, int>> facet column => value => term id */
    private array $ids = [];

    /**
     * @param  array<string, string>  $titles  facet column => title, in panel order
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FacetTable $table,
        private readonly array $titles = [],
        private readonly LoggerInterface $log = new NullLogger,
    ) {}

    public function facets(): array
    {
        return array_values(array_map(
            static fn (array $terms): FacetId => $terms[0]->facet,
            $this->load(),
        ));
    }

    public function termsFor(FacetId $facet): array
    {
        return $this->load()[$facet->value] ?? [];
    }

    public function resolve(int $termId): ?FacetTerm
    {
        $this->load();

        return $this->byId[$termId] ?? null;
    }

    public function termIdsFor(FacetId $facet, array $values): array
    {
        $wanted = [];

        foreach ($values as $value) {
            $wanted[$value->value] = true;
        }

        $ids = [];

        foreach ($this->termsFor($facet) as $term) {
            if (isset($wanted[$term->value->value])) {
                $ids[] = $term->id;
            }
        }

        return $ids;
    }

    /**
     * A facet without a title is labelled with its own name.
     */
    public function labelFor(FacetId $facet): string
    {
        $name = $this->table->keys->name($facet);

        return $this->titles[$name] ?? $name;
    }

    /**
     * The table may hold several values of any facet for one document.
     */
    public function isMultiValued(FacetId $facet): bool
    {
        return true;
    }

    /**
     * Term id by the facet and the value as the table stores them.
     */
    public function idOf(string $facet, string $value): ?int
    {
        $this->load();

        return $this->ids[$facet][$value] ?? null;
    }

    /**
     * @return array<string, non-empty-list<FacetTerm>>
     */
    private function load(): array
    {
        if ($this->terms !== null) {
            return $this->terms;
        }

        $query = $this->db->table($this->table->table)
            ->select(['facet', 'value'])
            ->selectRaw('MIN(label) AS term_label, MIN(sort_value) AS term_sort')
            ->groupBy('facet', 'value')
            ->orderBy('term_sort')
            ->orderBy('term_label');

        $rows = [];

        foreach ($query->get() as $row) {
            $rows[] = [
                'facet' => self::text($row->facet),
                'value' => self::text($row->value),
                'label' => self::text($row->term_label),
            ];
        }

        // Titled facets first, the rest by name; usort is stable, so values keep the order of the query.
        $rank = array_flip(array_keys($this->titles));

        usort($rows, static fn (array $a, array $b): int => ($rank[$a['facet']] ?? PHP_INT_MAX) <=> ($rank[$b['facet']] ?? PHP_INT_MAX)
            ?: strcmp($a['facet'], $b['facet']));

        $terms = [];
        $byId = [];
        $ids = [];
        $name = null;
        $facet = null;

        foreach ($rows as $row) {
            if ($row['facet'] !== $name) {
                $name = $row['facet'];
                $facet = $this->facet($name);
            }

            if ($facet === null) {
                continue;
            }

            $value = $this->value($name, $row['value']);

            if ($value === null) {
                continue;
            }

            $term = new FacetTerm(
                count($byId) + 1,
                $facet,
                $value,
                $row['label'],
                null,
                count($terms[$facet->value] ?? []),
            );

            $terms[$facet->value][] = $term;
            $byId[$term->id] = $term;
            $ids[$name][$row['value']] = $term->id;
        }

        $this->byId = $byId;
        $this->ids = $ids;

        return $this->terms = $terms;
    }

    private function facet(string $name): ?FacetId
    {
        $facet = $this->table->keys->facet($name);

        // The name has to read back unchanged, or a selection could not be matched against the column.
        if ($facet !== null && $this->table->keys->name($facet) === $name) {
            return $facet;
        }

        $this->log->warning('Facet skipped: its name is not a slug', ['table' => $this->table->table, 'facet' => $name]);

        return null;
    }

    private function value(string $facet, string $value): ?FacetValueId
    {
        try {
            $id = FacetValueId::fromString($value);
        } catch (InvalidArgumentException) {
            $id = null;
        }

        if ($id !== null && $id->value === $value) {
            return $id;
        }

        $this->log->warning('Facet value skipped: it is not a slug', ['table' => $this->table->table, 'facet' => $facet, 'value' => $value]);

        return null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
