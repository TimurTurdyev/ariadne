<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Results;

/**
 * Price bounds of the scope for the slider, counted without the selected range.
 */
final readonly class PriceBounds
{
    public function __construct(
        public ?float $min = null,
        public ?float $max = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->min === null || $this->max === null;
    }
}
