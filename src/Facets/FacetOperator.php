<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Facets;

/**
 * How selected values of one facet combine; All only makes sense for multi-valued facets.
 */
enum FacetOperator: string
{
    case Any = 'any';
    case All = 'all';

    public static function fromString(?string $value): self
    {
        return $value === self::All->value ? self::All : self::Any;
    }
}
