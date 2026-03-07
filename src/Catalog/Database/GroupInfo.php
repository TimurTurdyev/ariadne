<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Database;

/**
 * One panel group as the reader knows it: from the definition for a declared facet, from the groups table for a dynamic one.
 *
 * @internal
 */
final readonly class GroupInfo
{
    /**
     * @param  int  $slot  place of its facet among the facets of the definition
     * @param  string|null  $producer  the dynamic facet it comes from; null for a declared facet
     * @param  int|null  $position  place among the groups of its dynamic facet; null orders it by coverage
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public string $widget,
        public bool $multiple,
        public int $slot,
        public ?string $producer,
        public ?int $position,
    ) {}

    public function isToggle(): bool
    {
        return $this->widget === 'toggle';
    }
}
