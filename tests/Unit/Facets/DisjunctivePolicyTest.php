<?php

declare(strict_types=1);

use TimurTurdyev\SimpleSearch\Facets\DisjunctivePolicy;
use TimurTurdyev\SimpleSearch\Facets\FacetGroup;
use TimurTurdyev\SimpleSearch\Facets\FacetId;
use TimurTurdyev\SimpleSearch\Facets\FacetOperator;
use TimurTurdyev\SimpleSearch\Facets\FacetPanel;
use TimurTurdyev\SimpleSearch\Facets\FacetValueId;
use TimurTurdyev\SimpleSearch\Facets\ValueOrder;
use TimurTurdyev\SimpleSearch\Query\Constraint;
use TimurTurdyev\SimpleSearch\Query\FacetChoice;
use TimurTurdyev\SimpleSearch\Query\Query;
use TimurTurdyev\SimpleSearch\Query\QueryPlan;
use TimurTurdyev\SimpleSearch\Query\Scope;
use TimurTurdyev\SimpleSearch\Query\Selection;
use TimurTurdyev\SimpleSearch\Results\IndexResponse;
use TimurTurdyev\SimpleSearch\Tests\Support\InMemoryFacetDictionary;

function core_policy_dictionary(): InMemoryFacetDictionary
{
    return (new InMemoryFacetDictionary)
        ->add('brend', 'Бренд', ['riva' => 'RIVA', 'stil' => 'Стиль', 'metta' => 'Метта'])
        ->add('tsvet', 'Цвет', ['chernyy' => 'Черный', 'belyy' => 'Белый', 'seryy' => 'Серый'])
        ->add('material-obivki', 'Материал обивки', ['kozha' => 'Кожа', 'tkan' => 'Ткань']);
}

function core_policy_scope(): Scope
{
    return new Scope('section', [Constraint::in('sections', [10])]);
}

/**
 * @return array{0: QueryPlan, 1: IndexResponse}
 */
function core_policy_reference(InMemoryFacetDictionary $dictionary): array
{
    $query = (new Query(core_policy_scope()))->withSelection(new Selection([
        new FacetChoice(FacetId::fromString('brend'), [FacetValueId::fromString('riva')]),
        new FacetChoice(FacetId::fromString('tsvet'), [FacetValueId::fromString('chernyy')]),
        new FacetChoice(FacetId::fromString('material-obivki'), [FacetValueId::fromString('kozha')]),
    ]));

    $response = new IndexResponse([], 12, [
        'main' => [],
        'palette' => core_policy_palette($dictionary),
        'what-if:brend' => [
            $dictionary->termId('brend', 'riva') => 12,
            $dictionary->termId('brend', 'stil') => 9,
        ],
        'what-if:tsvet' => [
            $dictionary->termId('tsvet', 'chernyy') => 12,
            $dictionary->termId('tsvet', 'belyy') => 5,
        ],
        'what-if:material-obivki' => [
            $dictionary->termId('material-obivki', 'kozha') => 12,
            $dictionary->termId('material-obivki', 'tkan') => 7,
        ],
    ]);

    return [QueryPlan::forQuery($query), $response];
}

/**
 * @return array<int, int>
 */
function core_policy_palette(InMemoryFacetDictionary $dictionary): array
{
    $counts = [];

    foreach ($dictionary->facets() as $facet) {
        foreach ($dictionary->termsFor($facet) as $term) {
            $counts[$term->id] = 1;
        }
    }

    return $counts;
}

function core_policy_group(FacetPanel $panel, string $facet): FacetGroup
{
    return $panel->groupFor(FacetId::fromString($facet))
        ?? throw new LogicException("Group {$facet} must be in the panel.");
}

/**
 * @return array<string, array{0: int, 1: bool, 2: bool}> value => [count, selected, disabled]
 */
function core_policy_shape(FacetPanel $panel, string $facet): array
{
    $shape = [];

    foreach (core_policy_group($panel, $facet)->values as $value) {
        $shape[$value->term->value->value] = [$value->count, $value->selected, $value->isDisabled()];
    }

    return $shape;
}

it('reproduces the reference example of dependent facets', function (): void {
    $dictionary = core_policy_dictionary();
    [$plan, $response] = core_policy_reference($dictionary);

    $panel = (new DisjunctivePolicy)->panel($plan, $response, $dictionary, ValueOrder::Dictionary);

    // The neighbour shows 9, not zero: the count is taken without the brand filter.
    expect(core_policy_shape($panel, 'brend'))
        ->toBe(['riva' => [12, true, false], 'stil' => [9, false, false], 'metta' => [0, false, true]]);

    expect(core_policy_shape($panel, 'tsvet'))
        ->toBe(['chernyy' => [12, true, false], 'belyy' => [5, false, false], 'seryy' => [0, false, true]]);

    expect(core_policy_shape($panel, 'material-obivki'))
        ->toBe(['kozha' => [12, true, false], 'tkan' => [7, false, false]]);
});

it('takes counts of an inactive facet from the main intent', function (): void {
    $dictionary = core_policy_dictionary();

    $query = (new Query(core_policy_scope()))->withSelection(new Selection([
        new FacetChoice(FacetId::fromString('brend'), [FacetValueId::fromString('riva')]),
    ]));

    $response = new IndexResponse([], 12, [
        'main' => [
            $dictionary->termId('tsvet', 'chernyy') => 8,
            $dictionary->termId('tsvet', 'belyy') => 4,
        ],
        'palette' => core_policy_palette($dictionary),
        'what-if:brend' => [$dictionary->termId('brend', 'riva') => 12],
    ]);

    $panel = (new DisjunctivePolicy)->panel(QueryPlan::forQuery($query), $response, $dictionary, ValueOrder::Dictionary);
    $color = core_policy_shape($panel, 'tsvet');

    expect($color['chernyy'])->toBe([8, false, false], 'an inactive facet takes counts from the main intent')
        ->and($color['belyy'])->toBe([4, false, false])
        ->and($color['seryy'])->toBe([0, false, true]);
});

it('shows a selected value that is missing from the palette', function (): void {
    $dictionary = core_policy_dictionary();

    $query = (new Query(new Scope('search')))
        ->withText('кресло')
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('tsvet'), [FacetValueId::fromString('chernyy')]),
        ]));

    // A word added to the search can drop the selected value from the palette.
    $response = new IndexResponse([], 0, [
        'main' => [],
        'palette' => [$dictionary->termId('tsvet', 'belyy') => 3],
        'what-if:tsvet' => [$dictionary->termId('tsvet', 'belyy') => 3],
    ]);

    $panel = (new DisjunctivePolicy)->panel(QueryPlan::forQuery($query), $response, $dictionary, ValueOrder::Dictionary);
    $shape = core_policy_shape($panel, 'tsvet');

    expect($shape)->toHaveKey('chernyy')
        ->and($shape['chernyy'])->toBe([0, true, false], 'it is selected and not dimmed')
        ->and($shape['belyy'])->toBe([3, false, false]);
});

it('keeps a facet with an active selection when the palette is empty', function (): void {
    $dictionary = core_policy_dictionary();

    $query = (new Query(core_policy_scope()))->withSelection(new Selection([
        new FacetChoice(FacetId::fromString('tsvet'), [
            FacetValueId::fromString('chernyy'),
            FacetValueId::fromString('seryy'),
        ]),
    ]));

    $response = new IndexResponse([], 0, ['main' => [], 'palette' => [], 'what-if:tsvet' => []]);

    $group = core_policy_group(
        (new DisjunctivePolicy)->panel(QueryPlan::forQuery($query), $response, $dictionary, ValueOrder::Dictionary),
        'tsvet',
    );

    expect($group->selectedCount())->toBe(2);

    foreach ($group->values as $value) {
        expect($value->isDisabled())->toBeFalse('a selected value stays clickable');
    }
});

it('folds a facet without values in the scope', function (): void {
    $dictionary = core_policy_dictionary();
    $plan = QueryPlan::forQuery(new Query(core_policy_scope()));

    // Only colors in the palette: the section has no other facets.
    $response = new IndexResponse([], 3, [
        'main' => [$dictionary->termId('tsvet', 'chernyy') => 3],
    ]);

    $panel = (new DisjunctivePolicy)->panel($plan, $response, $dictionary, ValueOrder::Dictionary);

    expect($panel->groups)->toHaveCount(1)
        ->and($panel->groups[0]->facet->value)->toBe('tsvet')
        ->and($panel->groupFor(FacetId::fromString('brend')))->toBeNull();
});

it('skips an engine term the dictionary does not know', function (): void {
    $dictionary = core_policy_dictionary();
    $plan = QueryPlan::forQuery(new Query(core_policy_scope()));

    $response = new IndexResponse([], 1, [
        'main' => [$dictionary->termId('tsvet', 'chernyy') => 1, 999999 => 5],
    ]);

    $panel = (new DisjunctivePolicy)->panel($plan, $response, $dictionary, ValueOrder::Dictionary);

    expect($panel->groups)->toHaveCount(1)
        ->and($panel->groups[0]->values)->toHaveCount(1);
});

it('carries the operator of the choice into its group', function (): void {
    $dictionary = core_policy_dictionary();
    [$plan, $response] = core_policy_reference($dictionary);

    $panel = (new DisjunctivePolicy)->panel($plan, $response, $dictionary, ValueOrder::Dictionary);

    expect(core_policy_group($panel, 'brend')->operator)->toBe(FacetOperator::Any);

    $combined = (new Query(core_policy_scope()))->withSelection(new Selection([
        new FacetChoice(
            FacetId::fromString('material-obivki'),
            [FacetValueId::fromString('kozha'), FacetValueId::fromString('tkan')],
            FacetOperator::All,
        ),
    ]));

    $combinedPanel = (new DisjunctivePolicy)->panel(
        QueryPlan::forQuery($combined),
        new IndexResponse([], 4, [
            'main' => [],
            'palette' => core_policy_palette($dictionary),
            'what-if:material-obivki' => [],
        ]),
        $dictionary,
        ValueOrder::Dictionary,
    );

    expect(core_policy_group($combinedPanel, 'material-obivki')->operator)->toBe(FacetOperator::All);
});

it('keeps a group without a selection whose counts are all zero when zeros are kept', function (): void {
    $dictionary = core_policy_dictionary();

    $query = (new Query(core_policy_scope()))->withSelection(new Selection([
        new FacetChoice(FacetId::fromString('brend'), [FacetValueId::fromString('riva')]),
    ]));

    // The material occurs in the section, but not among the selected brand.
    $response = new IndexResponse([], 8, [
        'main' => [$dictionary->termId('tsvet', 'chernyy') => 8],
        'palette' => core_policy_palette($dictionary),
        'what-if:brend' => [$dictionary->termId('brend', 'riva') => 8],
    ]);

    $panel = (new DisjunctivePolicy(keepZeros: true, minValues: 1))
        ->panel(QueryPlan::forQuery($query), $response, $dictionary, ValueOrder::Dictionary);

    expect(core_policy_shape($panel, 'material-obivki'))
        ->toBe(['kozha' => [0, false, true], 'tkan' => [0, false, true]]);
});

it('hides zeros and single-value groups but keeps a selected value with a zero count', function (): void {
    $dictionary = core_policy_dictionary();

    $query = (new Query(core_policy_scope()))->withSelection(new Selection([
        new FacetChoice(FacetId::fromString('tsvet'), [FacetValueId::fromString('chernyy')]),
    ]));

    $response = new IndexResponse([], 0, [
        'main' => [
            $dictionary->termId('brend', 'riva') => 7,
            $dictionary->termId('brend', 'stil') => 3,
            $dictionary->termId('material-obivki', 'kozha') => 4,
        ],
        'palette' => core_policy_palette($dictionary),
        'what-if:tsvet' => [],
    ]);

    $panel = (new DisjunctivePolicy(keepZeros: false, minValues: 2))
        ->panel(QueryPlan::forQuery($query), $response, $dictionary, ValueOrder::Dictionary);

    // Alone in its group, the selected color stays: otherwise the filter could not be removed.
    expect(core_policy_shape($panel, 'tsvet'))->toBe(['chernyy' => [0, true, false]])
        ->and(core_policy_shape($panel, 'brend'))->toBe(['riva' => [7, false, false], 'stil' => [3, false, false]])
        ->and($panel->groupFor(FacetId::fromString('material-obivki')))->toBeNull('one value left is not a choice');
});
