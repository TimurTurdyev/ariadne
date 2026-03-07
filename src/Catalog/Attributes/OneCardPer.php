<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Attributes;

use Attribute;

/**
 * Documents sharing the column show as one card; a document where it is NULL is a card of its own.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class OneCardPer
{
    public function __construct(public string $column) {}
}
