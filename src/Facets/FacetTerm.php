<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Facets;

/**
 * Dictionary term: the integer id stored in index documents, its slugs and what the panel shows.
 */
final readonly class FacetTerm
{
    public function __construct(
        public int $id,
        public FacetId $facet,
        public FacetValueId $value,
        public string $label,
        public ?string $image = null,
        public int $sortOrder = 0,
    ) {}
}
