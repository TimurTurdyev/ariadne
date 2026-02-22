<?php

declare(strict_types=1);

namespace TimurTurdyev\Ariadne\Facets;

use TimurTurdyev\Ariadne\Dictionary\FacetDictionary;
use TimurTurdyev\Ariadne\Query\FacetChoice;
use TimurTurdyev\Ariadne\Query\IntentKind;
use TimurTurdyev\Ariadne\Query\QueryPlan;
use TimurTurdyev\Ariadne\Results\IndexResponse;

/**
 * Builds the filter panel from raw engine counts: a pure function, no I/O.
 */
final class DisjunctivePolicy
{
    /**
     * @param  bool  $keepZeros  a value with a zero count stays dimmed in the list instead of being hidden
     * @param  int  $minValues  a group without a selection needs at least this many values to be shown
     */
    public function __construct(
        private readonly ValueOrderPolicy $order = new ValueOrderPolicy,
        private readonly bool $keepZeros = true,
        private readonly int $minValues = 1,
    ) {}

    public function keepsZeros(): bool
    {
        return $this->keepZeros;
    }

    public function panel(
        QueryPlan $plan,
        IndexResponse $response,
        FacetDictionary $dictionary,
        ValueOrder $order = ValueOrder::Natural,
        ?int $limit = null,
    ): FacetPanel {
        $palette = $this->paletteByFacet($plan, $response, $dictionary);
        $whatIfKeys = $this->whatIfKeys($plan);
        $mainCounts = $response->countsFor(IntentKind::Main->value);
        $selection = $plan->query->selection;

        $groups = [];

        foreach ($dictionary->facets() as $facet) {
            $choice = $selection->choiceFor($facet);
            $terms = $this->withSelected($palette[$facet->value] ?? [], $choice, $dictionary);

            if ($terms === []) {
                continue;
            }

            // An active facet is counted without its own filter, so one pick does not zero its neighbours.
            $counts = $choice === null
                ? $mainCounts
                : $response->countsFor($whatIfKeys[$facet->value] ?? IntentKind::Main->value);

            $values = [];

            foreach ($terms as $term) {
                $value = new FacetValueCount(
                    $term,
                    $counts[$term->id] ?? 0,
                    $choice !== null && $choice->contains($term->value),
                );

                if ($this->keepZeros || ! $value->isDisabled()) {
                    $values[] = $value;
                }
            }

            // A group with a selection always stays, or the filter could not be removed.
            if ($choice === null && count($values) < $this->minValues) {
                continue;
            }

            $groups[] = new FacetGroup(
                $facet,
                $dictionary->labelFor($facet) ?? $facet->value,
                $this->order->apply($values, $order, $limit),
                $choice->operator ?? FacetOperator::Any,
            );
        }

        return new FacetPanel($groups);
    }

    /**
     * @param  list<FacetTerm>  $terms
     * @return list<FacetTerm>
     */
    private function withSelected(array $terms, ?FacetChoice $choice, FacetDictionary $dictionary): array
    {
        if ($choice === null) {
            return $terms;
        }

        // The palette can lose a selected value, and without it the filter could not be removed.
        $present = [];

        foreach ($terms as $term) {
            $present[$term->value->value] = true;
        }

        foreach ($dictionary->termsFor($choice->facet) as $term) {
            if (! isset($present[$term->value->value]) && $choice->contains($term->value)) {
                $terms[] = $term;
            }
        }

        return $terms;
    }

    /**
     * @return array<string, list<FacetTerm>>
     */
    private function paletteByFacet(QueryPlan $plan, IndexResponse $response, FacetDictionary $dictionary): array
    {
        $palette = [];

        foreach (array_keys($response->countsFor($plan->paletteKey())) as $termId) {
            $term = $dictionary->resolve($termId);

            if ($term === null) {
                continue;
            }

            $palette[$term->facet->value][] = $term;
        }

        return $palette;
    }

    /**
     * @return array<string, string> facet slug -> key of its what-if intent
     */
    private function whatIfKeys(QueryPlan $plan): array
    {
        $keys = [];

        foreach ($plan->intents as $intent) {
            if ($intent->kind === IntentKind::WhatIf && $intent->facet instanceof FacetId) {
                $keys[$intent->facet->value] = $intent->key();
            }
        }

        return $keys;
    }
}
