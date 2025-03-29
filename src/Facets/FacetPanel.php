<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Facets;

/**
 * The whole filter panel, ready to render.
 */
final readonly class FacetPanel
{
    /** @var list<FacetGroup> */
    public array $groups;

    /**
     * @param  array<array-key, FacetGroup>  $groups
     */
    public function __construct(array $groups = [])
    {
        $this->groups = array_values($groups);
    }

    public function groupFor(FacetId $facet): ?FacetGroup
    {
        foreach ($this->groups as $group) {
            if ($group->facet->equals($facet)) {
                return $group;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->groups === [];
    }
}
