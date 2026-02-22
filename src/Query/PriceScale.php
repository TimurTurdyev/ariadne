<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

use InvalidArgumentException;

/**
 * How the shown price relates to the indexed one: a multiplier, applied in both directions.
 */
final readonly class PriceScale
{
    private const DISPLAY_STEP = 1.0;

    public function __construct(public float $rate = 1.0)
    {
        if ($rate <= 0) {
            throw new InvalidArgumentException("Price scale rate must be positive, got {$rate}.");
        }
    }

    public static function identity(): self
    {
        return new self(1.0);
    }

    public function isIdentity(): bool
    {
        return abs($this->rate - 1.0) < 0.0000001;
    }

    /**
     * Lower bound in the base scale, half a step down: the shown price was rounded.
     */
    public function toBaseFrom(?float $amount): ?float
    {
        return $amount === null ? null : round(($amount - self::DISPLAY_STEP / 2) / $this->rate, 4);
    }

    /**
     * Upper bound in the base scale, half a step up for the same reason.
     */
    public function toBaseTo(?float $amount): ?float
    {
        return $amount === null ? null : round(($amount + self::DISPLAY_STEP / 2) / $this->rate, 4);
    }

    /**
     * Base price to the shown one, rounded to the display step.
     */
    public function toDisplay(?float $amount): ?float
    {
        return $amount === null ? null : round($amount * $this->rate / self::DISPLAY_STEP) * self::DISPLAY_STEP;
    }

    /**
     * Part of the cache key: a screen computed in one scale cannot be served in another.
     */
    public function signature(): string
    {
        return 'rate:'.var_export($this->rate, true);
    }
}
