<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use TimurTurdyev\Ariadne\Index\CatalogIndex;
use TimurTurdyev\Ariadne\Query\Query;

/**
 * What a driver of the layer gives besides the counts: coverage of groups and the group of a term.
 *
 * @internal
 */
interface FacetIndex extends CatalogIndex
{
    /**
     * @param  bool  $total  count the documents of the bare scope too, for shares
     */
    public function coverage(Query $query, bool $total): Coverage;

    /**
     * The group slug of a term id the counts returned; null for one they did not.
     */
    public function groupOf(int $term): ?string;
}
