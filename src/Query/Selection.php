<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Query;

use TimurTurdyev\SimpleSearch\Facets\FacetId;

/**
 * All facet choices. Immutable: every change returns a copy.
 */
final readonly class Selection
{
    /** @var array<string, FacetChoice> */
    private array $choices;

    /**
     * @param  list<FacetChoice>  $choices
     */
    public function __construct(array $choices = [])
    {
        $indexed = [];

        foreach ($choices as $choice) {
            $indexed[$choice->facet->value] = $choice;
        }

        ksort($indexed, SORT_STRING);

        $this->choices = $indexed;
    }

    public function isEmpty(): bool
    {
        return $this->choices === [];
    }

    public function has(FacetId $facet): bool
    {
        return isset($this->choices[$facet->value]);
    }

    public function choiceFor(FacetId $facet): ?FacetChoice
    {
        return $this->choices[$facet->value] ?? null;
    }

    /**
     * Active facets in a stable order: it defines the order of plan intents and cache keys.
     *
     * @return list<FacetId>
     */
    public function facets(): array
    {
        return array_values(array_map(
            static fn (FacetChoice $choice): FacetId => $choice->facet,
            $this->choices,
        ));
    }

    /**
     * @return list<FacetChoice>
     */
    public function all(): array
    {
        return array_values($this->choices);
    }

    public function with(FacetChoice $choice): self
    {
        $choices = $this->choices;
        $choices[$choice->facet->value] = $choice;

        return new self(array_values($choices));
    }

    public function without(FacetId $facet): self
    {
        $choices = $this->choices;
        unset($choices[$facet->value]);

        return new self(array_values($choices));
    }
}
