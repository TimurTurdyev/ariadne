<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Query;

use TimurTurdyev\SimpleSearch\Facets\FacetId;

/**
 * One intent of the plan with its own Query, so an adapter never guesses which filters to drop.
 */
final readonly class PlanIntent
{
    private function __construct(
        public IntentKind $kind,
        public Query $query,
        public ?FacetId $facet = null,
    ) {}

    public static function main(Query $query): self
    {
        return new self(IntentKind::Main, $query);
    }

    /**
     * @param  bool  $filtered  bounds of the selection with only the price dropped, not of the bare scope
     */
    public static function priceBounds(Query $query, bool $filtered = false): self
    {
        return new self(
            IntentKind::PriceBounds,
            $filtered ? $query->withPrice(PriceRange::none()) : $query->structureOnly(),
        );
    }

    public static function palette(Query $query): self
    {
        return new self(IntentKind::Palette, $query->structureOnly());
    }

    public static function whatIf(Query $query, FacetId $facet): self
    {
        return new self(IntentKind::WhatIf, $query->withoutFacet($facet), $facet);
    }

    /**
     * Key of the intent in the engine response; what-if keys include the facet slug.
     */
    public function key(): string
    {
        return $this->facet === null
            ? $this->kind->value
            : $this->kind->value.':'.$this->facet->value;
    }

    /**
     * Every intent but main, and main too when the screen asks for the panel alone.
     */
    public function isAggregateOnly(): bool
    {
        return $this->kind !== IntentKind::Main || ! $this->query->need->wantsDocuments();
    }
}
