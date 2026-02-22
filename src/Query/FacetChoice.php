<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Query;

use InvalidArgumentException;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetOperator;
use TimurTurdyev\Ariadne\Facets\FacetValueId;

/**
 * The user's choice within one facet: a set of values and how they combine.
 */
final readonly class FacetChoice
{
    /** @var list<FacetValueId> */
    public array $values;

    /**
     * @param  list<FacetValueId>  $values
     */
    public function __construct(
        public FacetId $facet,
        array $values,
        public FacetOperator $operator = FacetOperator::Any,
    ) {
        if ($values === []) {
            throw new InvalidArgumentException("Facet choice for {$facet} must not be empty.");
        }

        $unique = [];

        foreach ($values as $value) {
            $unique[$value->value] = $value;
        }

        $this->values = array_values($unique);
    }

    public function contains(FacetValueId $value): bool
    {
        foreach ($this->values as $selected) {
            if ($selected->equals($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "All" differs from a plain choice only with two or more values.
     */
    public function isCombined(): bool
    {
        return $this->operator === FacetOperator::All && count($this->values) > 1;
    }

    /**
     * @return list<string>
     */
    public function valueSlugs(): array
    {
        return array_map(static fn (FacetValueId $value): string => $value->value, $this->values);
    }
}
