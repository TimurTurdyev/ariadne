<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Facets;

/**
 * A panel value with its count; a selected value is never dimmed, so it can always be removed.
 */
final readonly class FacetValueCount
{
    public function __construct(
        public FacetTerm $term,
        public int $count,
        public bool $selected,
    ) {}

    public function isDisabled(): bool
    {
        return $this->count <= 0 && ! $this->selected;
    }
}
