<?php

declare(strict_types=1);

namespace TimurTurdyev\SimpleSearch\Query;

/**
 * The intents the engine runs to build one filter screen.
 */
final readonly class QueryPlan
{
    /**
     * @param  list<PlanIntent>  $intents
     */
    private function __construct(
        public Query $query,
        public array $intents,
    ) {}

    /**
     * @param  bool  $filteredBounds  count price bounds over the selection, not the bare scope
     * @param  bool  $paletteOnPrice  a price range alone gets the palette too, so values it zeroes stay dimmed
     */
    public static function forQuery(Query $query, bool $filteredBounds = false, bool $paletteOnPrice = false): self
    {
        // The main intent always runs; the rest is only for the panel.
        $intents = [PlanIntent::main($query)];

        if (! $query->need->wantsPanel()) {
            return new self($query, $intents);
        }

        $intents[] = PlanIntent::priceBounds($query, $filteredBounds);

        if ($query->hasFacets() || ($paletteOnPrice && ! $query->price->isEmpty())) {
            $intents[] = PlanIntent::palette($query);
        }

        foreach ($query->selection->facets() as $facet) {
            $intents[] = PlanIntent::whatIf($query, $facet);
        }

        return new self($query, $intents);
    }

    /**
     * The palette has its own intent when the plan has one, otherwise main serves it.
     */
    public function paletteKey(): string
    {
        foreach ($this->intents as $intent) {
            if ($intent->kind === IntentKind::Palette) {
                return IntentKind::Palette->value;
            }
        }

        return IntentKind::Main->value;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (PlanIntent $intent): string => $intent->key(), $this->intents);
    }
}
