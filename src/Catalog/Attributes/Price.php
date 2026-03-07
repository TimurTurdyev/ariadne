<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Attributes;

use Attribute;

/**
 * The price column documents are filtered, bounded and sorted by.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Price
{
    /**
     * @param  string  $column  column of the model, in base units
     * @param  int  $unit  base units in one unit typed in the address: 100 when prices are kept in cents
     */
    public function __construct(
        public string $column = 'price',
        public int $unit = 1,
    ) {}
}
