<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Facets;

use InvalidArgumentException;

/**
 * Public facet value slug, unique within its facet only.
 */
final readonly class FacetValueId
{
    private const PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public const MAX_LENGTH = 96;

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidArgumentException('Facet value id must not be empty.');
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Facet value id is longer than '.self::MAX_LENGTH." chars: {$normalized}");
        }

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidArgumentException("Facet value id must be a lowercase slug: {$normalized}");
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
