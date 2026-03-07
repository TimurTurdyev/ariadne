<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Attributes;

use Attribute;

/**
 * Columns and relations of the model a controller may narrow the catalog by with where().
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Fields
{
    /** @var list<string> */
    public array $names;

    public function __construct(string ...$names)
    {
        $this->names = array_values($names);
    }
}
