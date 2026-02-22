<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Tests\Support;

use LogicException;
use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueId;

/**
 * In-memory dictionary for tests; term ids are handed out from 1 in the order values are added.
 */
final class InMemoryFacetDictionary implements FacetDictionary
{
    /** @var array<string, list<FacetTerm>> */
    private array $terms = [];

    /** @var array<string, string> */
    private array $labels = [];

    /** @var array<int, FacetTerm> */
    private array $byId = [];

    /** @var array<string, bool> */
    private array $singleValued = [];

    private int $nextId = 1;

    /**
     * @param  array<int|string, string>  $values  value slug => label; a numeric slug arrives as an integer key
     */
    public function add(string $facet, string $label, array $values): self
    {
        $facetId = FacetId::fromString($facet);
        $terms = [];
        $sort = 0;

        foreach ($values as $slug => $valueLabel) {
            $term = new FacetTerm(
                $this->nextId++,
                $facetId,
                FacetValueId::fromString((string) $slug),
                $valueLabel,
                null,
                $sort++,
            );

            $terms[] = $term;
            $this->byId[$term->id] = $term;
        }

        $this->terms[$facet] = $terms;
        $this->labels[$facet] = $label;

        return $this;
    }

    /**
     * Colors, a multi-valued material for the "all" operator and a brand.
     */
    public static function catalog(): self
    {
        return (new self)
            ->add('color', 'Цвет', [
                'venge' => 'Венге',
                'graphite' => 'Графит',
                'beige' => 'Бежевый',
            ])
            ->add('uphol-material', 'Материал обивки', [
                'kozha' => 'Кожа',
                'tkan' => 'Ткань',
                'setka' => 'Сетка',
            ])
            ->add('brand', 'Бренд', [
                'riva' => 'RIVA',
                'stil' => 'Стиль',
            ]);
    }

    public function facets(): array
    {
        return array_map(
            static fn (string $facet): FacetId => FacetId::fromString($facet),
            array_keys($this->terms),
        );
    }

    public function termsFor(FacetId $facet): array
    {
        return $this->terms[$facet->value] ?? [];
    }

    /**
     * Term id by facet and value slugs, for building an engine response.
     */
    public function termId(string $facet, string $value): int
    {
        foreach ($this->termsFor(FacetId::fromString($facet)) as $term) {
            if ($term->value->value === $value) {
                return $term->id;
            }
        }

        throw new LogicException("Term {$facet}/{$value} is not in the dictionary.");
    }

    public function resolve(int $termId): ?FacetTerm
    {
        return $this->byId[$termId] ?? null;
    }

    public function termIdsFor(FacetId $facet, array $values): array
    {
        $ids = [];

        foreach ($this->termsFor($facet) as $term) {
            foreach ($values as $value) {
                if ($term->value->equals($value)) {
                    $ids[] = $term->id;
                }
            }
        }

        return $ids;
    }

    /**
     * Marks a facet single-valued: no document carries two of its values.
     */
    public function markSingleValued(string $facet): self
    {
        $this->singleValued[$facet] = true;

        return $this;
    }

    public function isMultiValued(FacetId $facet): bool
    {
        return ! isset($this->singleValued[$facet->value]);
    }

    public function labelFor(FacetId $facet): ?string
    {
        return $this->labels[$facet->value] ?? null;
    }
}
