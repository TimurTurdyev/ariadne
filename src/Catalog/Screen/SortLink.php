<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Screen;

final readonly class SortLink
{
    public function __construct(
        public string $value,
        public string $label,
        public string $href,
        public bool $selected,
    ) {}
}
