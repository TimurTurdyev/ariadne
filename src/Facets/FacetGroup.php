<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Facets;

/**
 * One facet of the panel with its values.
 */
final readonly class FacetGroup
{
    /**
     * @param  list<FacetValueCount>  $values
     */
    public function __construct(
        public FacetId $facet,
        public string $label,
        public array $values,
        public FacetOperator $operator = FacetOperator::Any,
    ) {}

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function selectedCount(): int
    {
        $selected = 0;

        foreach ($this->values as $value) {
            if ($value->selected) {
                $selected++;
            }
        }

        return $selected;
    }
}
