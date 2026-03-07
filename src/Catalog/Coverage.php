<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Catalog;

/**
 * How many documents of the bare scope carry each group; the total only when a share is asked for.
 */
final readonly class Coverage
{
    /**
     * @param  array<string, int>  $covered  group slug => documents with at least one of its values
     * @param  int|null  $total  documents of the bare scope; null when not counted
     */
    public function __construct(
        public array $covered = [],
        public ?int $total = null,
    ) {}

    public function documents(string $group): int
    {
        return $this->covered[$group] ?? 0;
    }

    public function share(string $group): ?float
    {
        if ($this->total === null) {
            return null;
        }

        return $this->total > 0 ? $this->documents($group) / $this->total : 0.0;
    }
}
