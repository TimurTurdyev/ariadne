<?php

declare(strict_types=1);

use TimurTurdyev\SimpleSearch\Facets\FacetId;
use TimurTurdyev\SimpleSearch\Facets\FacetValueId;
use TimurTurdyev\SimpleSearch\Query\Constraint;
use TimurTurdyev\SimpleSearch\Query\FacetChoice;
use TimurTurdyev\SimpleSearch\Query\Query;
use TimurTurdyev\SimpleSearch\Query\Scope;
use TimurTurdyev\SimpleSearch\Query\Selection;
use TimurTurdyev\SimpleSearch\Tests\Support\InMemoryFacetDictionary;
use TimurTurdyev\SimpleSearch\Url\QueryStringCodec;
use TimurTurdyev\SimpleSearch\Url\UrlFormat;
use TimurTurdyev\SimpleSearch\Url\UrlStyle;

function url_brackets_codec(int $priceUnit = 1): QueryStringCodec
{
    return new QueryStringCodec(new UrlFormat(
        UrlStyle::Brackets,
        $priceUnit,
        ['popular', 'new', 'price_asc', 'price_desc', 'discount', 'relevance'],
    ));
}

function url_brackets_dictionary(): InMemoryFacetDictionary
{
    return (new InMemoryFacetDictionary)
        ->add('pack-size', 'Размер', ['56' => '56', '62' => '62', '104' => '104'])
        ->add('color', 'Цвет', ['white' => 'Белый', 'blue' => 'Голубой'])
        ->add('brand', 'Бренд', ['riva' => 'RIVA']);
}

function url_brackets_decode(string $queryString, int $priceUnit = 1): Query
{
    parse_str($queryString, $params);

    return url_brackets_codec($priceUnit)->decode(
        $params,
        new Scope('section', [Constraint::in('sections', [10])]),
        url_brackets_dictionary(),
    );
}

/**
 * @return array<string, list<string>> facet => values
 */
function url_brackets_selection(Query $query): array
{
    $selection = [];

    foreach ($query->selection->all() as $choice) {
        $selection[$choice->facet->value] = $choice->valueSlugs();
    }

    return $selection;
}

it('reads key[], key[0] and a bare key alike', function (): void {
    expect(url_brackets_selection(url_brackets_decode('color[]=white')))->toBe(['color' => ['white']])
        ->and(url_brackets_selection(url_brackets_decode('color[0]=white')))->toBe(['color' => ['white']])
        ->and(url_brackets_selection(url_brackets_decode('color=white')))->toBe(['color' => ['white']])
        ->and(url_brackets_selection(url_brackets_decode('color[0]=white&color[1]=blue')))->toBe(['color' => ['white', 'blue']])
        ->and(url_brackets_selection(url_brackets_decode('color[]=white&color[]=blue')))->toBe(['color' => ['white', 'blue']]);
});

it('does not fail on sort[] and a nested array', function (): void {
    $codec = url_brackets_codec();
    $address = 'sort[]=new&page[]=2&q[]=x&price_min[]=5&color[x][]=white&color[]=blue&tag[a][]=b';

    parse_str($address, $params);

    $query = url_brackets_decode($address);

    expect($query->sort)->toBe('')
        ->and($query->page->page)->toBe(1)
        ->and($query->text)->toBeNull()
        ->and($query->price->isEmpty())->toBeTrue()
        ->and(url_brackets_selection($query))->toBe(['color' => ['blue']], 'the nested part is skipped, the plain value is read')
        ->and($codec->foreign($params, url_brackets_dictionary()))->toBe([], 'a nested array is not carried either');
});

it('carries the price as a pair of bounds in the units of the address, under snake keys', function (): void {
    $codec = url_brackets_codec();
    $query = url_brackets_decode('pack_size[]=62&price_min=500&price_max=900&sort=price_asc');

    expect($query->price->min)->toBe(500.0)
        ->and($query->price->max)->toBe(900.0)
        ->and($query->sort)->toBe('price_asc')
        ->and(url_brackets_selection($query))->toBe(['pack-size' => ['62']], 'the snake key names the kebab facet')
        ->and($codec->encode($query))->toBe('pack_size%5B%5D=62&price_min=500&price_max=900&sort=price_asc');

    $open = url_brackets_decode('price_max=900&price_min=0');

    expect($open->price->min)->toBeNull('zero is not a bound')
        ->and($open->price->max)->toBe(900.0)
        ->and($codec->encode($open))->toBe('price_max=900');

    // The unit applies to the pair as well: the address carries whole units over base hundredths.
    $hundredths = url_brackets_decode('price_min=500&price_max=900.5', priceUnit: 100);

    expect($hundredths->price->min)->toBe(50000.0)
        ->and($hundredths->price->max)->toBe(90050.0)
        ->and(url_brackets_codec(100)->encode($hundredths))->toBe('price_min=500&price_max=900.5');
});

it('writes key%5B%5D=v with values in alphabetical order', function (): void {
    $query = (new Query(new Scope('section', [Constraint::in('sections', [10])])))
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('pack-size'), [
                FacetValueId::fromString('62'),
                FacetValueId::fromString('104'),
                FacetValueId::fromString('56'),
            ]),
            new FacetChoice(FacetId::fromString('color'), [
                FacetValueId::fromString('white'),
                FacetValueId::fromString('blue'),
            ]),
        ]));

    // Values are compared as strings, so 104 goes before 56.
    expect(url_brackets_codec()->encode($query))
        ->toBe('color%5B%5D=blue&color%5B%5D=white&pack_size%5B%5D=104&pack_size%5B%5D=56&pack_size%5B%5D=62');
});

it('carries sale, all and utm_source as foreign parameters', function (): void {
    $codec = url_brackets_codec();

    parse_str('q=body&color[]=white&sale=1&price_min=500&all=1&sort=new&utm_source=ya&page=2', $params);

    $foreign = $codec->foreign($params, url_brackets_dictionary());

    expect($foreign)->toBe(['sale' => '1', 'all' => '1', 'utm_source' => 'ya'])
        ->and($codec->queryString($foreign))->toBe('sale=1&all=1&utm_source=ya');
});

it('leaves placeholders for the price pair and for the page in a template', function (): void {
    $codec = url_brackets_codec();
    $query = url_brackets_decode('color[]=white&price_min=500&sort=new&page=3');

    expect($codec->template($query, price: true))->toBe('color%5B%5D=white&price_min={min}&price_max={max}&sort=new&page=3')
        ->and($codec->template($query, page: true))->toBe('color%5B%5D=white&price_min=500&sort=new&page={page}')
        ->and($codec->template($query))->toBe($codec->encode($query));
});

it('reserves a slug whose parameter name the codec keeps or PHP reads as a number', function (): void {
    $brackets = url_brackets_codec();
    $comma = new QueryStringCodec(new UrlFormat(UrlStyle::Comma));

    expect($brackets->isReserved('price-min'))->toBeTrue('written as price_min')
        ->and($comma->isReserved('price-min'))->toBeFalse()
        ->and($brackets->isReserved('2024'))->toBeTrue('an integer key is never read')
        ->and($comma->isReserved('2024'))->toBeTrue()
        ->and($brackets->isReserved('pack-size'))->toBeFalse()
        ->and($comma->isReserved('page'))->toBeTrue();
});
