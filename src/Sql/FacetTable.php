<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Sql;

use TimurTurdyev\SimpleSearch\Facets\KeyStyle;

/**
 * The flat facet table: one row per filter value of a document, in the fixed columns facet, value, label and sort_value.
 */
final readonly class FacetTable
{
    /**
     * @param  string  $document  column with the document id
     * @param  KeyStyle  $keys  how a facet name is spelled in the facet column
     */
    public function __construct(
        public string $table,
        public string $document,
        public KeyStyle $keys = KeyStyle::Kebab,
    ) {}
}
