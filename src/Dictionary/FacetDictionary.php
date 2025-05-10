<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Dictionary;

use TimurTurdyev\SimpleSearch\Facets\FacetId;
use TimurTurdyev\SimpleSearch\Facets\FacetTerm;
use TimurTurdyev\SimpleSearch\Facets\FacetValueId;

/**
 * Term dictionary: where public slugs, integer ids in the index and human labels meet.
 */
interface FacetDictionary
{
    /**
     * All facets in display order.
     *
     * @return list<FacetId>
     */
    public function facets(): array;

    /**
     * Values of a facet in display order.
     *
     * @return list<FacetTerm>
     */
    public function termsFor(FacetId $facet): array;

    /**
     * Term by its index id; null for an unknown id, since the index may be ahead of the dictionary.
     */
    public function resolve(int $termId): ?FacetTerm;

    /**
     * Term ids of the selected values; unknown values are dropped, so a stale link does not break the page.
     *
     * @param  list<FacetValueId>  $values
     * @return list<int>
     */
    public function termIdsFor(FacetId $facet, array $values): array;

    /**
     * Human facet name for the group title.
     */
    public function labelFor(FacetId $facet): ?string;

    /**
     * Whether one document can carry two or more values of the facet; an unknown facet counts as multi-valued.
     */
    public function isMultiValued(FacetId $facet): bool;
}
