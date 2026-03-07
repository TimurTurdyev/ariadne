<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

use LogicException;

/**
 * A developer mistake in a catalog definition or its config, with every problem listed at once.
 */
final class InvalidCatalog extends LogicException
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(public readonly string $catalog, public readonly array $problems)
    {
        parent::__construct("Catalog [{$catalog}] is invalid:\n- ".implode("\n- ", $problems));
    }
}
