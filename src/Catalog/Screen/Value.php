<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog\Screen;

/**
 * One value of a panel group with its links: href toggles it, only keeps it alone in its group.
 */
final readonly class Value
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $image,
        public int $count,
        public bool $selected,
        public bool $disabled,
        public string $href,
        public string $only,
    ) {}
}
