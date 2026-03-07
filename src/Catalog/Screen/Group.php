<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Screen;

/**
 * One group of the panel with its values and links.
 */
final readonly class Group
{
    /**
     * @param  string  $param  the address parameter of the group, snake in the brackets style
     * @param  'any'|'all'  $operator
     * @param  string|null  $switch  the same choice with the other operator; null when there is nothing to switch
     * @param  float|null  $coverage  share of the scope documents that carry the group; null when not counted
     * @param  list<Value>  $values
     */
    public function __construct(
        public string $facet,
        public string $param,
        public string $title,
        public string $widget,
        public bool $multiple,
        public string $operator,
        public int $selected,
        public string $reset,
        public ?string $switch,
        public ?float $coverage,
        public array $values,
    ) {}
}
