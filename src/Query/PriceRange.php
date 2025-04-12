<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Query;

/**
 * Selected price range: a missing bound is not set, a reversed range is swapped.
 */
final readonly class PriceRange
{
    public ?float $min;

    public ?float $max;

    public function __construct(?float $min = null, ?float $max = null)
    {
        // "1e999" in an address arrives as INF, which no JSON response can carry.
        $min = $min !== null && is_finite($min) && $min > 0 ? $min : null;
        $max = $max !== null && is_finite($max) && $max > 0 ? $max : null;

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $this->min = $min;
        $this->max = $max;
    }

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->min === null && $this->max === null;
    }

    public function equals(self $other): bool
    {
        return $this->min === $other->min && $this->max === $other->max;
    }
}
