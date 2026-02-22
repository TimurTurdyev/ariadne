<?php

declare(strict_types=1);

use TimurTurdyev\SimpleSearch\Facets\FacetId;
use TimurTurdyev\SimpleSearch\Facets\FacetValueId;
use TimurTurdyev\SimpleSearch\Query\Constraint;
use TimurTurdyev\SimpleSearch\Query\FacetChoice;
use TimurTurdyev\SimpleSearch\Query\IntentKind;
use TimurTurdyev\SimpleSearch\Query\PlanIntent;
use TimurTurdyev\SimpleSearch\Query\PriceRange;
use TimurTurdyev\SimpleSearch\Query\Query;
use TimurTurdyev\SimpleSearch\Query\QueryPlan;
use TimurTurdyev\SimpleSearch\Query\Scope;
use TimurTurdyev\SimpleSearch\Query\Selection;

function core_plan_scope(): Scope
{
    return new Scope('section', [Constraint::in('sections', [10])]);
}

function core_plan_intent(QueryPlan $plan, IntentKind $kind, ?FacetId $facet = null): PlanIntent
{
    foreach ($plan->intents as $intent) {
        if ($intent->kind === $kind && ($facet === null || $intent->facet?->equals($facet) === true)) {
            return $intent;
        }
    }

    throw new LogicException("The plan has no {$kind->value} intent.");
}

it('asks only for the main intent and the price bounds without facets, the palette comes from main', function (): void {
    $plan = QueryPlan::forQuery(new Query(core_plan_scope()));

    expect($plan->keys())->toBe(['main', 'price-bounds'])
        ->and($plan->paletteKey())->toBe(IntentKind::Main->value);
});

it('adds a palette and one what-if per active facet', function (): void {
    $query = (new Query(core_plan_scope(), text: 'desk'))->withSelection(new Selection([
        new FacetChoice(FacetId::fromString('color'), [FacetValueId::fromString('venge')]),
        new FacetChoice(FacetId::fromString('brand'), [FacetValueId::fromString('riva')]),
    ]));

    $plan = QueryPlan::forQuery($query);

    expect($plan->keys())->toBe(['main', 'price-bounds', 'palette', 'what-if:brand', 'what-if:color'])
        ->and($plan->paletteKey())->toBe(IntentKind::Palette->value)
        ->and(core_plan_intent($plan, IntentKind::Palette)->query->text)->toBe('desk', 'the palette is counted within the search')
        ->and(core_plan_intent($plan, IntentKind::PriceBounds)->query->text)->toBe('desk');
});

it('removes only its own facet in a what-if', function (): void {
    $color = FacetId::fromString('color');
    $brand = FacetId::fromString('brand');

    $query = (new Query(core_plan_scope()))->withSelection(new Selection([
        new FacetChoice($color, [FacetValueId::fromString('venge')]),
        new FacetChoice($brand, [FacetValueId::fromString('riva')]),
    ]));

    $whatIfColor = core_plan_intent(QueryPlan::forQuery($query), IntentKind::WhatIf, $color);

    expect($whatIfColor->query->selection->has($color))->toBeFalse()
        ->and($whatIfColor->query->selection->has($brand))->toBeTrue();
});

it('counts price bounds over the selection only when asked for filtered bounds', function (): void {
    $color = FacetId::fromString('color');

    $query = (new Query(core_plan_scope()))
        ->withSelection(new Selection([new FacetChoice($color, [FacetValueId::fromString('venge')])]))
        ->withPrice(new PriceRange(1000.0, 5000.0));

    $bare = QueryPlan::forQuery($query);
    $filtered = QueryPlan::forQuery($query, filteredBounds: true);

    $scopeBounds = core_plan_intent($bare, IntentKind::PriceBounds)->query;
    $selectionBounds = core_plan_intent($filtered, IntentKind::PriceBounds)->query;

    expect($scopeBounds->selection->isEmpty())->toBeTrue('by default the bounds cover the bare scope')
        ->and($scopeBounds->price->isEmpty())->toBeTrue()
        ->and($selectionBounds->selection->has($color))->toBeTrue('filtered bounds keep the selection')
        ->and($selectionBounds->price->isEmpty())->toBeTrue('but never the price itself')
        ->and($filtered->keys())->toBe($bare->keys());
});

it('plans a palette for a price range without facets only when asked to', function (): void {
    $query = (new Query(core_plan_scope()))->withPrice(new PriceRange(40000.0));

    $plain = QueryPlan::forQuery($query);
    $flagged = QueryPlan::forQuery($query, paletteOnPrice: true);

    expect($plain->keys())->toBe(['main', 'price-bounds'])
        ->and($plain->paletteKey())->toBe(IntentKind::Main->value)
        ->and($flagged->keys())->toBe(['main', 'price-bounds', 'palette'])
        ->and($flagged->paletteKey())->toBe(IntentKind::Palette->value, 'the panel reads the palette the plan has')
        ->and(core_plan_intent($flagged, IntentKind::Palette)->query->price->isEmpty())->toBeTrue('the palette ignores the price')
        ->and(QueryPlan::forQuery(new Query(core_plan_scope()), paletteOnPrice: true)->keys())->toBe(['main', 'price-bounds']);
});
