<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Events;

use TimurTurdyev\Ariadne\Catalog\Database\RebuildReport;

/**
 * A new generation of the catalog is live: a moment to forget caches built on the old one.
 */
final readonly class CatalogRebuilt
{
    public function __construct(
        public string $catalog,
        public RebuildReport $report,
    ) {}
}
