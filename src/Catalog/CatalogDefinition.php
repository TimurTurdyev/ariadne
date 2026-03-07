<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One catalog described once: what is listed, how it is filtered and how it is sorted.
 *
 * Facts of the schema go in class attributes: Price, Fields, OneCardPer.
 *
 * @template TModel of Model
 */
interface CatalogDefinition
{
    /**
     * Visible documents and the relations a card loads; no select, no joins, no order.
     *
     * @return Builder<TModel>
     */
    public function documents(): Builder;

    /**
     * Facets in panel order; a dynamic facet puts its groups in its own place.
     *
     * @return list<Facet>
     */
    public function facets(): array;

    /**
     * Orders of the listing; the first one besides relevance is the default.
     *
     * @return list<Sort>
     */
    public function sorts(): array;
}
