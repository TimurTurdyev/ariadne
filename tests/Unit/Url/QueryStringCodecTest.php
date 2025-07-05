<?php

declare(strict_types=1);

use TimurTurdyev\SimpleSearch\Facets\FacetId;
use TimurTurdyev\SimpleSearch\Facets\FacetOperator;
use TimurTurdyev\SimpleSearch\Facets\FacetValueId;
use TimurTurdyev\SimpleSearch\Query\Constraint;
use TimurTurdyev\SimpleSearch\Query\FacetChoice;
use TimurTurdyev\SimpleSearch\Query\PageRequest;
use TimurTurdyev\SimpleSearch\Query\PriceRange;
use TimurTurdyev\SimpleSearch\Query\Query;
use TimurTurdyev\SimpleSearch\Query\Scope;
use TimurTurdyev\SimpleSearch\Query\Selection;
use TimurTurdyev\SimpleSearch\Tests\Support\InMemoryFacetDictionary;
use TimurTurdyev\SimpleSearch\Url\QueryStringCodec;
use TimurTurdyev\SimpleSearch\Url\UrlFormat;
use TimurTurdyev\SimpleSearch\Url\UrlStyle;

function url_comma_codec(): QueryStringCodec
{
    return new QueryStringCodec(new UrlFormat(UrlStyle::Comma, 100, ['price-asc', 'price-desc', 'name', 'sku']));
}

function url_comma_scope(): Scope
{
    return new Scope('section', [Constraint::in('sections', [10])]);
}

function url_comma_decode(string $queryString, InMemoryFacetDictionary $dictionary): Query
{
    parse_str($queryString, $params);

    return url_comma_codec()->decode($params, url_comma_scope(), $dictionary);
}

/**
 * @return array<string, array{operator: string, values: list<string>}>
 */
function url_comma_selection_shape(Query $query): array
{
    $shape = [];

    foreach ($query->selection->all() as $choice) {
        // The order of values means nothing: the address writes them alphabetically.
        $values = $choice->valueSlugs();
        sort($values, SORT_STRING);

        $shape[$choice->facet->value] = [
            'operator' => $choice->isCombined() ? 'all' : 'any',
            'values' => $values,
        ];
    }

    return $shape;
}

it('decodes an encoded string into the same query', function (Query $query): void {
    $codec = url_comma_codec();
    $decoded = url_comma_decode($codec->encode($query), InMemoryFacetDictionary::catalog());

    expect($codec->encode($decoded))->toBe($codec->encode($query))
        ->and($decoded->text)->toBe($query->text)
        ->and($decoded->sort)->toBe($query->sort)
        ->and($decoded->page->page)->toBe($query->page->page)
        ->and($query->price->equals($decoded->price))->toBeTrue()
        ->and(url_comma_selection_shape($decoded))->toBe(url_comma_selection_shape($query));
})->with([
    'an empty query' => fn (): Query => new Query(url_comma_scope()),
    'one facet, one value' => fn (): Query => (new Query(url_comma_scope()))
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('color'), [FacetValueId::fromString('venge')]),
        ])),
    'one facet, several values' => fn (): Query => (new Query(url_comma_scope()))
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('color'), [
                FacetValueId::fromString('venge'),
                FacetValueId::fromString('graphite'),
            ]),
        ])),
    'several facets' => fn (): Query => (new Query(url_comma_scope()))
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('color'), [FacetValueId::fromString('venge')]),
            new FacetChoice(FacetId::fromString('brand'), [FacetValueId::fromString('riva')]),
        ])),
    'the all operator' => fn (): Query => (new Query(url_comma_scope()))
        ->withSelection(new Selection([
            new FacetChoice(
                FacetId::fromString('uphol-material'),
                [FacetValueId::fromString('kozha'), FacetValueId::fromString('tkan')],
                FacetOperator::All,
            ),
        ])),
    'both price bounds' => fn (): Query => (new Query(url_comma_scope()))
        ->withPrice(new PriceRange(10000.0, 50000.0)),
    'the lower bound only' => fn (): Query => (new Query(url_comma_scope()))
        ->withPrice(new PriceRange(10000.0, null)),
    'the upper bound only' => fn (): Query => (new Query(url_comma_scope()))
        ->withPrice(new PriceRange(null, 50000.0)),
    'a fractional price' => fn (): Query => (new Query(url_comma_scope()))
        ->withPrice(new PriceRange(149950.0, 800025.0)),
    'a sort' => fn (): Query => (new Query(url_comma_scope()))
        ->withSort('price-desc'),
    'a page' => fn (): Query => (new Query(url_comma_scope()))
        ->withPage(new PageRequest(3)),
    'a search text' => fn (): Query => (new Query(new Scope('search')))
        ->withText('стол руководителя'),
    'everything at once' => fn (): Query => (new Query(url_comma_scope()))
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('color'), [
                FacetValueId::fromString('venge'),
                FacetValueId::fromString('beige'),
            ]),
            new FacetChoice(
                FacetId::fromString('uphol-material'),
                [FacetValueId::fromString('kozha'), FacetValueId::fromString('setka')],
                FacetOperator::All,
            ),
        ]))
        ->withPrice(new PriceRange(1000.0, 90000.0))
        ->withSort('price-asc')
        ->withPage(new PageRequest(2))
        ->withText('кресло'),
]);

it('drops an unknown facet and an unknown value', function (): void {
    $decoded = url_comma_decode('color=venge,neizvestnyi&sozdatel=ivanov', InMemoryFacetDictionary::catalog());

    expect($decoded->selection->has(FacetId::fromString('color')))->toBeTrue()
        ->and($decoded->selection->has(FacetId::fromString('sozdatel')))->toBeFalse()
        ->and($decoded->selection->choiceFor(FacetId::fromString('color'))?->valueSlugs())->toBe(['venge']);
});

it('survives malformed parameters', function (): void {
    $decoded = url_comma_decode(
        'Color=venge&variants%5B7%5D%5B%5D=12&color=graphite&price=abc&page=-3&sort=random',
        InMemoryFacetDictionary::catalog(),
    );

    expect($decoded->selection->choiceFor(FacetId::fromString('color'))?->valueSlugs())->toBe(['graphite'])
        ->and($decoded->price->isEmpty())->toBeTrue()
        ->and($decoded->page->page)->toBe(1)
        ->and($decoded->sort)->toBe('');
});

it('converts an open price bound from the units of the address into base units', function (): void {
    $onlyMin = url_comma_decode('price=10000-', InMemoryFacetDictionary::catalog());
    $onlyMax = url_comma_decode('price=-50000', InMemoryFacetDictionary::catalog());

    // The address carries whole units, the query works in hundredths: the codec converts the bound.
    expect($onlyMin->price->min)->toBe(1000000.0)
        ->and($onlyMin->price->max)->toBeNull()
        ->and($onlyMax->price->min)->toBeNull()
        ->and($onlyMax->price->max)->toBe(5000000.0);
});

it('writes the all operator over a single value as a plain choice', function (): void {
    $decoded = url_comma_decode('uphol-material=all:kozha', InMemoryFacetDictionary::catalog());
    $choice = $decoded->selection->choiceFor(FacetId::fromString('uphol-material'));

    expect($choice?->operator)->toBe(FacetOperator::All)
        ->and($choice?->isCombined())->toBeFalse()
        ->and(url_comma_codec()->encode($decoded))->toBe('uphol-material=kozha');
});

it('reduces the all operator on a single-valued facet to any', function (): void {
    $dictionary = InMemoryFacetDictionary::catalog()->markSingleValued('uphol-material');

    $decoded = url_comma_decode('uphol-material=all:kozha,tkan', $dictionary);
    $choice = $decoded->selection->choiceFor(FacetId::fromString('uphol-material'));

    expect($choice?->operator)->toBe(FacetOperator::Any, 'an intersection on a single-valued facet is empty by construction')
        ->and(url_comma_codec()->encode($decoded))->toBe('uphol-material=kozha,tkan', 'the canonical address loses the all: prefix');
});

it('orders facets, values and service parameters in the canonical address', function (): void {
    $params = [
        'utm_source' => 'ya',
        'sort' => 'price-asc',
        'page' => '2',
        'uphol-material' => 'all:tkan,kozha',
        'q' => 'кресло',
        'color' => 'venge,beige,venge,',
        'price' => '100-500',
    ];

    expect(url_comma_codec()->canonical($params, InMemoryFacetDictionary::catalog()))->toBe([
        'q' => 'кресло',
        'color' => 'beige,venge',
        'uphol-material' => 'all:kozha,tkan',
        'price' => '100-500',
        'sort' => 'price-asc',
        'page' => '2',
        'utm_source' => 'ya',
    ]);
});

it('ignores a bracket parameter: neither a choice nor a foreign parameter, and gone from the canonical address', function (): void {
    $codec = url_comma_codec();
    $dictionary = InMemoryFacetDictionary::catalog();

    parse_str('brand[]=riva&tag[]=new&color=venge&utm_source=ya', $params);

    $decoded = $codec->decode($params, url_comma_scope(), $dictionary);

    // canonical() passes an array on as it came; queryString() is where it leaves the address.
    expect($decoded->selection->has(FacetId::fromString('brand')))->toBeFalse('brackets are not a choice in this style')
        ->and($codec->encode($decoded))->toBe('color=venge')
        ->and($codec->foreign($params, $dictionary))->toBe(['utm_source' => 'ya'], 'an array is not carried, whatever its name')
        ->and($codec->queryString($codec->canonical($params, $dictionary)))->toBe('color=venge&utm_source=ya');
});

it('drops a value that is not a slug and keeps the rest of the choice', function (): void {
    $decoded = url_comma_decode('color=Beige,venge,gra_phite,graphite-,graphite', InMemoryFacetDictionary::catalog());

    expect(url_comma_selection_shape($decoded))->toBe(['color' => ['operator' => 'any', 'values' => ['graphite', 'venge']]]);
});

it('reads a reversed price range swapped', function (): void {
    $decoded = url_comma_decode('price=500-100', InMemoryFacetDictionary::catalog());

    expect($decoded->price->min)->toBe(10000.0)
        ->and($decoded->price->max)->toBe(50000.0)
        ->and(url_comma_codec()->encode($decoded))->toBe('price=100-500');
});

it('reads a price bound only as the address can write it back', function (): void {
    $dictionary = InMemoryFacetDictionary::catalog();
    $ceiling = number_format(QueryStringCodec::MAX_AMOUNT, 0, '.', '');
    $infinite = url_comma_decode('price=1e999-500', $dictionary);
    $fine = url_comma_decode('price=100.555-', $dictionary);

    expect([$infinite->price->min, $infinite->price->max])->toBe([null, 50000.0], 'only the broken bound is lost')
        ->and(url_comma_decode('price=100-'.str_repeat('9', 310), $dictionary)->price->max)->toBeNull()
        ->and(url_comma_decode("price={$ceiling}1-", $dictionary)->price->min)->toBeNull('above the ceiling')
        ->and(url_comma_decode("price={$ceiling}-", $dictionary)->price->min)->toBe(QueryStringCodec::MAX_AMOUNT * 100)
        ->and(url_comma_decode(url_comma_codec()->encode($fine), $dictionary)->price->equals($fine->price))->toBeTrue('the address writes cents');
});

it('builds a canonical choice from the parsed values, so the redirect keeps the selection', function (): void {
    $codec = url_comma_codec();
    $dictionary = InMemoryFacetDictionary::catalog();
    $params = ['color' => 'venge,all:beige,neizvestnyi'];

    $canonical = $codec->canonical($params, $dictionary);

    parse_str($codec->queryString($canonical), $redirected);

    expect($canonical)->toBe(['color' => 'neizvestnyi,venge'], 'a value that cannot be a slug goes, an unknown slug stays')
        ->and(url_comma_selection_shape($codec->decode($redirected, url_comma_scope(), $dictionary)))
        ->toBe(url_comma_selection_shape($codec->decode($params, url_comma_scope(), $dictionary)))
        ->and($codec->canonical(['color' => 'white,all:'], $dictionary))->toBe(['color' => 'white'])
        ->and($codec->canonical(['color' => 'all:,white'], $dictionary))->toBe(['color' => 'all:white']);
});
