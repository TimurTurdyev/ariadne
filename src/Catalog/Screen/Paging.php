<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Screen;

final readonly class Paging
{
    /**
     * @param  string  $template  the current address with {page} to fill
     */
    public function __construct(
        public string $first,
        public ?string $prev,
        public ?string $next,
        public string $template,
    ) {}
}
