<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Events;

/**
 * Edited documents reached the live generation.
 */
final readonly class CatalogSynced
{
    /**
     * @param  list<int>  $documents
     */
    public function __construct(
        public string $catalog,
        public array $documents,
    ) {}
}
