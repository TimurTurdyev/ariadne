<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Screen;

/**
 * A filter in effect with the link that takes it off: a chip above the listing.
 */
final readonly class Applied
{
    /**
     * @param  string  $facet  group slug, or "price"
     * @param  string  $label  the value label; for the price "min-max" as typed, either side may be empty
     */
    public function __construct(
        public string $facet,
        public string $title,
        public string $label,
        public string $href,
    ) {}
}
