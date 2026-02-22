<?php

declare(strict_types=1);

use TimurTurdyev\Ariadne\Facets\DisjunctivePolicy;
use TimurTurdyev\Ariadne\Facets\FacetGroup;
use TimurTurdyev\Ariadne\Facets\FacetId;
use TimurTurdyev\Ariadne\Facets\FacetOperator;
use TimurTurdyev\Ariadne\Facets\FacetPanel;
use TimurTurdyev\Ariadne\Facets\FacetTerm;
use TimurTurdyev\Ariadne\Facets\FacetValueCount;
use TimurTurdyev\Ariadne\Facets\FacetValueId;
use TimurTurdyev\Ariadne\Present\LinkBase;
use TimurTurdyev\Ariadne\Present\PanelLinks;
use TimurTurdyev\Ariadne\Query\Constraint;
use TimurTurdyev\Ariadne\Query\FacetChoice;
use TimurTurdyev\Ariadne\Query\Need;
use TimurTurdyev\Ariadne\Query\PageRequest;
use TimurTurdyev\Ariadne\Query\PriceRange;
use TimurTurdyev\Ariadne\Query\Query;
use TimurTurdyev\Ariadne\Query\Scope;
use TimurTurdyev\Ariadne\Query\Selection;
use TimurTurdyev\Ariadne\Results\IndexResponse;
use TimurTurdyev\Ariadne\Results\PriceBounds;
use TimurTurdyev\Ariadne\Results\ResultPage;
use TimurTurdyev\Ariadne\Search\SearchCatalog;
use TimurTurdyev\Ariadne\Search\SearchResult;
use TimurTurdyev\Ariadne\Tests\Support\InMemoryFacetDictionary;
use TimurTurdyev\Ariadne\Tests\Support\RecordingIndex;
use TimurTurdyev\Ariadne\Url\QueryStringCodec;
use TimurTurdyev\Ariadne\Url\UrlFormat;
use TimurTurdyev\Ariadne\Url\UrlStyle;

function links_comma_codec(): QueryStringCodec
{
    return new QueryStringCodec(new UrlFormat(UrlStyle::Comma, 100, ['price-asc', 'price-desc', 'name', 'sku']));
}

function links_brackets_codec(): QueryStringCodec
{
    return new QueryStringCodec(new UrlFormat(UrlStyle::Brackets, 1, ['popular', 'new', 'price_asc', 'price_desc']));
}

function links_scope(): Scope
{
    return new Scope('section', [Constraint::in('sections', [58])]);
}

function links_sizes_dictionary(): InMemoryFacetDictionary
{
    return (new InMemoryFacetDictionary)
        ->add('size', 'Размер', ['56' => '56', '62' => '62', '68' => '68'])
        ->add('color', 'Цвет', ['white' => 'Белый', 'blue' => 'Голубой']);
}

/**
 * A section with a brand and a price selected, sorted, on the third page.
 */
function links_desks_screen(): SearchResult
{
    $brand = FacetId::fromString('brand');
    $riva = new FacetTerm(1, $brand, FacetValueId::fromString('riva'), 'RIVA');
    $stil = new FacetTerm(2, $brand, FacetValueId::fromString('stil'), 'Стиль');

    $query = (new Query(links_scope()))
        ->withSelection(new Selection([new FacetChoice($brand, [FacetValueId::fromString('riva')])]))
        ->withPrice(new PriceRange(100000.0, 5000000.0))
        ->withSort('price-asc')
        ->withPage(new PageRequest(3));

    return new SearchResult(
        $query,
        new ResultPage([], 1, 1, 30),
        new FacetPanel([
            new FacetGroup($brand, 'Бренд', [new FacetValueCount($riva, 2, true), new FacetValueCount($stil, 1, false)]),
        ]),
        new PriceBounds(50000.0, 9000000.0),
    );
}

function links_desks(): PanelLinks
{
    return new PanelLinks(links_comma_codec(), InMemoryFacetDictionary::catalog());
}

/**
 * A screen whose panel lists every value of the dictionary, marked by the selection of the query.
 */
function links_screen(InMemoryFacetDictionary $dictionary, Query $query, int $total = 1): SearchResult
{
    $groups = [];

    foreach ($dictionary->facets() as $facet) {
        $choice = $query->selection->choiceFor($facet);

        $groups[] = new FacetGroup(
            $facet,
            (string) $dictionary->labelFor($facet),
            array_map(
                static fn (FacetTerm $term): FacetValueCount => new FacetValueCount(
                    $term,
                    1,
                    $choice !== null && $choice->contains($term->value),
                ),
                $dictionary->termsFor($facet),
            ),
            $choice === null ? FacetOperator::Any : $choice->operator,
        );
    }

    return new SearchResult(
        $query,
        new ResultPage([], $total, $query->page->page, $query->page->perPage),
        new FacetPanel($groups),
        PriceBounds::none(),
    );
}

/**
 * @return array<int|string, string> value => address with the value toggled; a numeric value is an integer key
 */
function links_value_hrefs(PanelLinks $links, SearchResult $screen, LinkBase $base, string $facet): array
{
    foreach ($links->panel($screen, $base)['facets'] as $group) {
        if ($group['facet'] === $facet) {
            return array_column($group['values'], 'href', 'value');
        }
    }

    throw new LogicException("Group {$facet} must be in the panel.");
}

/**
 * @return array<string, string|null> facet => address of the same choice with the other operator
 */
function links_operator_hrefs(PanelLinks $links, SearchResult $screen, LinkBase $base): array
{
    return array_column($links->panel($screen, $base)['facets'], 'operatorHref', 'facet');
}

/**
 * The base of a landing page: /catalog/size-62 already says "size 62".
 */
function links_landing_base(): LinkBase
{
    return (new LinkBase('/catalog'))->landing('/catalog/size-62', new Selection([
        new FacetChoice(FacetId::fromString('size'), [FacetValueId::fromString('62')]),
    ]));
}

it('toggles a value and starts paging over', function (): void {
    [$riva, $stil] = links_desks()->panel(links_desks_screen(), new LinkBase('/catalog/stoly'))['facets'][0]['values'];

    expect($riva['href'])->toBe('/catalog/stoly?price=1000-50000&sort=price-asc', 'the selected value is taken off')
        ->and($stil['href'])->toBe('/catalog/stoly?brand=riva,stil&price=1000-50000&sort=price-asc');
});

it('resets a group, the price and the whole selection', function (): void {
    $panel = links_desks()->panel(links_desks_screen(), new LinkBase('/catalog/stoly'));

    expect($panel['facets'][0]['reset'])->toBe('/catalog/stoly?price=1000-50000&sort=price-asc')
        ->and($panel['price']['reset'])->toBe('/catalog/stoly?brand=riva&sort=price-asc')
        ->and($panel['reset'])->toBe('/catalog/stoly?sort=price-asc', 'the sort stays');
});

it('gives addresses of sorts, of paging, of a single value and the price template', function (): void {
    $panel = links_desks()->panel(
        links_desks_screen(),
        new LinkBase('/catalog/stoly'),
        sorts: ['' => 'По порядку', 'price-asc' => 'Дешевле', 'price-desc' => 'Дороже'],
    );

    $sorts = array_column($panel['sorts'], null, 'value');
    [, $stil] = $panel['facets'][0]['values'];

    expect($sorts['price-desc']['href'])->toBe('/catalog/stoly?brand=riva&price=1000-50000&sort=price-desc')
        ->and($sorts['price-asc']['selected'])->toBeTrue()
        ->and($sorts['']['href'])->toBe('/catalog/stoly?brand=riva&price=1000-50000', 'the default order is not written')
        ->and($panel['paging']['first'])->toBe('/catalog/stoly?brand=riva&price=1000-50000&sort=price-asc')
        ->and($panel['paging']['template'])->toBe('/catalog/stoly?brand=riva&price=1000-50000&sort=price-asc&page={page}')
        ->and($stil['only'])->toBe('/catalog/stoly?brand=stil&price=1000-50000&sort=price-asc')
        ->and($panel['price']['input'])->toBe(['min' => '1000', 'max' => '50000'])
        ->and($panel['price']['template'])->toBe('/catalog/stoly?brand=riva&price={min}-{max}&sort=price-asc');
});

it('builds panel addresses from the query of the buyer, not from the one normalized for the cache', function (): void {
    $dictionary = (new InMemoryFacetDictionary)->add('brend', 'Бренд', ['riva' => 'RIVA', 'stil' => 'Стиль']);
    $index = new RecordingIndex(new IndexResponse([], 3, ['main' => [1 => 2, 2 => 1]], new PriceBounds(1000.0, 50000.0)));
    $codec = links_comma_codec();

    $query = $codec->decode(['sort' => 'price-asc', 'page' => '2'], links_scope(), $dictionary);

    // A panel request drops the sort and the page so that it caches as one entry.
    $result = (new SearchCatalog($index, $dictionary, new DisjunctivePolicy))($query->needing(Need::Panel));
    $panel = (new PanelLinks($codec, $dictionary))->panel($result, new LinkBase('/catalog/stoly'), $query);

    $hrefs = array_column($panel['facets'][0]['values'], 'href', 'value');

    expect($hrefs['riva'])->toBe('/catalog/stoly?brend=riva&sort=price-asc')
        ->and($panel['price']['reset'])->toBe('/catalog/stoly?sort=price-asc')
        ->and($panel['reset'])->toBe('/catalog/stoly?sort=price-asc');
});

it('leaves the page number out of the address of the first page', function (): void {
    $links = links_desks();

    $query = (new Query(links_scope()))
        ->withSelection(new Selection([
            new FacetChoice(FacetId::fromString('brend'), [FacetValueId::fromString('riva')]),
        ]))
        ->withPrice(new PriceRange(1000.0, 50000.0));

    $screen = static fn (int $page): SearchResult => new SearchResult(
        $query->withPage(new PageRequest($page)),
        new ResultPage([], 95, $page, 30),
        new FacetPanel,
        PriceBounds::none(),
    );

    // The address carries whole units: 1000 and 50000 hundredths are 10 and 500.
    expect($links->panel($screen(2), new LinkBase('/catalog/kresla'))['paging'])->toBe([
        'first' => '/catalog/kresla?brend=riva&price=10-500',
        'prev' => '/catalog/kresla?brend=riva&price=10-500',
        'next' => '/catalog/kresla?brend=riva&price=10-500&page=3',
        'template' => '/catalog/kresla?brend=riva&price=10-500&page={page}',
    ])
        ->and($links->panel($screen(1), new LinkBase('/catalog/kresla'))['paging']['prev'])->toBeNull()
        ->and($links->panel($screen(4), new LinkBase('/catalog/kresla'))['paging']['next'])->toBeNull('95 results make four pages');
});

it('switches the operator of a choice on a multi-valued facet', function (): void {
    $codec = links_comma_codec();
    $dictionary = InMemoryFacetDictionary::catalog();
    $links = new PanelLinks($codec, $dictionary);
    $base = new LinkBase('/catalog/kresla');

    $any = links_screen($dictionary, $codec->decode(['uphol-material' => 'kozha,tkan'], links_scope(), $dictionary));
    $all = links_screen($dictionary, $codec->decode(['uphol-material' => 'all:kozha,tkan'], links_scope(), $dictionary));

    expect(links_operator_hrefs($links, $any, $base))->toBe([
        'color' => null,
        'uphol-material' => '/catalog/kresla?uphol-material=all:kozha,tkan',
        'brand' => null,
    ])
        ->and(links_operator_hrefs($links, $all, $base)['uphol-material'])->toBe('/catalog/kresla?uphol-material=kozha,tkan')
        // "All" picked by the buyer is not reset by the next click.
        ->and(links_value_hrefs($links, $all, $base, 'uphol-material')['setka'])->toBe('/catalog/kresla?uphol-material=all:kozha,setka,tkan');
});

it('offers no operator switch on a single-valued facet and in the brackets style', function (): void {
    $comma = links_comma_codec();
    $single = InMemoryFacetDictionary::catalog()->markSingleValued('uphol-material');
    $chairs = links_screen($single, $comma->decode(['uphol-material' => 'kozha,tkan'], links_scope(), $single));

    $brackets = links_brackets_codec();
    $sizes = links_sizes_dictionary();
    $bodies = links_screen($sizes, $brackets->decode(['size' => ['56', '62']], links_scope(), $sizes));

    expect(links_operator_hrefs(new PanelLinks($comma, $single), $chairs, new LinkBase('/catalog/kresla'))['uphol-material'])->toBeNull()
        ->and(links_operator_hrefs(new PanelLinks($brackets, $sizes), $bodies, new LinkBase('/catalog'))['size'])->toBeNull();
});

it('takes off exactly the selected value, however the address spelled the list', function (string $address): void {
    $codec = links_brackets_codec();
    $dictionary = links_sizes_dictionary();
    $links = new PanelLinks($codec, $dictionary);

    parse_str($address, $params);

    $screen = links_screen($dictionary, $codec->decode($params, links_scope(), $dictionary));
    $base = LinkBase::of('/catalog', $params, $codec, $dictionary);

    $sizes = links_value_hrefs($links, $screen, $base, 'size');
    $colors = links_value_hrefs($links, $screen, $base, 'color');

    expect($sizes['56'])->toBe('/catalog?color%5B%5D=white&size%5B%5D=62')
        ->and($sizes['62'])->toBe('/catalog?color%5B%5D=white&size%5B%5D=56')
        ->and($sizes['68'])->toBe('/catalog?color%5B%5D=white&size%5B%5D=56&size%5B%5D=62&size%5B%5D=68')
        ->and($colors['white'])->toBe('/catalog?size%5B%5D=56&size%5B%5D=62', 'the last value takes the group with it');
})->with([
    'key[0], as a paginator rewrites it' => 'size[0]=56&size[1]=62&color[0]=white&page=2',
    'key[], as a form submits it' => 'size[]=56&size[]=62&color[]=white&page=2',
]);

it('carries foreign parameters in every address until the base drops them', function (): void {
    $codec = links_brackets_codec();
    $dictionary = links_sizes_dictionary();
    $links = new PanelLinks($codec, $dictionary);

    parse_str('color[]=white&sale=1&all=1&utm_source=ya', $params);

    $query = $codec->decode($params, links_scope(), $dictionary);
    $base = LinkBase::of('/catalog', $params, $codec, $dictionary);

    expect($links->href($query, $base))->toBe('/catalog?color%5B%5D=white&sale=1&all=1&utm_source=ya')
        ->and($links->href($query, $base->without('sale')))->toBe('/catalog?color%5B%5D=white&all=1&utm_source=ya')
        ->and($links->href(new Query(links_scope()), $base->without('sale', 'all')))->toBe('/catalog?utm_source=ya')
        ->and($links->href(new Query(links_scope()), new LinkBase('/catalog')))->toBe('/catalog');
});

it('keeps paging and sorting on the landing path, without the choice the path carries', function (): void {
    $codec = links_brackets_codec();
    $dictionary = links_sizes_dictionary();

    // A landing page puts its own choice into the parameters before decoding.
    $query = $codec->decode(['size' => ['62'], 'sort' => 'price_asc', 'page' => '2'], links_scope(), $dictionary);

    $panel = (new PanelLinks($codec, $dictionary))->panel(
        links_screen($dictionary, $query, total: 95),
        links_landing_base(),
        sorts: ['popular' => 'Популярные', 'new' => 'Новинки'],
    );

    expect($panel['paging'])->toBe([
        'first' => '/catalog/size-62?sort=price_asc',
        'prev' => '/catalog/size-62?sort=price_asc',
        'next' => '/catalog/size-62?sort=price_asc&page=3',
        'template' => '/catalog/size-62?sort=price_asc&page={page}',
    ])
        ->and(array_column($panel['sorts'], 'href', 'value'))->toBe([
            'popular' => '/catalog/size-62?sort=popular',
            'new' => '/catalog/size-62?sort=new',
        ])
        ->and($panel['price']['template'])->toBe('/catalog/size-62?price_min={min}&price_max={max}&sort=price_asc');
});

it('leads to the main path once the choice of the landing page changes', function (): void {
    $codec = links_brackets_codec();
    $dictionary = links_sizes_dictionary();
    $links = new PanelLinks($codec, $dictionary);

    $screen = links_screen($dictionary, $codec->decode(['size' => ['62'], 'sort' => 'price_asc'], links_scope(), $dictionary));
    $base = links_landing_base();

    $sizes = links_value_hrefs($links, $screen, $base, 'size');
    $colors = links_value_hrefs($links, $screen, $base, 'color');

    expect($sizes['62'])->toBe('/catalog?sort=price_asc', 'taking the pinned value off leaves the landing page')
        ->and($sizes['68'])->toBe('/catalog?size%5B%5D=62&size%5B%5D=68&sort=price_asc', 'a wider choice is written in full')
        ->and($colors['white'])->toBe('/catalog/size-62?color%5B%5D=white&sort=price_asc', 'another facet keeps the page')
        ->and($links->panel($screen, $base)['reset'])->toBe('/catalog?sort=price_asc');
});

it('carries only the named foreign parameters and leaves out one that does not fit', function (): void {
    $codec = links_brackets_codec();
    $dictionary = links_sizes_dictionary();
    $links = new PanelLinks($codec, $dictionary);
    $query = new Query(links_scope());

    parse_str('sale=1&partial=1&utm_content='.str_repeat('a', LinkBase::MAX_FOREIGN_LENGTH).'&utm_source=ya', $params);

    $base = LinkBase::of('/catalog', $params, $codec, $dictionary);

    expect($links->href($query, $base->only('sale', 'utm_source')))->toBe('/catalog?sale=1&utm_source=ya')
        ->and($links->href($query, $base))->toBe('/catalog?sale=1&partial=1&utm_source=ya', 'a short parameter after the long one still fits');
});

it('offers no next page past the deepest page an address can reach', function (): void {
    $query = (new Query(links_scope()))->withPage(new PageRequest(PageRequest::MAX_PAGE));
    $screen = links_screen(links_sizes_dictionary(), $query, total: $query->page->perPage * (PageRequest::MAX_PAGE + 10));

    $paging = (new PanelLinks(links_brackets_codec(), links_sizes_dictionary()))->panel($screen, new LinkBase('/catalog'))['paging'];

    expect($paging['next'])->toBeNull()
        ->and($paging['prev'])->toBe('/catalog?page='.(PageRequest::MAX_PAGE - 1));
});

it('keeps every address on its own host, whatever the path starts with', function (): void {
    $codec = links_brackets_codec();
    $dictionary = links_sizes_dictionary();
    $links = new PanelLinks($codec, $dictionary);
    $query = $codec->decode(['size' => ['62']], links_scope(), $dictionary);
    $pinned = new Selection([new FacetChoice(FacetId::fromString('size'), [FacetValueId::fromString('62')])]);

    $base = LinkBase::of('//evil.example/catalog', [], $codec, $dictionary);

    expect($links->href($query, $base))->toBe('/evil.example/catalog?size%5B%5D=62')
        ->and($links->href($query, $base->landing('/\\evil.example/size-62', $pinned)))->toBe('/evil.example/size-62');
});

it('stays on the main path when the landing page pins nothing', function (): void {
    $codec = links_brackets_codec();
    $dictionary = links_sizes_dictionary();
    $query = $codec->decode(['color' => ['white']], links_scope(), $dictionary);

    $base = (new LinkBase('/catalog'))->landing('/catalog/all', new Selection);

    expect((new PanelLinks($codec, $dictionary))->href($query, $base))->toBe('/catalog?color%5B%5D=white');
});
