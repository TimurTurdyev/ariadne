<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Screen;

/**
 * The price slider: bounds and selection in base units, the fields as typed, a template with {min} and {max}.
 */
final readonly class PriceFilter
{
    /**
     * @param  array{min: ?float, max: ?float}  $bounds
     * @param  array{min: ?float, max: ?float}  $selected
     * @param  array{min: string, max: string}  $input
     */
    public function __construct(
        public array $bounds,
        public array $selected,
        public array $input,
        public string $template,
        public string $reset,
    ) {}
}
