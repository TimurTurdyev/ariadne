<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Results;

use TimurTurdyev\Ariadne\Query\PlanIntent;

/**
 * Raw engine answer before any display rules; counts are keyed by term id.
 */
final readonly class IndexResponse
{
    /**
     * @param  list<Hit>  $hits
     * @param  array<string, array<int, int>>  $counts  intent key -> (term id -> count)
     */
    public function __construct(
        public array $hits,
        public int $total,
        public array $counts = [],
        public PriceBounds $priceBounds = new PriceBounds,
        /** Distinct owners behind the rows; null when not counted. */
        public ?int $owners = null,
    ) {}

    public static function empty(): self
    {
        return new self([], 0);
    }

    /**
     * @return array<int, int>
     */
    public function countsFor(PlanIntent|string $intent): array
    {
        $key = $intent instanceof PlanIntent ? $intent->key() : $intent;

        return $this->counts[$key] ?? [];
    }
}
