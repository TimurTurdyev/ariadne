<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Search;

use TimurTurdyev\Ariadne\Facets\FacetPanel;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Results\PriceBounds;
use TimurTurdyev\Ariadne\Results\ResultPage;

/**
 * A ready catalog screen; prices are already in the caller's scale and must not be scaled again.
 */
final readonly class SearchResult
{
    /**
     * @param  bool  $degraded  built without the engine: search did not answer
     */
    public function __construct(
        public Query $query,
        public ResultPage $page,
        public FacetPanel $panel,
        public PriceBounds $priceBounds,
        public bool $degraded = false,
    ) {}

    /**
     * An honest empty answer.
     */
    public static function empty(Query $query): self
    {
        return new self(
            $query,
            ResultPage::empty($query->page->perPage),
            new FacetPanel,
            PriceBounds::none(),
        );
    }

    /**
     * No answer at all: looks empty, but the user must not be told to reset filters.
     */
    public static function unavailable(Query $query): self
    {
        return new self(
            $query,
            ResultPage::empty($query->page->perPage),
            new FacetPanel,
            PriceBounds::none(),
            degraded: true,
        );
    }

    public function isEmpty(): bool
    {
        return $this->page->isEmpty();
    }
}
