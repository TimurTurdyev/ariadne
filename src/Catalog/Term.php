<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

/**
 * A filter value one document carries: a slug for the address, a label for the panel, a place in the group.
 *
 * A term of a dynamic facet also names its panel group with in().
 */
final readonly class Term
{
    private function __construct(
        public string $value,
        public string $label,
        public int $sort = 0,
        public ?string $group = null,
        public ?string $groupTitle = null,
        public ?string $image = null,
    ) {}

    /**
     * @param  int  $sort  place in the group; equal sorts go by label, then by slug
     */
    public static function of(string|int $value, string $label, int $sort = 0): self
    {
        return new self((string) $value, $label, $sort);
    }

    /**
     * The panel group of a term of a dynamic facet; the title counts only when the facet has no describedBy().
     */
    public function in(string $group, ?string $title = null): self
    {
        return new self($this->value, $this->label, $this->sort, $group, $title, $this->image);
    }

    /**
     * A swatch color or a picture of the value, stored once with its label.
     */
    public function image(?string $image): self
    {
        return new self($this->value, $this->label, $this->sort, $this->group, $this->groupTitle, $image);
    }
}
