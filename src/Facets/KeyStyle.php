<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Facets;

use InvalidArgumentException;

/**
 * How a facet name is spelled in a URL parameter or a table column.
 */
enum KeyStyle: string
{
    case Kebab = 'kebab';

    /** Writes pack-size as pack_size. */
    case Snake = 'snake';

    public function name(FacetId $facet): string
    {
        return match ($this) {
            self::Kebab => $facet->value,
            self::Snake => str_replace('-', '_', $facet->value),
        };
    }

    /**
     * The facet behind an outside name, null when the name is not valid in this style.
     */
    public function facet(string $name): ?FacetId
    {
        // Snake never writes a hyphen, so it does not read one: a facet keeps a single name.
        if ($this === self::Snake && str_contains($name, '-')) {
            return null;
        }

        try {
            return FacetId::fromString($this === self::Snake ? str_replace('_', '-', $name) : $name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
