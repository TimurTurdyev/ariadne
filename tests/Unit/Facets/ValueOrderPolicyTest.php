<?php

declare(strict_types=1);

use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueCount;
use TimurTurdyev\Ariadne\Facets\FacetValueId;
use TimurTurdyev\Ariadne\Facets\ValueOrder;
use TimurTurdyev\Ariadne\Facets\ValueOrderPolicy;

/**
 * @param  list<array{0: string, 1: int, 2?: bool}>  $rows  label, count, selected; dictionary order as given
 * @return list<FacetValueCount>
 */
function core_order_values(array $rows): array
{
    $values = [];

    foreach ($rows as $position => $row) {
        $id = $position + 1;

        $values[] = new FacetValueCount(
            new FacetTerm($id, FacetId::fromString('size'), FacetValueId::fromString('v'.$id), $row[0], null, $position),
            $row[1],
            $row[2] ?? false,
        );
    }

    return $values;
}

/**
 * @param  list<FacetValueCount>  $values
 * @return list<string>
 */
function core_order_labels(array $values): array
{
    return array_map(static fn (FacetValueCount $value): string => $value->term->label, $values);
}

it('compares numbers as numbers in the natural order', function (): void {
    $values = core_order_values([['160', 1], ['16', 2], ['18', 3], ['22', 4]]);

    expect(core_order_labels((new ValueOrderPolicy)->apply($values, ValueOrder::Natural)))
        ->toBe(['16', '18', '22', '160']);
});

it('keeps a selected value past the limit', function (): void {
    $values = core_order_values([['First', 10], ['Second', 9], ['Third', 8], ['Fourth', 7, true]]);

    expect(core_order_labels((new ValueOrderPolicy)->apply($values, ValueOrder::CountDesc, limit: 2)))
        ->toBe(['First', 'Second', 'Fourth'], 'without it the filter could not be removed');
});
